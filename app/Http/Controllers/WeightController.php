<?php

/**
 * Fishing Peg Manager
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Http\Controllers;

use App\Models\EventZoneLock;
use App\Models\FishingEvent;
use App\Models\MeteosatJob;
use App\Models\Structure;
use App\Models\Tournament;
use App\Models\WeightEntry;
use App\Models\User;
use App\Services\Tournaments\TournamentStandingsCalculator;
use App\Services\Weights\EventResultsPdfDataBuilder;
use App\Services\Weights\PlacementCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WeightController extends Controller
{
    public function __construct(
        private readonly PlacementCalculator $placementCalculator,
        private readonly TournamentStandingsCalculator $tournamentStandingsCalculator,
        private readonly EventResultsPdfDataBuilder $eventResultsPdfDataBuilder,
    ) {
    }

    public function index(Request $request): View
    {
        $user = auth()->user();
        $today = now()->toDateString();
        $eventId = $request->input('event');

        if ($eventId) {
            $event = FishingEvent::with([
                'tournament.structure',
                'draws.angler.team',
                'draws.angler.zone',
                'weightEntries',
                'structure',
                'meteosatReport',
            ])->findOrFail($eventId);
            $this->authorizeEventAccess($event);

            $tournament = $event->tournament;
            if (!$tournament) {
                return view('weights.index', ['tournaments' => collect()]);
            }

            $tournament->setRelation('events', collect([$event]));

            return view('weights.index', ['tournaments' => collect([$tournament])]);
        }

        $query = Tournament::query()
            ->with([
                'structure',
                'events' => function ($events) use ($user, $today) {
                    $events->with([
                        'draws.angler.team',
                        'draws.angler.zone',
                        'weightEntries',
                        'structure',
                        'meteosatReport',
                    ])->orderBy('event_date');

                    if ($user?->role === User::ROLE_RILEVATORE) {
                        $events->whereDate('event_date', $today)
                            ->where('is_locked', false);
                    }
                },
            ])
            ->orderBy('name');

        if (!$user?->isSuperAdmin()) {
            $structure = $this->userStructure($user);
            if (!$structure) {
                return view('weights.index', ['tournaments' => collect()]);
            }
            $query->where('structure_id', $structure->id);
        }

        $tournaments = $query->get();

        if ($user?->role === User::ROLE_RILEVATORE) {
            $tournaments = $tournaments
                ->filter(fn (Tournament $tournament) => $tournament->events->isNotEmpty())
                ->values();
        }

        return view('weights.index', compact('tournaments'));
    }

    public function show(FishingEvent $event): View
    {
        $event->load([
            'tournament',
            'draws.angler.team',
            'draws.angler.zone',
            'weightEntries',
            'zoneLocks',
        ]);
        $this->authorizeEventAccess($event);

        $weightMap = $event->weightEntries->keyBy('angler_id');
        $placementData = $this->placementCalculator->calculate($event);
        $zonesData = $this->prepareZoneDisplayData($event, $weightMap);

        return view('weights.show', [
            'event' => $event,
            'weightMap' => $weightMap,
            'weightsComplete' => $placementData['complete'],
            'placementsMap' => $placementData['placements'],
            'absoluteMax' => $placementData['absolute_max'],
            'zonesData' => $zonesData,
        ]);
    }

    public function results(FishingEvent $event): RedirectResponse|View
    {
        $event->load([
            'tournament',
            'draws.angler.team',
            'draws.angler.zone',
            'weightEntries',
            'structure',
            'meteosatReport',
        ]);
        $this->authorizeEventAccess($event);

        $placementData = $this->placementCalculator->calculate($event);
        if (!$placementData['complete']) {
            return redirect()
                ->route('weights.show', $event)
                ->withErrors(['general' => 'Complete all weights before checking the placements.']);
        }

        return view('weights.results', [
            'event' => $event,
            'zones' => $placementData['zones'],
            'absoluteMax' => $placementData['absolute_max'],
        ]);
    }

    public function resultsPdf(FishingEvent $event): RedirectResponse|Response
    {
        $this->authorizeEventAccess($event);
        $pdfData = $this->eventResultsPdfDataBuilder->build($event);
        if (!$pdfData['placement']['complete']) {
            return redirect()
                ->route('weights.show', $event)
                ->withErrors(['general' => 'Complete all weights before exporting the placements.']);
        }

        $pdf = Pdf::loadView('weights.results_pdf', [
            'event' => $event,
            'zones' => $pdfData['zones'],
            'absoluteMax' => $pdfData['absolute_max'],
            'dayEntries' => $pdfData['day_entries'],
            'tournamentStandings' => $pdfData['tournament_standings'],
            'tournamentEvents' => $pdfData['tournament_events'],
        ])->setPaper('a4', 'landscape');

        $fileName = sprintf(
            'piazzamenti-%s-%s.pdf',
            Str::slug($event->tournament->name),
            optional($event->event_date)->format('Ymd') ?? $event->id
        );

        return $pdf->download($fileName);
    }

    public function tournamentResults(Tournament $tournament): RedirectResponse|View
    {
        $this->authorizeTournamentAccess($tournament);
        $tournament->load([
            'events.draws.angler.team',
            'events.weightEntries',
        ]);

        if (!$tournament->isCompleted()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->withErrors(['general' => 'Complete all events and weights before checking the final standings.']);
        }

        $standings = $this->tournamentStandingsCalculator->calculate($tournament);
        if (!$standings['complete']) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->withErrors(['general' => 'Final standings are not available. Check the events data.']);
        }

        $eventModels = $tournament->events()
            ->with(['structure', 'meteosatReport'])
            ->orderBy('event_date')
            ->get();

        return view('tournaments.final_results', [
            'tournament' => $tournament,
            'events' => $standings['events'],
            'standings' => $standings['standings'],
            'eventModels' => $eventModels,
        ]);
    }

    public function tournamentResultsPdf(Tournament $tournament): RedirectResponse|Response
    {
        $this->authorizeTournamentAccess($tournament);
        $tournament->load([
            'events.draws.angler.team',
            'events.weightEntries',
        ]);

        if (!$tournament->isCompleted()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->withErrors(['general' => 'Complete all events and weights before exporting the final results.']);
        }

        $standings = $this->tournamentStandingsCalculator->calculate($tournament);
        if (!$standings['complete']) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->withErrors(['general' => 'Final standings are not available.']);
        }

        $pdf = Pdf::loadView('tournaments.final_results_pdf', [
            'tournament' => $tournament,
            'events' => $standings['events'],
            'standings' => $standings['standings'],
        ])->setPaper('a4', 'landscape');

        $fileName = sprintf(
            'risultati-finali-%s.pdf',
            Str::slug($tournament->name)
        );

        return $pdf->download($fileName);
    }

    public function tournamentPartialResults(Tournament $tournament): RedirectResponse|View
    {
        $this->authorizeTournamentAccess($tournament);
        $tournament->load([
            'events.draws.angler.team',
            'events.weightEntries',
        ]);

        $standings = $this->tournamentStandingsCalculator->calculate($tournament, false);

        if ($standings['standings']->isEmpty()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->withErrors(['general' => 'No data available for partial results.']);
        }

        return view('tournaments.partial_results', [
            'tournament' => $tournament,
            'events' => $standings['events'],
            'standings' => $standings['standings'],
        ]);
    }

    public function store(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        $validated = $request->validate([
            'zone_name' => ['required', 'array'],
            'zone_name.*' => ['required', 'string'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['nullable', 'array'],
            'weights.*.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $zoneNames = collect($validated['zone_name'])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();
        $weightsInput = $validated['weights'] ?? [];

        if ($zoneNames->isEmpty()) {
            return redirect()
                ->route('weights.show', $event)
                ->withErrors(['general' => 'No valid zone to update.']);
        }

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

        $response = redirect()->route('weights.show', $event);

        if (!empty($updatedZones)) {
            $zoneList = implode(', ', $updatedZones);
            $response->with('status', count($updatedZones) === 1
                ? "Weights updated correctly for zone '{$zoneList}'."
                : "Weights updated correctly for zones: {$zoneList}.");
        }

        if (!empty($errors)) {
            $response->withErrors(['general' => implode(' ', $errors)]);
        }

        if (empty($updatedZones) && empty($errors)) {
            $response->with('status', 'No changes to weights.');
        }

        return $response;
    }

    public function lockZone(Request $request, FishingEvent $event): RedirectResponse
    {
        $validated = $request->validate([
            'zone_name' => ['required', 'string'],
            'zone_names' => ['nullable', 'array'],
            'zone_names.*' => ['string'],
        ]);

        $zoneIdentifier = trim($validated['zone_name']);
        $zoneList = $zoneIdentifier === '__bulk__'
            ? collect($validated['zone_names'] ?? [])
            : collect([$zoneIdentifier]);

        $zoneList = $zoneList
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        if ($zoneList->isEmpty()) {
            return redirect()
                ->route('weights.show', $event)
                ->withErrors(['zone' => 'No valid zone to confirm.']);
        }

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

        $response = redirect()->route('weights.show', $event);

        if (!empty($success)) {
            $response->with('status', 'Confirmed zones: ' . implode(', ', $success));
        }

        if (!empty($errors)) {
            $response->withErrors(['zone' => implode(' ', $errors)]);
        }

        if (!empty($success)) {
            $event->loadMissing(['draws.angler.zone', 'weightEntries', 'zoneLocks', 'tournament']);
            if ($this->allZonesLocked($event)) {
                $this->scheduleMeteosatJob($event);
            }
        }

        return $response;
    }

    public function unlockZone(Request $request, FishingEvent $event): RedirectResponse
    {
        $validated = $request->validate([
            'zone_name' => ['required', 'string'],
        ]);

        $zoneName = trim($validated['zone_name']);
        $zoneKey = $this->normalizeZoneKey($zoneName);

        $event->load('zoneLocks');
        $lock = $event->zoneLocks->firstWhere('zone_key', $zoneKey);

        if ($lock === null) {
            return redirect()
                ->route('weights.show', $event)
                ->withErrors(['zone' => "Zone '{$zoneName}' is not confirmed."]);
        }

        $lock->delete();

        return redirect()
            ->route('weights.show', $event)
            ->with('status', "Zone '{$zoneName}' unlocked: weights can be modified.");
    }

    /**
     * @return \Illuminate\Support\Collection<int,array{name:string,key:string,draws:\Illuminate\Support\Collection,min_peg:?string,min_sort_key:string,complete:bool,locked:bool,locked_at:?\Illuminate\Support\Carbon,lock_form_id:string}>
     */
    private function prepareZoneDisplayData(FishingEvent $event, \Illuminate\Support\Collection $weightMap)
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

    private function allZonesLocked(FishingEvent $event): bool
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

    private function pegSortKey(?string $peg): string
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

    private function resolveZoneName(\App\Models\Draw $draw): string
    {
        return $draw->frozen_zone_name
            ?? optional($draw->angler->zone)->name
            ?? 'Zona';
    }

    private function normalizeZoneKey(string $zoneName): string
    {
        return strtoupper(trim($zoneName));
    }

    private function authorizeEventAccess(FishingEvent $event): void
    {
        $event->loadMissing('tournament');
        if ($event->tournament) {
            $this->authorizeTournamentAccess($event->tournament);
        }

        $user = auth()->user();
        if ($user?->role === User::ROLE_RILEVATORE) {
            $eventDate = optional($event->event_date)->toDateString();
            if ($event->is_locked || $eventDate !== now()->toDateString()) {
                abort(403, 'This event is not available for your profile.');
            }
        }
    }

    private function authorizeTournamentAccess(Tournament $tournament): void
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return;
        }

        $structure = $this->userStructure($user);
        if (!$structure || $tournament->structure_id !== $structure->id) {
            abort(403, 'You do not have access to this tournament.');
        }
    }

    private function userStructure(?User $user): ?Structure
    {
        return $user?->assignedStructure();
    }
}
