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

use App\Http\Requests\StoreTeamTournamentRequest;
use App\Http\Requests\StoreIndividualTournamentRequest;
use App\Http\Requests\StorePoolTournamentRequest;
use App\Http\Requests\UpdateTournamentRequest;
use App\Models\Structure;
use App\Models\Tournament;
use App\Services\SecureActionService;
use App\Services\TournamentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TournamentController extends Controller
{
    public function __construct(
        private readonly SecureActionService $secureActionService,
        private readonly TournamentService $tournamentService
    ) {}

    public function index(): View
    {
        $query = Tournament::with([
            'events.draws',
            'events.weightEntries',
            'events.structure',
            'structure',
        ])->orderByDesc('id');

        $user = auth()->user();
        if (!$user?->isSuperAdmin()) {
            $structure = $user?->assignedStructure();
            if (!$structure) {
                return view('tournaments.index', [
                    'activeTournaments' => collect(),
                    'completedTournaments' => collect(),
                ]);
            }
            $query->where('structure_id', $structure->id);
        }

        $tournaments = $query->get();

        [$completed, $active] = $tournaments->partition(fn ($tournament) => $tournament->isCompleted());

        return view('tournaments.index', [
            'activeTournaments' => $active,
            'completedTournaments' => $completed,
        ]);
    }

    public function create(): View
    {
        [$structures, $defaultStructure] = $this->tournamentService->structureFormData();
        $structureLakeMap = $this->tournamentService->structureLakeMap($structures, $defaultStructure);

        return view('tournaments.create', [
            'participantSuggestions' => $this->tournamentService->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $structureLakeMap,
            'structureLakeSelections' => $this->tournamentService->structureLakeSelections($structures, $defaultStructure),
        ]);
    }

    public function createIndividual(): View
    {
        [$structures, $defaultStructure] = $this->tournamentService->structureFormData();
        $structureLakeMap = $this->tournamentService->structureLakeMap($structures, $defaultStructure);

        return view('tournaments.create_individual', [
            'participantSuggestions' => $this->tournamentService->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $structureLakeMap,
            'structureLakeSelections' => $this->tournamentService->structureLakeSelections($structures, $defaultStructure),
        ]);
    }

    public function createPool(): View
    {
        [$structures, $defaultStructure] = $this->tournamentService->structureFormData();
        $structureLakeMap = $this->tournamentService->structureLakeMap($structures, $defaultStructure);

        return view('tournaments.create_pool', [
            'participantSuggestions' => $this->tournamentService->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $structureLakeMap,
            'structureLakeSelections' => $this->tournamentService->structureLakeSelections($structures, $defaultStructure),
        ]);
    }

    public function store(StoreTeamTournamentRequest $request): RedirectResponse
    {
        $structureId = $this->tournamentService->determineStructureId($request->user(), $request->input('structure_id'));
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->tournamentService->resolveStructureLakeSelection($request->input('structure_lake_ids', []), $structureId, $structure);
        $allowedPegCodes = $this->tournamentService->pegCodesForLakeSelection($structure, $lakeSelection);

        try {
            $zoneInputs = $this->tournamentService->parseZones(
                $request->input('zone_name', []),
                $request->input('zone_start', []),
                $request->input('zone_end', []),
                $request->input('zone_is_technical'),
                $allowedPegCodes
            );

            $tournament = $this->tournamentService->createTeamTournament(
                $request->all(),
                $structureId,
                $lakeSelection,
                $allowedPegCodes,
                $zoneInputs
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            report($throwable);
            return back()
                ->withErrors(['general' => 'Unexpected error while saving the tournament.'])
                ->withInput();
        }

        return redirect()->route('tournaments.show', $tournament)
            ->with('status', 'Tournament created successfully.');
    }

    public function storeIndividual(StoreIndividualTournamentRequest $request): RedirectResponse
    {
        $structureId = $this->tournamentService->determineStructureId($request->user(), $request->input('structure_id'));
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->tournamentService->resolveStructureLakeSelection($request->input('structure_lake_ids', []), $structureId, $structure);
        $allowedPegCodes = $this->tournamentService->pegCodesForLakeSelection($structure, $lakeSelection);

        try {
            $zoneInputs = $this->tournamentService->parseZones(
                $request->input('zone_name', []),
                $request->input('zone_start', []),
                $request->input('zone_end', []),
                $request->input('zone_is_technical'),
                $allowedPegCodes
            );

            $this->tournamentService->validateTechnicalZones($zoneInputs, (int)$request->input('sector_size', 5), $allowedPegCodes);

            $participants = $this->tournamentService->parseParticipants($request->all());

            $tournament = $this->tournamentService->createIndividualTournament(
                $request->all(),
                $structureId,
                $lakeSelection,
                $allowedPegCodes,
                $zoneInputs,
                $participants
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            report($throwable);
            return back()
                ->withErrors(['general' => 'Unexpected error while saving the tournament.'])
                ->withInput();
        }

        return redirect()->route('tournaments.show', $tournament)
            ->with('status', 'Individual tournament created successfully.');
    }

    public function storePool(StorePoolTournamentRequest $request): RedirectResponse
    {
        $structureId = $this->tournamentService->determineStructureId($request->user(), $request->input('structure_id'));
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->tournamentService->resolveStructureLakeSelection($request->input('structure_lake_ids', []), $structureId, $structure);
        $allowedPegs = $this->tournamentService->pegCodesForLakeSelection($structure, $lakeSelection);

        $maxAvailablePegs = count($allowedPegs);
        $capacityRequested = (int)$request->input('pool_capacity', 0);
        if ($maxAvailablePegs > 0 && $capacityRequested > $maxAvailablePegs) {
            throw ValidationException::withMessages([
                'pool_capacity' => "The maximum number of participants ({$capacityRequested}) exceeds the {$maxAvailablePegs} available pegs.\nA) Add another lake if available.\nB) Reduce the maximum number of participants because the selected lakes do not allow it.",
            ]);
        }

        try {
            $zonesData = $this->tournamentService->parsePoolZones($request->all(), $allowedPegs);
            $pegNumbersCount = $this->tournamentService->countPoolPegEntries($zonesData);
            if ($pegNumbersCount <= 0) {
                throw ValidationException::withMessages(['pool_pegs' => 'Define at least one zone with valid pegs.']);
            }
            if ($capacityRequested > 0 && $pegNumbersCount > $capacityRequested) {
                throw ValidationException::withMessages([
                    'pool_pegs' => "You specified {$capacityRequested} participants: you cannot enter more than {$capacityRequested} pegs (currently {$pegNumbersCount}).",
                ]);
            }

            $tournament = $this->tournamentService->createPoolTournament(
                $request->all(),
                $structureId,
                $lakeSelection,
                $allowedPegs,
                $zonesData
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            report($throwable);
            return back()
                ->withErrors(['general' => 'Unexpected error while saving the pool tournament.'])
                ->withInput();
        }

        return redirect()->route('tournaments.show', $tournament)
            ->with('status', 'Pool tournament created successfully.');
    }

    public function show(Tournament $tournament): View
    {
        $this->ensureTournamentAccess($tournament);

        $zones = $tournament->zones()->orderBy('start_number')->get();
        $poolPegs = $tournament->isPool()
            ? $tournament->poolPegs()->orderBy('peg_number')->get()
            : collect();
        $teams = $tournament->teams()->with(['anglers.zone'])->orderBy('id')->get();
        $events = $tournament->events()
            ->with(['draws', 'weightEntries', 'structure'])
            ->orderBy('event_date')
            ->get();
        $participants = ($tournament->isIndividual() || $tournament->isPool())
            ? $tournament->anglers()->orderBy('name')->get()
            : collect();

        return view('tournaments.show', compact('tournament', 'zones', 'teams', 'events', 'participants', 'poolPegs'));
    }

    public function edit(Tournament $tournament): View
    {
        $this->ensureTournamentAccess($tournament);
        [$structures, $defaultStructure] = $this->tournamentService->structureFormData($tournament);

        $zones = $tournament->zones()->orderBy('start_number')->get();
        $poolPegs = $tournament->isPool()
            ? $tournament->poolPegs()->orderBy('peg_number')->get()
            : collect();
        $teams = $tournament->teams()->with(['anglers.zone'])->orderBy('id')->get();
        $events = $tournament->events()->with('structure')->orderBy('event_date')->get();
        $participants = ($tournament->isIndividual() || $tournament->isPool())
            ? $tournament->anglers()->orderBy('name')->get()
            : collect();

        return view('tournaments.edit', compact('tournament', 'zones', 'teams', 'events', 'participants', 'poolPegs') + [
            'participantSuggestions' => $this->tournamentService->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $this->tournamentService->structureLakeMap($structures, $defaultStructure ?: $tournament->structure),
            'structureLakeSelections' => $this->tournamentService->structureLakeSelections($structures, $defaultStructure ?: $tournament->structure, $tournament),
        ]);
    }

    public function update(UpdateTournamentRequest $request, Tournament $tournament): RedirectResponse
    {
        $this->ensureTournamentAccess($tournament);
        $structureId = $this->tournamentService->determineStructureId($request->user(), $request->input('structure_id'), $tournament);
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->tournamentService->resolveStructureLakeSelection($request->input('structure_lake_ids', []), $structureId, $structure);

        try {
            $this->tournamentService->updateTournament(
                $tournament,
                $request->all(),
                $structureId,
                $lakeSelection,
                $structure
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            report($throwable);
            return back()
                ->withErrors(['general' => 'Unexpected error while saving the tournament.'])
                ->withInput();
        }

        return redirect()->route('tournaments.show', $tournament)
            ->with('status', 'Tournament updated successfully.');
    }

    public function destroy(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorizeTournamentAccess($tournament);
        $data = $request->validate([
            'otp_code' => ['required', 'digits:6'],
        ]);
        $this->secureActionService->validate(
            $request->user(),
            $this->deleteActionKey($tournament),
            $data['otp_code']
        );

        DB::transaction(function () use ($tournament) {
            $tournament->events()->with(['draws', 'weightEntries', 'zoneLocks'])->get()->each(function ($event) {
                $event->draws()->delete();
                $event->weightEntries()->delete();
                $event->zoneLocks()->delete();
                $event->delete();
            });

            $tournament->teams()->with('anglers')->get()->each(function ($team) {
                $team->anglers()->delete();
                $team->delete();
            });

            $tournament->poolPegs()->delete();
            $tournament->zones()->delete();
            $tournament->delete();
        });

        return redirect()->route('tournaments.index')->with('status', 'Tournament deleted permanently.');
    }

    public function requestDeleteOtp(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorizeTournamentAccess($tournament);

        $this->secureActionService->issue(
            $request->user(),
            $this->deleteActionKey($tournament),
            [
                'label' => "eliminare il torneo {$tournament->name}",
                'tournament_id' => $tournament->id,
            ]
        );

        return back()->with('status', 'OTP sent via email. Enter it to confirm the tournament deletion.');
    }

    private function ensureTournamentAccess(Tournament $tournament): void
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return;
        }

        $structure = $user?->assignedStructure();
        if (!$structure || $tournament->structure_id !== $structure->id) {
            abort(403, 'You do not have access to this tournament.');
        }
    }

    private function authorizeTournamentAccess(Tournament $tournament): void
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return;
        }

        $structure = $user?->assignedStructure();
        if (!$structure || $tournament->structure_id !== $structure->id) {
            abort(403, 'You do not have access to this tournament.');
        }
    }

    private function deleteActionKey(Tournament $tournament): string
    {
        return 'tournaments.delete:' . $tournament->id;
    }
}
