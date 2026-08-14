<?php

namespace App\Services;

use App\Models\Draw;
use App\Models\EventZoneLock;
use App\Models\FishingEvent;
use App\Models\Tournament;
use App\Models\WeightEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WeightService
{
    public function storeWeights(FishingEvent $event, Collection $zoneNames, array $weightsInput): array
    {
        $event->load([
            'draws.angler',
            'zoneLocks',
        ]);

        $lockedZones = $event->zoneLocks->keyBy('zone_key');
        $errors = [];
        $updatedZones = [];

        foreach ($zoneNames as $zoneName) {
            $zoneKey = $this->normalizeZoneKey($zoneName);
            if ($lockedZones->has($zoneKey)) {
                $errors[] = "Zone '{$zoneName}' is already confirmed and cannot be modified.";
                continue;
            }

            $zoneDraws = $event->draws->filter(function ($draw) use ($zoneKey) {
                return $this->normalizeZoneKey($this->resolveZoneName($draw)) === $zoneKey;
            })->values();

            if ($zoneDraws->isEmpty()) {
                $errors[] = "Zone '{$zoneName}' is not valid for this event.";
                continue;
            }

            $zoneWeights = $weightsInput[$zoneName] ?? [];

            try {
                DB::transaction(function () use ($event, $zoneDraws, $zoneWeights) {
                    foreach ($zoneDraws as $draw) {
                        $anglerId = $draw->angler_id;
                        if ($anglerId === null) {
                            continue;
                        }

                        $valueRaw = $zoneWeights[$anglerId] ?? null;
                        $value = $valueRaw === null || $valueRaw === '' ? null : (float)$valueRaw;

                        if ($value === null) {
                            WeightEntry::where('event_id', $event->id)
                                ->where('angler_id', $anglerId)
                                ->delete();
                            continue;
                        }

                        WeightEntry::updateOrCreate(
                            [
                                'event_id' => $event->id,
                                'angler_id' => $anglerId,
                            ],
                            [
                                'tournament_id' => $event->tournament_id,
                                'draw_id' => $draw->id,
                                'peg_number' => $draw->peg_number,
                                'weight_value' => $value,
                            ]
                        );
                    }
                });
                $updatedZones[] = $zoneName;
            } catch (\Throwable $throwable) {
                report($throwable);
                $errors[] = "Error saving weights for zone '{$zoneName}'.";
            }
        }

        return [
            'updated' => $updatedZones,
            'errors' => $errors,
        ];
    }

    public function lockZone(FishingEvent $event, Collection $zoneList): array
    {
        $event->load([
            'draws.angler.zone',
            'weightEntries',
            'zoneLocks',
        ]);

        $weightMap = $event->weightEntries->keyBy('angler_id');
        $success = [];
        $errors = [];

        foreach ($zoneList as $zoneName) {
            $zoneKey = $this->normalizeZoneKey($zoneName);
            $matchingDraws = $event->draws->filter(function ($draw) use ($zoneKey) {
                return $this->normalizeZoneKey($this->resolveZoneName($draw)) === $zoneKey;
            });

            if ($matchingDraws->isEmpty()) {
                $errors[] = "Zone '{$zoneName}' is not valid for this event.";
                continue;
            }

            $alreadyLocked = $event->zoneLocks->firstWhere('zone_key', $zoneKey);
            if ($alreadyLocked) {
                continue;
            }

            $zoneValid = true;
            foreach ($matchingDraws as $draw) {
                if ($draw->angler_id === null) {
                    $errors[] = "Some pegs in zone '{$zoneName}' do not have an assigned participant.";
                    $zoneValid = false;
                    break;
                }
                $entry = $weightMap->get($draw->angler_id);
                if ($entry === null || $entry->weight_value === null) {
                    $errors[] = "Complete all weights for zone '{$zoneName}' before confirming.";
                    $zoneValid = false;
                    break;
                }
            }

            if (!$zoneValid) {
                continue;
            }

            EventZoneLock::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'zone_key' => $zoneKey,
                ],
                [
                    'zone_name' => $zoneName,
                    'locked_at' => now(),
                ]
            );

            $success[] = $zoneName;
        }

        if (!empty($success)) {
            $event->loadMissing(['draws.angler.zone', 'weightEntries', 'zoneLocks', 'tournament']);
            if ($this->allZonesLocked($event)) {
                $this->scheduleMeteosatJob($event);
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
        ];
    }

    public function unlockZone(FishingEvent $event, string $zoneName): ?string
    {
        $zoneKey = $this->normalizeZoneKey($zoneName);

        $event->load('zoneLocks');
        $lock = $event->zoneLocks->firstWhere('zone_key', $zoneKey);

        if ($lock === null) {
            return "Zone '{$zoneName}' is not confirmed.";
        }

        $lock->delete();
        return null;
    }

    public function prepareZoneDisplayData(FishingEvent $event, Collection $weightMap): Collection
    {
        $locks = $event->zoneLocks->keyBy('zone_key');
        $groups = [];

        foreach ($event->draws as $draw) {
            $name = $this->resolveZoneName($draw);
            $key = $this->normalizeZoneKey($name);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $name,
                    'key' => $key,
                    'draws' => [],
                ];
            }
            $groups[$key]['draws'][] = $draw;
        }

        $zones = collect($groups)->map(function ($data) use ($locks, $weightMap) {
            $draws = collect($data['draws'])
                ->sortBy(fn ($draw) => $this->pegSortKey((string) $draw->peg_number))
                ->values();
            $firstDraw = $draws->first();
            $minPeg = $firstDraw?->peg_number !== null ? (string) $firstDraw->peg_number : null;
            $complete = $draws->every(function ($draw) use ($weightMap) {
                $anglerId = $draw->angler_id;
                if ($anglerId === null) {
                    return false;
                }
                $entry = $weightMap->get($anglerId);
                return $entry !== null && $entry->weight_value !== null;
            });
            $lock = $locks->get($data['key']);

            return [
                'name' => $data['name'],
                'key' => $data['key'],
                'draws' => $draws,
                'min_peg' => $minPeg,
                'min_sort_key' => $this->pegSortKey($minPeg),
                'complete' => $complete,
                'locked' => $lock !== null,
                'locked_at' => optional($lock)->locked_at,
            ];
        })->sortBy([
            fn ($zone) => $zone['min_sort_key'],
            fn ($zone) => $zone['name'],
        ])->values();

        return $zones->map(function ($zone, $index) {
            $zone['lock_form_id'] = 'lock-zone-' . Str::slug($zone['name']) . '-' . $index;
            $zone['unlock_form_id'] = 'unlock-zone-' . Str::slug($zone['name']) . '-' . $index;
            return $zone;
        });
    }

    public function allZonesLocked(FishingEvent $event): bool
    {
        $zoneKeys = $event->draws
            ->map(fn ($draw) => $this->normalizeZoneKey($this->resolveZoneName($draw)))
            ->filter()
            ->unique()
            ->values();

        if ($zoneKeys->isEmpty()) {
            return false;
        }

        $lockedKeys = $event->zoneLocks
            ->map(fn ($lock) => $this->normalizeZoneKey($lock->zone_key))
            ->filter()
            ->unique()
            ->values();

        return $zoneKeys->diff($lockedKeys)->isEmpty();
    }

    public function scheduleMeteosatJob(FishingEvent $event): void
    {
        if (!$event->tournament?->isPool()) {
            return;
        }

        if ($event->meteosatReport()->exists()) {
            return;
        }

        if (method_exists($event, 'meteosatJob') && $event->meteosatJob()->exists()) {
            return;
        }

        $eventDate = $event->event_date;
        $runAt = $eventDate
            ? Carbon::parse($eventDate)->setTime(15, 0, 0)
            : now()->setTime(15, 0, 0);

        if ($runAt->isPast()) {
            $runAt = now()->addMinutes(1);
        }

        $event->meteosatJob()->create([
            'run_at' => $runAt,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function pegSortKey(?string $peg): string
    {
        if ($peg === null || $peg === '') {
            return '9|';
        }

        if (is_numeric($peg)) {
            $numeric = (int) $peg;
            return '0|' . str_pad((string) $numeric, 10, '0', STR_PAD_LEFT);
        }

        return '1|' . strtoupper($peg);
    }

    public function resolveZoneName(Draw $draw): string
    {
        return $draw->frozen_zone_name
            ?? optional($draw->angler->zone)->name
            ?? 'Zona';
    }

    public function normalizeZoneKey(string $zoneName): string
    {
        return strtoupper(trim($zoneName));
    }
}
