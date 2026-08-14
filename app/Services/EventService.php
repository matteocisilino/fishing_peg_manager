<?php

namespace App\Services;

use App\Models\Angler;
use App\Models\Draw;
use App\Models\FishingEvent;
use App\Models\Tournament;
use App\Models\Zone;
use App\Support\PegHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventService
{
    private array $edgePegCache = [];

    public function __construct(
        private readonly ParticipantDirectoryService $directoryService
    ) {}

    public function generateDraw(FishingEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $event->load('tournament');

            if ($event->tournament->isIndividual()) {
                $this->buildIndividualDraw($event);
            } elseif ($event->tournament->isPool()) {
                $this->buildPoolDraw($event);
            } else {
                $this->buildTeamDraw($event);
            }
        });
    }

    public function consolidate(FishingEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $event->update(['is_locked' => true]);
            $this->syncParticipantDirectory($event);
            $this->scheduleMeteosatJob($event);
        });
    }

    public function updateDraws(FishingEvent $event, array $drawData): void
    {
        DB::transaction(function () use ($event, $drawData) {
            foreach ($drawData as $id => $pegNumber) {
                $draw = Draw::whereKey((int)$id)
                    ->where('event_id', $event->id)
                    ->first();
                if ($draw !== null) {
                    $draw->update(['peg_number' => $pegNumber]);
                }
            }
        });
    }

    public function unlock(FishingEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $event->update(['is_locked' => false]);
            if ($event->meteosatJob) {
                $event->meteosatJob->delete();
            }
        });
    }

    public function formatDrawRow(Draw $draw, int $sectorSize, ?Tournament $tournament = null): array
    {
        $zoneName = $draw->frozen_zone_name ?? optional($draw->angler->zone)->name;
        $zoneStart = optional($draw->frozenZone)->start_number ?? optional($draw->angler->zone)->start_number;
        $zoneEnd = optional($draw->frozenZone)->end_number ?? optional($draw->angler->zone)->end_number;
        $pegValue = PegHelper::normalizeCode((string) $draw->peg_number);

        $sectorLabel = null;
        $sectorStart = null;
        $sectorEnd = null;
        if ($sectorSize > 0 && $zoneStart !== null && $zoneEnd !== null) {
            $effectiveTournament = $tournament ?? $draw->event?->tournament;
            $allowedPegs = $this->getAllowedPegCodesForTournament($effectiveTournament);
            $pegLookup = $this->getPegLookupForTournament($effectiveTournament);
            $zoneRange = PegHelper::sliceRange((string) $zoneStart, (string) $zoneEnd, $allowedPegs, $pegLookup);

            if (empty($zoneRange) && is_numeric($zoneStart) && is_numeric($zoneEnd)) {
                $zoneRange = range((int) $zoneStart, (int) $zoneEnd);
                $zoneRange = array_map(fn ($value) => PegHelper::normalizeCode((string) $value), $zoneRange);
            }

            if (!empty($zoneRange)) {
                $normalizedRange = array_map(fn ($code) => PegHelper::normalizeCode((string) $code), $zoneRange);
                $position = array_search($pegValue, $normalizedRange, true);
                if ($position !== false) {
                    $sectorIndex = intdiv((int) $position, $sectorSize);
                    $sectorStartIndex = $sectorIndex * $sectorSize;
                    $sectorEndIndex = min($sectorStartIndex + $sectorSize - 1, count($zoneRange) - 1);
                    $sectorStart = $zoneRange[$sectorStartIndex] ?? null;
                    $sectorEnd = $zoneRange[$sectorEndIndex] ?? null;
                    $sectorLabel = sprintf('%s%d', $zoneName, $sectorIndex + 1);
                }
            }
        }

        return [
            'id' => $draw->id,
            'zone_name' => $zoneName,
            'sector_label' => $sectorLabel,
            'sector_start' => $sectorStart,
            'sector_end' => $sectorEnd,
            'peg_number' => $pegValue,
            'angler_name' => $draw->frozen_angler_name ?? optional($draw->angler)->name,
            'team_name' => $draw->frozen_team_name ?? optional(optional($draw->angler)->team)->name,
        ];
    }

    public function buildPegOverview(FishingEvent $event): array
    {
        $tournament = $event->tournament;
        if (!$tournament) {
            return [];
        }

        if ($tournament->isPool()) {
            $poolPegs = $tournament->poolPegs()
                ->orderBy('zone_name')
                ->orderBy('peg_number')
                ->get();

            if ($poolPegs->isEmpty()) {
                return [];
            }

            return $poolPegs
                ->groupBy(fn ($peg) => $peg->zone_name ?: 'Pool')
                ->map(fn ($group, $name) => [
                    'label' => $name,
                    'pegs' => $group->pluck('peg_number')
                        ->map(fn ($number) => strtoupper((string) $number))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
        }

        $zones = $tournament->zones()->orderBy('start_number')->get();
        if ($zones->isEmpty()) {
            return [];
        }

        $allowedPegCodes = $this->getAllowedPegCodesForTournament($tournament);
        $pegLookup = $this->getPegLookupForTournament($tournament);

        return $zones
            ->map(function (Zone $zone) use ($allowedPegCodes, $pegLookup) {
                $pegs = $this->pegCodesForZone($zone, $allowedPegCodes, $pegLookup);
                if (empty($pegs)) {
                    return null;
                }
                return [
                    'label' => $zone->name,
                    'pegs' => $pegs,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getAllowedPegCodesForTournament(?Tournament $tournament): array
    {
        return PegHelper::allowedCodesForTournament($tournament);
    }

    public function getPegLookupForTournament(?Tournament $tournament): array
    {
        return PegHelper::buildLookup($this->getAllowedPegCodesForTournament($tournament));
    }

    public function pegCodesForZone(Zone $zone, array $allowedPegs, array $lookup): array
    {
        return PegHelper::sliceRange(
            (string) $zone->start_number,
            (string) $zone->end_number,
            $allowedPegs,
            $lookup
        );
    }

    private function buildTeamDraw(FishingEvent $event): void
    {
        $zones = $event->tournament->zones()->get()->keyBy('id');
        $allowedPegs = $this->getAllowedPegCodesForTournament($event->tournament);
        $pegLookup = $this->getPegLookupForTournament($event->tournament);

        $anglers = Angler::with(['team', 'zone'])
            ->whereHas('team', fn ($query) => $query->where('tournament_id', $event->tournament_id))
            ->where('is_absent', false)
            ->orderBy('team_id')
            ->orderBy('id')
            ->get();

        if ($zones->isEmpty() || $anglers->isEmpty()) {
            throw ValidationException::withMessages(['general' => 'Error: missing zones or participants.']);
        }

        $zonePegMap = [];
        foreach ($zones as $zone) {
            $zonePegMap[$zone->id] = $this->pegCodesForZone($zone, $allowedPegs, $pegLookup);
        }

        $grouped = $anglers->groupBy('zone_id');
        foreach ($grouped as $zoneId => $list) {
            $zone = $zones->get($zoneId);
            if (!$zone) {
                throw ValidationException::withMessages(['general' => 'Participant assigned to a nonexistent zone.']);
            }
            $pegCodes = $zonePegMap[$zoneId] ?? [];
            $available = count($pegCodes);
            if ($available === 0) {
                throw ValidationException::withMessages(['general' => "Zone {$zone->name} does not contain valid pegs for the selected lakes."]);
            }
            if ($list->count() > $available) {
                throw ValidationException::withMessages(['general' => "Error: in zone {$zone->name} there are more participants than available pegs."]);
            }
        }

        Draw::where('event_id', $event->id)->delete();

        foreach ($grouped as $zoneId => $list) {
            $zone = $zones->get($zoneId);
            $pegNumbers = $zonePegMap[$zoneId] ?? [];
            $maxTries = 1000;
            $assigned = false;

            while ($maxTries-- > 0) {
                shuffle($pegNumbers);
                $used = [];
                $tmp = [];

                foreach ($list as $angler) {
                    $forbidden = $this->getForbiddenPegs(
                        $angler->id,
                        $event,
                        $pegNumbers[0],
                        $pegNumbers[count($pegNumbers) - 1]
                    );
                    $pegAssigned = null;

                    foreach ($pegNumbers as $peg) {
                        if (in_array($peg, $used, true) || in_array($peg, $forbidden, true)) {
                            continue;
                        }
                        $pegAssigned = $peg;
                        break;
                    }

                    if ($pegAssigned === null) {
                        $tmp = [];
                        break;
                    }

                    $tmp[] = [
                        'angler' => $angler,
                        'peg' => $pegAssigned,
                    ];
                    $used[] = $pegAssigned;
                }

                if (!empty($tmp)) {
                    foreach ($tmp as $row) {
                        $angler = $row['angler'];
                        Draw::create([
                            'event_id' => $event->id,
                            'angler_id' => $angler->id,
                            'peg_number' => $row['peg'],
                            'frozen_angler_name' => $angler->name,
                            'frozen_team_name' => $angler->team->name,
                            'frozen_zone_name' => $angler->zone->name,
                            'frozen_zone_id' => $angler->zone->id,
                        ]);
                    }
                    $assigned = true;
                    break;
                }
            }

            if (!$assigned) {
                throw ValidationException::withMessages(['general' => "Unable to find a valid combination for zone {$zone->name}."]);
            }
        }
    }

    private function buildIndividualDraw(FishingEvent $event): void
    {
        $zones = $event->tournament->zones()->orderBy('start_number')->get();
        $allowedPegCodes = $this->getAllowedPegCodesForTournament($event->tournament);
        $pegLookup = $this->getPegLookupForTournament($event->tournament);
        $anglers = Angler::with('team')
            ->whereHas('team', fn ($query) => $query->where('tournament_id', $event->tournament_id))
            ->where('is_absent', false)
            ->orderBy('id')
            ->get();

        if ($zones->isEmpty() || $anglers->isEmpty()) {
            throw ValidationException::withMessages(['general' => 'Error: missing zones or participants.']);
        }

        $pegPool = [];
        $zonePegMap = [];
        foreach ($zones as $zone) {
            $pegCodes = $this->pegCodesForZone($zone, $allowedPegCodes, $pegLookup);
            if (empty($pegCodes)) {
                throw ValidationException::withMessages(['general' => "Zone {$zone->name} does not contain valid pegs for the selected lakes."]);
            }
            $zonePegMap[$zone->id] = $pegCodes;
            foreach ($pegCodes as $peg) {
                $pegPool[] = [
                    'number' => $peg,
                    'zone' => $zone,
                ];
            }
        }

        $participantsCount = $anglers->count();
        if ($participantsCount > count($pegPool)) {
            throw ValidationException::withMessages(['general' => 'Number of participants exceeds available pegs.']);
        }

        if ($participantsCount < count($pegPool)) {
            usort($pegPool, function ($a, $b) {
                return PegHelper::compareCodes(
                    (string) $a['number'],
                    (string) $b['number'],
                    $this->getPegLookupForTournament($a['zone']->tournament)
                );
            });
            $pegPool = array_slice($pegPool, 0, $participantsCount);
        }

        Draw::where('event_id', $event->id)->delete();

        $maxTries = 2000;
        $assigned = false;

        while ($maxTries-- > 0) {
            $shuffledPegs = $pegPool;
            shuffle($shuffledPegs);
            $used = [];
            $tmp = [];

            foreach ($anglers as $angler) {
                $pegAssigned = null;

                foreach ($shuffledPegs as $index => $pegInfo) {
                    if (in_array($pegInfo['number'], $used, true)) {
                        continue;
                    }
                    $zone = $pegInfo['zone'];
                    $zoneRange = $zonePegMap[$zone->id] ?? [];
                    $zoneStart = !empty($zoneRange) ? $zoneRange[0] : $zone->start_number;
                    $zoneEnd = !empty($zoneRange) ? $zoneRange[count($zoneRange) - 1] : $zone->end_number;
                    $forbidden = $this->getForbiddenPegs(
                        $angler->id,
                        $event,
                        $zoneStart,
                        $zoneEnd
                    );
                    if (in_array($pegInfo['number'], $forbidden, true)) {
                        continue;
                    }
                    $pegAssigned = $pegInfo;
                    unset($shuffledPegs[$index]);
                    $shuffledPegs = array_values($shuffledPegs);
                    break;
                }

                if ($pegAssigned === null) {
                    $tmp = [];
                    break;
                }

                $tmp[] = [
                    'angler' => $angler,
                    'peg' => $pegAssigned,
                ];
                $used[] = $pegAssigned['number'];
            }

            if (!empty($tmp)) {
                foreach ($tmp as $row) {
                    $angler = $row['angler'];
                    $zone = $row['peg']['zone'];
                    Draw::create([
                        'event_id' => $event->id,
                        'angler_id' => $angler->id,
                        'peg_number' => $row['peg']['number'],
                        'frozen_angler_name' => $angler->name,
                        'frozen_team_name' => optional($angler->team)->name,
                        'frozen_zone_name' => $zone->name,
                        'frozen_zone_id' => $zone->id,
                    ]);
                }
                $assigned = true;
                break;
            }
        }

        if (!$assigned) {
            throw ValidationException::withMessages(['general' => 'Unable to find a valid combination for the participants.']);
        }
    }

    private function buildPoolDraw(FishingEvent $event): void
    {
        $poolPegs = $event->tournament->poolPegs()
            ->orderBy('zone_name')
            ->orderBy('peg_number')
            ->get();
        $pegNumbers = $poolPegs->pluck('peg_number')->all();
        $pegZones = $poolPegs->pluck('zone_name', 'peg_number')->all();
        $anglers = Angler::with('team')
            ->whereHas('team', fn ($query) => $query->where('tournament_id', $event->tournament_id))
            ->where('is_absent', false)
            ->orderBy('id')
            ->get();

        if (empty($pegNumbers) || $anglers->isEmpty()) {
            throw ValidationException::withMessages(['general' => 'Error: missing pegs or participants for the pool.']);
        }

        if ($anglers->count() > count($pegNumbers)) {
            throw ValidationException::withMessages(['general' => 'More participants than available pegs in the pool.']);
        }

        Draw::where('event_id', $event->id)->delete();

        $minPeg = min($pegNumbers);
        $maxPeg = max($pegNumbers);

        $maxTries = 2000;
        $assigned = false;

        while ($maxTries-- > 0) {
            $shuffled = $pegNumbers;
            shuffle($shuffled);
            $used = [];
            $tmp = [];

            foreach ($anglers as $angler) {
                $pegAssigned = null;
                foreach ($shuffled as $index => $peg) {
                    if (in_array($peg, $used, true)) {
                        continue;
                    }
                    $forbidden = $this->getForbiddenPegs($angler->id, $event, $minPeg, $maxPeg);
                    if (in_array($peg, $forbidden, true)) {
                        continue;
                    }
                    $pegAssigned = $peg;
                    unset($shuffled[$index]);
                    $shuffled = array_values($shuffled);
                    break;
                }

                if ($pegAssigned === null) {
                    $tmp = [];
                    break;
                }

                $tmp[] = [
                    'angler' => $angler,
                    'peg' => $pegAssigned,
                ];
                $used[] = $pegAssigned;
            }

            if (!empty($tmp)) {
                foreach ($tmp as $row) {
                    $angler = $row['angler'];
                    Draw::create([
                        'event_id' => $event->id,
                        'angler_id' => $angler->id,
                        'peg_number' => $row['peg'],
                        'frozen_angler_name' => $angler->name,
                        'frozen_team_name' => optional($angler->team)->name,
                        'frozen_zone_name' => $pegZones[$row['peg']] ?? 'Pool',
                        'frozen_zone_id' => null,
                    ]);
                }
                $assigned = true;
                break;
            }
        }

        if (!$assigned) {
            throw ValidationException::withMessages(['general' => 'Unable to find a valid combination for the pool participants.']);
        }
    }

    private function getForbiddenPegs(int $anglerId, FishingEvent $currentEvent, int|string $firstPeg, int|string $lastPeg): array
    {
        if (
            $currentEvent->tournament?->isPool() ||
            $currentEvent->tournament?->allowsEdgeRepeats()
        ) {
            return [];
        }

        $tournamentId = (int) $currentEvent->tournament_id;

        if (!isset($this->edgePegCache[$tournamentId])) {
            $zoneEdges = $currentEvent->tournament?->zones()
                ->get(['start_number', 'end_number'])
                ->flatMap(fn ($zone) => [$zone->start_number, $zone->end_number])
                ->map(fn ($value) => PegHelper::normalizeCode((string) $value))
                ->all() ?? [];
            $this->edgePegCache[$tournamentId] = $zoneEdges;
        }

        $edgeValues = collect([$firstPeg, $lastPeg])
            ->merge($this->edgePegCache[$tournamentId])
            ->map(function ($value) {
                if ($value === null) {
                    return null;
                }
                $normalized = PegHelper::normalizeCode((string) $value);
                if ($normalized === '') {
                    return null;
                }
                return $normalized;
            })
            ->filter()
            ->unique()
            ->values();

        if ($edgeValues->isEmpty()) {
            return [];
        }

        return Draw::query()
            ->where('angler_id', $anglerId)
            ->where('event_id', '!=', $currentEvent->id)
            ->whereIn('peg_number', $edgeValues->all())
            ->whereHas('event', fn ($query) => $query->where('tournament_id', $currentEvent->tournament_id))
            ->pluck('peg_number')
            ->map(fn ($peg) => (string) $peg)
            ->all();
    }

    private function syncParticipantDirectory(FishingEvent $event): void
    {
        $event->loadMissing(['structure', 'tournament.structure']);
        $structure = $event->structure ?? $event->tournament?->structure;

        $names = $event->draws()
            ->pluck('frozen_angler_name')
            ->filter(fn ($name) => trim((string)$name) !== '')
            ->unique()
            ->all();

        foreach ($names as $name) {
            $this->directoryService->registerName($name, $structure);
        }
    }

    private function scheduleMeteosatJob(FishingEvent $event): void
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
            ? $eventDate->copy()->setTime(15, 0, 0)
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
}
