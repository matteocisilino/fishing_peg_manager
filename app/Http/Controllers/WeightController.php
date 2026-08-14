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

use App\Http\Requests\StoreWeightsRequest;
use App\Models\FishingEvent;
use App\Models\Structure;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\TournamentStandingsCalculator;
use App\Services\Weights\EventResultsPdfDataBuilder;
use App\Services\Weights\PlacementCalculator;
use App\Services\WeightService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WeightController extends Controller
{
    public function __construct(
        private readonly PlacementCalculator $placementCalculator,
        private readonly TournamentStandingsCalculator $tournamentStandingsCalculator,
        private readonly EventResultsPdfDataBuilder $eventResultsPdfDataBuilder,
        private readonly WeightService $weightService
    ) {}

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
        $zonesData = $this->weightService->prepareZoneDisplayData($event, $weightMap);

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

    public function store(StoreWeightsRequest $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        $validated = $request->validated();

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

        $res = $this->weightService->storeWeights($event, $zoneNames, $weightsInput);

        $response = redirect()->route('weights.show', $event);

        if (!empty($res['updated'])) {
            $zoneList = implode(', ', $res['updated']);
            $response->with('status', count($res['updated']) === 1
                ? "Weights updated correctly for zone '{$zoneList}'."
                : "Weights updated correctly for zones: {$zoneList}.");
        }

        if (!empty($res['errors'])) {
            $response->withErrors(['general' => implode(' ', $res['errors'])]);
        }

        if (empty($res['updated']) && empty($res['errors'])) {
            $response->with('status', 'No changes to weights.');
        }

        return $response;
    }

    public function lockZone(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);

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

        $res = $this->weightService->lockZone($event, $zoneList);

        $response = redirect()->route('weights.show', $event);

        if (!empty($res['success'])) {
            $response->with('status', 'Confirmed zones: ' . implode(', ', $res['success']));
        }

        if (!empty($res['errors'])) {
            $response->withErrors(['zone' => implode(' ', $res['errors'])]);
        }

        return $response;
    }

    public function unlockZone(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);

        $validated = $request->validate([
            'zone_name' => ['required', 'string'],
        ]);

        $zoneName = trim($validated['zone_name']);
        $error = $this->weightService->unlockZone($event, $zoneName);

        if ($error !== null) {
            return redirect()
                ->route('weights.show', $event)
                ->withErrors(['zone' => $error]);
        }

        return redirect()
            ->route('weights.show', $event)
            ->with('status', "Zone '{$zoneName}' unlocked: weights can be modified.");
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
