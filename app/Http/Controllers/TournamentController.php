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

use App\Models\Angler;
use App\Models\ParticipantDirectory;
use App\Models\Structure;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\Zone;
use App\Support\PegHelper;
use App\Services\SecureActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TournamentController extends Controller
{
    public function __construct(private readonly SecureActionService $secureActionService)
    {
    }

    /**
     * @var list<int>|null
     */
    private ?array $structureIdCache = null;

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
        [$structures, $defaultStructure] = $this->structureFormData();

        $structureLakeMap = $this->structureLakeMap($structures, $defaultStructure);

        return view('tournaments.create', [
            'participantSuggestions' => $this->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $structureLakeMap,
            'structureLakeSelections' => $this->structureLakeSelections($structures, $defaultStructure),
        ]);
    }

    public function createIndividual(): View
    {
        [$structures, $defaultStructure] = $this->structureFormData();

        $structureLakeMap = $this->structureLakeMap($structures, $defaultStructure);

        return view('tournaments.create_individual', [
            'participantSuggestions' => $this->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $structureLakeMap,
            'structureLakeSelections' => $this->structureLakeSelections($structures, $defaultStructure),
        ]);
    }

    public function createPool(): View
    {
        [$structures, $defaultStructure] = $this->structureFormData();

        $structureLakeMap = $this->structureLakeMap($structures, $defaultStructure);

        return view('tournaments.create_pool', [
            'participantSuggestions' => $this->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $structureLakeMap,
            'structureLakeSelections' => $this->structureLakeSelections($structures, $defaultStructure),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            't_name' => ['required', 'string', 'max:255'],
            'sector_size' => ['required', 'integer', Rule::in([5, 10])],
            'team_size' => ['required', 'integer', 'min:1'],
            'allow_edge_repeats' => ['required', 'boolean'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ]);

        $structureId = $this->determineStructureId($request);
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->resolveStructureLakeSelection($request, $structureId, $structure);
        $allowedPegCodes = $this->pegCodesForLakeSelection($structure, $lakeSelection);
        $tournament = null;

        try {
            DB::transaction(function () use ($request, &$tournament, $structureId, $lakeSelection, $allowedPegCodes) {
                $tournament = Tournament::create([
                    'name' => trim((string)$request->input('t_name')),
                    'sector_size' => (int)$request->input('sector_size', 5),
                    'tournament_type' => Tournament::TYPE_TEAM,
                    'team_size' => (int)$request->input('team_size'),
                    'allow_edge_repeats' => $request->boolean('allow_edge_repeats'),
                    'structure_id' => $structureId,
                ]);

                $tournament->lakes()->sync($lakeSelection);

                $zoneInputs = $this->parseZones($request, $allowedPegCodes);
                foreach ($zoneInputs as $zoneData) {
                    $tournament->zones()->create($zoneData);
                }

                $zonesMap = $tournament->zones()->pluck('id', 'name')->all();

                $this->storeTeams($request, $tournament, $zonesMap, (int)$request->input('team_size'));
                $this->storeEvents($request, $tournament);
            });
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

    public function storeIndividual(Request $request): RedirectResponse
    {
        $request->validate([
            't_name' => ['required', 'string', 'max:255'],
            'sector_size' => ['required', 'integer', Rule::in([5, 10])],
            'participant_name' => ['nullable', 'array'],
            'participant_name.*' => ['nullable', 'string', 'max:255'],
            'allow_edge_repeats' => ['required', 'boolean'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ]);

        $structureId = $this->determineStructureId($request);
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->resolveStructureLakeSelection($request, $structureId, $structure);
        $allowedPegCodes = $this->pegCodesForLakeSelection($structure, $lakeSelection);
        $tournament = null;

        try {
            DB::transaction(function () use ($request, &$tournament, $structureId, $lakeSelection, $allowedPegCodes) {
                $sectorSize = (int)$request->input('sector_size', 5);
                $tournament = Tournament::create([
                    'name' => trim((string)$request->input('t_name')),
                    'sector_size' => $sectorSize,
                    'tournament_type' => Tournament::TYPE_INDIVIDUAL,
                    'allow_edge_repeats' => $request->boolean('allow_edge_repeats'),
                    'structure_id' => $structureId,
                ]);

                $tournament->lakes()->sync($lakeSelection);

                $zoneInputs = $this->parseZones($request, $allowedPegCodes);
                $this->validateTechnicalZones($zoneInputs, $sectorSize, $allowedPegCodes);
                foreach ($zoneInputs as $zoneData) {
                    $tournament->zones()->create($zoneData);
                }

                $zones = $tournament->zones()->orderBy('start_number')->get();
                $pegLookup = PegHelper::buildLookup($allowedPegCodes);
                $maxSlots = $zones->sum(fn ($zone) => $this->zonePegCount($zone, $allowedPegCodes, $pegLookup));
                if ($maxSlots <= 0) {
                    throw ValidationException::withMessages(['participants' => 'No pegs available to assign participants.']);
                }

                $participants = $this->parseParticipants($request);
                if (empty($participants)) {
                    throw ValidationException::withMessages(['participants' => 'Enter at least one participant.']);
                }
                if (count($participants) > $maxSlots) {
                    throw ValidationException::withMessages(['participants' => 'The number of participants exceeds available pegs.']);
                }

                $team = $tournament->teams()->create([
                    'name' => 'Individuali',
                ]);

                $zoneIds = $zones->pluck('id')->all();
                if (empty($zoneIds)) {
                    throw ValidationException::withMessages(['participants' => 'Define at least one zone to assign participants.']);
                }
                $zoneCount = count($zoneIds);
                foreach ($participants as $index => $participantName) {
                    $zoneId = $zoneIds[$index % $zoneCount];
                    $team->anglers()->create([
                        'name' => $participantName,
                        'zone_id' => $zoneId,
                    ]);
                }

                $this->storeEvents($request, $tournament);
            });
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

    public function storePool(Request $request): RedirectResponse
    {
        $request->validate([
            't_name' => ['required', 'string', 'max:255'],
            'pool_capacity' => ['required', 'integer', 'min:1'],
            'pool_zone_name' => ['required', 'array'],
            'pool_zone_name.*' => ['nullable', 'string', 'max:50'],
            'pool_zone_pegs' => ['required', 'array'],
            'pool_zone_pegs.*' => ['nullable', 'string'],
            'participant_name' => ['nullable', 'array'],
            'participant_name.*' => ['nullable', 'string', 'max:255'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ]);

        $structureId = $this->determineStructureId($request);
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->resolveStructureLakeSelection($request, $structureId, $structure);
        $allowedPegs = $this->pegCodesForLakeSelection($structure, $lakeSelection);
        $tournament = null;

        $maxAvailablePegs = count($allowedPegs);
        $capacityRequested = (int)$request->input('pool_capacity', 0);
        if ($maxAvailablePegs > 0 && $capacityRequested > $maxAvailablePegs) {
            throw ValidationException::withMessages([
                'pool_capacity' => "The maximum number of participants ({$capacityRequested}) exceeds the {$maxAvailablePegs} available pegs.\nA) Add another lake if available.\nB) Reduce the maximum number of participants because the selected lakes do not allow it.",
            ]);
        }

        try {
            DB::transaction(function () use ($request, &$tournament, $structureId, $allowedPegs, $lakeSelection, $maxAvailablePegs) {
                $capacity = (int)$request->input('pool_capacity', 0);
                $zonesData = $this->parsePoolZones($request, $allowedPegs);
                $pegNumbersCount = $this->countPoolPegEntries($zonesData);
                if ($pegNumbersCount <= 0) {
                    throw ValidationException::withMessages(['pool_pegs' => 'Enter at least one valid peg for a zone.']);
                }
                if ($capacity > 0 && $pegNumbersCount > $capacity) {
                    throw ValidationException::withMessages([
                        'pool_pegs' => "You specified {$capacity} participants: you cannot enter more than {$capacity} pegs (currently {$pegNumbersCount}).",
                    ]);
                }

                $effectiveCapacity = $capacity > 0 ? min($capacity, $pegNumbersCount) : $pegNumbersCount;

                $tournament = Tournament::create([
                    'name' => trim((string)$request->input('t_name')),
                    'sector_size' => 0,
                    'tournament_type' => Tournament::TYPE_POOL,
                    'pool_capacity' => $capacity,
                    'allow_edge_repeats' => true,
                    'structure_id' => $structureId,
                ]);

                $tournament->lakes()->sync($lakeSelection);

                foreach ($zonesData as $zoneData) {
                    foreach ($zoneData['pegs'] as $peg) {
                        $tournament->poolPegs()->create([
                            'peg_number' => $peg,
                            'zone_name' => $zoneData['name'],
                        ]);
                    }
                }

                $participants = $this->parseParticipants($request);
                if (empty($participants)) {
                    throw ValidationException::withMessages(['participants' => 'Enter at least one participant.']);
                }
                if ($effectiveCapacity > 0 && count($participants) > $effectiveCapacity) {
                    throw ValidationException::withMessages([
                        'participants' => 'The number of participants exceeds the limit allowed by available pegs.',
                    ]);
                }

                $team = $tournament->teams()->create([
                    'name' => 'Pool',
                ]);

                foreach ($participants as $participantName) {
                    $team->anglers()->create([
                        'name' => $participantName,
                        'zone_id' => null,
                        'is_absent' => false,
                    ]);
                }

                $this->storeEvents($request, $tournament);
            });
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
        [$structures, $defaultStructure] = $this->structureFormData($tournament);

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
            'participantSuggestions' => $this->participantSuggestions(),
            'structures' => $structures,
            'defaultStructure' => $defaultStructure,
            'structureLakeMap' => $this->structureLakeMap($structures, $defaultStructure ?: $tournament->structure),
            'structureLakeSelections' => $this->structureLakeSelections($structures, $defaultStructure ?: $tournament->structure, $tournament),
        ]);
    }

    public function update(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->ensureTournamentAccess($tournament);
        $rules = [
            't_name' => ['required', 'string', 'max:255'],
            'allow_edge_repeats' => ['nullable', 'boolean'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ];
        if (!$tournament->isPool()) {
            $rules['sector_size'] = ['required', 'integer', Rule::in([5, 10])];
        }
        if ($tournament->isTeam()) {
            $rules['team_size'] = ['required', 'integer', 'min:1'];
        }
        if ($tournament->isPool()) {
            $rules['pool_capacity'] = ['required', 'integer', 'min:1'];
            $rules['pool_zone_name'] = ['required', 'array'];
            $rules['pool_zone_name.*'] = ['nullable', 'string', 'max:50'];
            $rules['pool_zone_pegs'] = ['required', 'array'];
            $rules['pool_zone_pegs.*'] = ['nullable', 'string'];
        }
        $request->validate($rules);

        $structureId = $this->determineStructureId($request, $tournament);
        $structure = Structure::with('lakes')->findOrFail($structureId);
        $lakeSelection = $this->resolveStructureLakeSelection($request, $structureId, $structure);

        try {
            DB::transaction(function () use ($request, $tournament, $structureId, $structure, $lakeSelection) {
                $payload = [
                    'name' => trim((string)$request->input('t_name')),
                ];
                if (!$tournament->isPool()) {
                    $payload['sector_size'] = (int)$request->input('sector_size', 5);
                }
                if (!$tournament->isPool()) {
                    $payload['allow_edge_repeats'] = $request->boolean('allow_edge_repeats', $tournament->allow_edge_repeats);
                }
                if ($tournament->isTeam()) {
                    $payload['team_size'] = (int)$request->input('team_size');
                }
                if ($tournament->isPool()) {
                    $payload['pool_capacity'] = (int)$request->input('pool_capacity');
                }
                $payload['structure_id'] = $structureId;
                $tournament->update($payload);
                $tournament->lakes()->sync($lakeSelection);

                if ($tournament->isTeam()) {
                    $zonesByName = $tournament->zones()->get()->keyBy('name');

                    foreach ($request->input('team_name_edit', []) as $teamId => $teamName) {
                        $teamName = trim((string)$teamName);
                        if ($teamName === '') {
                            continue;
                        }
                        Team::whereKey((int)$teamId)
                            ->where('tournament_id', $tournament->id)
                            ->update(['name' => $teamName]);
                    }

                    $this->updateAnglers($request, $tournament, $zonesByName);
                } elseif ($tournament->isPool()) {
                    $allowedPegs = $this->pegCodesForLakeSelection($structure, $lakeSelection);
                    $capacity = (int)$request->input('pool_capacity', 0);
                    if (!empty($allowedPegs) && $capacity > count($allowedPegs)) {
                        throw ValidationException::withMessages([
                            'pool_capacity' => "The maximum number of participants ({$capacity}) exceeds the " . count($allowedPegs) . " available pegs.\nA) Add another lake if available.\nB) Reduce the maximum number of participants because the selected lakes do not allow it.",
                        ]);
                    }
                    $this->syncPoolPegs($request, $tournament, $allowedPegs, $capacity);
                    $this->updatePoolParticipants($request, $tournament);
                } else {
                    $this->updateIndividualParticipants($request, $tournament);
                }

                $this->syncEvents($request, $tournament);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            report($throwable);
            return back()
                ->withErrors(['general' => 'Unexpected error while updating the tournament.'])
                ->withInput();
        }

        return redirect()->route('tournaments.show', $tournament)
            ->with('status', 'Tournament updated successfully.');
    }

    private function structureFormData(?Tournament $tournament = null): array
    {
        $user = auth()->user();
        $structures = collect();
        $defaultStructure = $tournament?->structure;

        if ($user?->isSuperAdmin()) {
            $structures = Structure::with('lakes')->orderBy('name')->get();
            if (!$defaultStructure && $structures->isNotEmpty()) {
                $defaultStructure = $structures->first();
            }
        } else {
            $defaultStructure = $defaultStructure ?: $user?->assignedStructure();
            if (!$defaultStructure) {
                abort(403, 'You do not have a facility associated with your profile.');
            }
            $defaultStructure->loadMissing('lakes');
        }

        return [$structures, $defaultStructure];
    }

    /**
     * @param \Illuminate\Support\Collection<int, Structure> $structures
     * @return array<int, array{lakes: array<int, array{name:string, peg_count:int, prefix: ?string, pegs: array<int, string>}>, all_pegs: array<int, string>}>
     */
    private function structureLakeMap(Collection $structures, ?Structure $defaultStructure): array
    {
        $map = [];

        $structureList = $structures;
        if ($defaultStructure && !$structures->contains('id', $defaultStructure->id)) {
            $structureList = $structures->concat([$defaultStructure]);
        }

        foreach ($structureList as $structure) {
            $map[$structure->id] = $this->describeStructureLakes($structure);
        }

        return $map;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Structure> $structures
     * @return array<string, array<int>>
     */
    private function structureLakeSelections(Collection $structures, ?Structure $defaultStructure, ?Tournament $tournament = null): array
    {
        $selections = [];
        $structureList = $structures;
        if ($defaultStructure && !$structures->contains('id', $defaultStructure->id)) {
            $structureList = $structures->concat([$defaultStructure]);
        }

        foreach ($structureList as $structure) {
            $isSingleLake = $structure->lakes->count() === 1;
            $defaultSelection = $isSingleLake
                ? [$structure->lakes->first()->id]
                : [];

            if ($tournament && $tournament->structure_id === $structure->id) {
                $current = $tournament->lakes()->pluck('structure_lake_id')->all();
                $selections[$structure->id] = !empty($current) ? $current : $defaultSelection;
            } else {
                $selections[$structure->id] = $defaultSelection;
            }
        }

        return $selections;
    }

    private function describeStructureLakes(Structure $structure): array
    {
        $structure->loadMissing('lakes');

        $lakes = [];
        $flat = [];

        foreach ($structure->lakes as $lake) {
            $prefix = $lake->peg_prefix ? strtoupper($lake->peg_prefix) : '';
            $pegCodes = [];

            for ($i = 1; $i <= (int) $lake->peg_count; $i++) {
                $code = $prefix !== '' ? $prefix . $i : (string) $i;
                $pegCodes[] = $code;
                $flat[] = $code;
            }

            $lakes[] = [
                'id' => $lake->id,
                'name' => $lake->name,
                'peg_count' => (int) $lake->peg_count,
                'prefix' => $prefix !== '' ? $prefix : null,
                'pegs' => $pegCodes,
            ];
        }

        return [
            'lakes' => $lakes,
            'all_pegs' => $flat,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedPegCodesForStructure(int $structureId): array
    {
        $structure = Structure::with('lakes')->find($structureId);
        if (!$structure) {
            return [];
        }

        return $this->describeStructureLakes($structure)['all_pegs'];
    }

    /**
     * @param array<int> $lakeIds
     * @return list<string>
     */
    private function pegCodesForLakeSelection(Structure $structure, array $lakeIds): array
    {
        $structure->loadMissing('lakes');
        $map = $this->describeStructureLakes($structure);
        if (empty($lakeIds)) {
            return $map['all_pegs'];
        }
        $allowed = [];
        $lookup = array_flip($lakeIds);
        foreach ($map['lakes'] as $lake) {
            if (isset($lookup[$lake['id']])) {
                $allowed = array_merge($allowed, $lake['pegs']);
            }
        }
        return $allowed;
    }

    /**
     * @return list<string>
     */
    private function allowedPegCodesForTournament(Tournament $tournament): array
    {
        return PegHelper::allowedCodesForTournament($tournament);
    }

    /**
     * @return list<int>
     */
    private function resolveStructureLakeSelection(Request $request, int $structureId, ?Structure $structure = null): array
    {
        $structure = $structure ?: Structure::with('lakes')->find($structureId);
        if (!$structure) {
            throw ValidationException::withMessages([
                'structure_id' => 'Invalid facility.',
            ]);
        }

        $availableLakeIds = $structure->lakes->pluck('id')->all();
        if (empty($availableLakeIds)) {
            throw ValidationException::withMessages([
                'structure_lake_ids' => 'This facility has no configured lakes.',
            ]);
        }

        $input = $request->input('structure_lake_ids', []);
        if (!is_array($input)) {
            $input = [];
        }

        $normalized = [];
        foreach ($input as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }
        $normalized = array_values(array_unique($normalized));

        $allowed = array_values(array_intersect($normalized, $availableLakeIds));
        if (empty($allowed)) {
            throw ValidationException::withMessages([
                'structure_lake_ids' => 'Select at least one valid lake for the chosen facility.',
            ]);
        }

        return $allowed;
    }

    private function determineStructureId(Request $request, ?Tournament $tournament = null): int
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            $structureId = (int)($request->input('structure_id') ?: $tournament?->structure_id);
            if ($structureId <= 0 || !Structure::whereKey($structureId)->exists()) {
                throw ValidationException::withMessages(['structure_id' => 'Select a valid facility.']);
            }
            return $structureId;
        }

        $structure = $user?->assignedStructure();
        if (!$structure) {
            throw ValidationException::withMessages(['structure_id' => 'You do not have an available facility.']);
        }

        if ($tournament && $tournament->structure_id !== $structure->id) {
            abort(403, 'You cannot modify a tournament from another facility.');
        }

        return $structure->id;
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

    /**
     * @return array<int, array<string, int|string>>
     */
    private function parseZones(Request $request, array $allowedPegCodes = []): array
    {
        $zones = [];
        $seen = [];
        $allowedLookup = PegHelper::buildLookup($allowedPegCodes);
        $zoneNames = $request->input('zone_name', []);
        $zoneStarts = $request->input('zone_start', []);
        $zoneEnds = $request->input('zone_end', []);
        $selectedTechnical = $request->input('zone_is_technical');
        if (is_array($selectedTechnical)) {
            $selectedTechnical = array_key_first(array_filter($selectedTechnical, fn ($v) => $v === '1' || $v === 1 || $v === true));
        }

        foreach ($zoneNames as $index => $nameRaw) {
            $name = trim((string)$nameRaw);
            $startRaw = $zoneStarts[$index] ?? '';
            $endRaw = $zoneEnds[$index] ?? '';

            if ($name === '' && trim((string)$startRaw) === '' && trim((string)$endRaw) === '') {
                continue;
            }

            $startCode = PegHelper::normalizeCode((string) $startRaw);
            $endCode = PegHelper::normalizeCode((string) $endRaw);

            if ($name === '' || $startCode === '' || $endCode === '') {
                throw ValidationException::withMessages(['zones' => 'Each zone must have a name, start, and end.']);
            }

            if (!PegHelper::isValidCode($startCode) || !PegHelper::isValidCode($endCode)) {
                throw ValidationException::withMessages(['zones' => 'Each zone must have a valid name and pegs (letters and numbers).']);
            }

            if (!empty($allowedLookup)) {
                if (!isset($allowedLookup[$startCode])) {
                    throw ValidationException::withMessages(['zones' => "The start peg '$startCode' does not belong to the selected lakes."]);
                }
                if (!isset($allowedLookup[$endCode])) {
                    throw ValidationException::withMessages(['zones' => "The end peg '$endCode' does not belong to the selected lakes."]);
                }
            }

            $startPosition = PegHelper::position($startCode, $allowedLookup);
            $endPosition = PegHelper::position($endCode, $allowedLookup);
            if ($startPosition !== null && $endPosition !== null && $startPosition > $endPosition) {
                throw ValidationException::withMessages(['zones' => "Zone '$name' has a start greater than the end."]);
            }

            $key = strtoupper($name);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['zones' => "Zone name '$name' is already used."]);
            }
            $seen[$key] = true;

            $zones[] = [
                'name' => $name,
                'start_number' => $startCode,
                'end_number' => $endCode,
                'is_technical' => ($selectedTechnical !== null && (int)$selectedTechnical === (int)$index),
                'start_position' => $startPosition ?? PegHelper::numericValue($startCode),
                'end_position' => $endPosition ?? PegHelper::numericValue($endCode),
            ];
        }

        if (empty($zones)) {
            throw ValidationException::withMessages(['zones' => 'You must define at least one zone.']);
        }

        usort($zones, fn ($a, $b) => $a['start_position'] <=> $b['start_position']);

        for ($i = 0; $i < count($zones) - 1; $i++) {
            $curr = $zones[$i];
            $next = $zones[$i + 1];
            if ($curr['end_position'] >= $next['start_position']) {
                throw ValidationException::withMessages(['zones' => "Zones '{$curr['name']}' and '{$next['name']}' overlap."]);
            }
        }

        return array_map(fn ($zone) => [
            'name' => $zone['name'],
            'start_number' => $zone['start_number'],
            'end_number' => $zone['end_number'],
            'is_technical' => $zone['is_technical'],
        ], $zones);
    }

    /**
     * @param Zone|array{name?:string,start_number?:string,end_number?:string} $zone
     * @param array<int,string> $allowedPegCodes
     * @param array<string,int> $lookup
     */
    private function zonePegCount(Zone|array $zone, array $allowedPegCodes, array $lookup = []): int
    {
        $start = is_array($zone) ? (string) ($zone['start_number'] ?? '') : (string) $zone->start_number;
        $end = is_array($zone) ? (string) ($zone['end_number'] ?? '') : (string) $zone->end_number;
        if ($start === '' || $end === '') {
            return 0;
        }

        if (empty($allowedPegCodes)) {
            $startNum = PegHelper::numericValue($start);
            $endNum = PegHelper::numericValue($end);
            return $endNum >= $startNum ? ($endNum - $startNum + 1) : 0;
        }

        $map = !empty($lookup) ? $lookup : PegHelper::buildLookup($allowedPegCodes);
        return PegHelper::rangeLength($start, $end, $allowedPegCodes, $map);
    }

    /**
     * @param array<string,int> $zonesMap
     */
    private function storeTeams(Request $request, Tournament $tournament, array $zonesMap, int $teamSize): void
    {
        $teamNames = $request->input('team_name', []);
        $memberNames = $request->input('member_name', []);
        $memberZones = $request->input('member_zone', []);

        foreach ($teamNames as $index => $nameRaw) {
            $teamName = trim((string)$nameRaw);
            if ($teamName === '') {
                continue;
            }

            $zonesUsed = [];
            $preparedMembers = [];
            foreach ($memberNames[$index] ?? [] as $mIndex => $memberNameRaw) {
                $memberName = trim((string)$memberNameRaw);
                $zoneCodeRaw = $memberZones[$index][$mIndex] ?? '';
                $zoneCode = strtoupper(trim((string)$zoneCodeRaw));
                if ($memberName === '' && $zoneCode === '') {
                    continue;
                }

                if ($memberName === '') {
                    throw ValidationException::withMessages(['teams' => "Team '$teamName' contains a participant without a name."]);
                }

                if ($zoneCode === '' || !isset($zonesMap[$zoneCode])) {
                    throw ValidationException::withMessages(['teams' => "Zone '$zoneCode' is not valid for participant '$memberName' (team '$teamName')."]);
                }

                if (in_array($zoneCode, $zonesUsed, true)) {
                    throw ValidationException::withMessages(['teams' => "In team '$teamName' zone '$zoneCode' is duplicated."]);
                }
                $zonesUsed[] = $zoneCode;

                $preparedMembers[] = [
                    'name' => $memberName,
                    'zone_id' => $zonesMap[$zoneCode],
                ];
            }

            if ($teamSize > 0 && count($preparedMembers) !== $teamSize) {
                throw ValidationException::withMessages(['teams' => "Team '$teamName' must have exactly {$teamSize} participants."]);
            }

            if (empty($preparedMembers)) {
                $team = $tournament->teams()->create(['name' => $teamName]);
                continue;
            }

            $team = $tournament->teams()->create(['name' => $teamName]);

            foreach ($preparedMembers as $memberData) {
                $team->anglers()->create($memberData);
            }
        }
    }

    private function storeEvents(Request $request, Tournament $tournament): void
    {
        $dates = $request->input('event_date', []);
        $names = $request->input('event_name', []);
        $structures = $request->input('event_structure_id', []);

        foreach ($dates as $index => $dateRaw) {
            $date = trim((string)$dateRaw);
            if ($date === '') {
                continue;
            }
            $name = trim((string)($names[$index] ?? '')) ?: 'Prova ' . ($index + 1);
            $structureId = $this->resolveEventStructureId($request, $tournament, $structures, $index);

            $tournament->events()->create([
                'event_date' => $date,
                'name' => $name,
                'structure_id' => $structureId,
            ]);
        }
    }

    private function updateAnglers(Request $request, Tournament $tournament, Collection $zonesByName): void
    {
        $names = $request->input('angler_name', []);
        $zones = $request->input('angler_zone', []);

        foreach ($names as $anglerId => $nameRaw) {
            $name = trim((string)$nameRaw);
            $zoneCode = trim((string)($zones[$anglerId] ?? ''));
            if ($name === '' || $zoneCode === '') {
                continue;
            }

            $zone = $zonesByName->get($zoneCode);
            if ($zone === null) {
                throw ValidationException::withMessages(['angler_zone' => "Zone '$zoneCode' is not valid for participant '$name'."]);
            }

            Angler::whereKey((int)$anglerId)->update([
                'name' => $name,
                'zone_id' => $zone->id,
            ]);
        }

        $teamSize = (int)$tournament->team_size;
        $newMembersInput = $request->input('team_new_member', []);
        $newZonesInput = $request->input('team_new_zone', []);

        if (!empty($newMembersInput) && is_array($newMembersInput)) {
            $teams = $tournament->teams()->with(['anglers.zone'])->get()->keyBy('id');
            foreach ($newMembersInput as $teamId => $memberRows) {
                $teamId = (int)$teamId;
                if (!$teams->has($teamId)) {
                    continue;
                }
                if (!is_array($memberRows)) {
                    continue;
                }
                $team = $teams->get($teamId);
                $currentCount = $team->anglers->count();
                $usedZones = [];
                foreach ($team->anglers as $existingAngler) {
                    $zoneName = optional($existingAngler->zone)->name;
                    if ($zoneName !== null && $zoneName !== '') {
                        $usedZones[] = strtoupper($zoneName);
                    }
                }

                foreach ($memberRows as $index => $memberNameRaw) {
                    $memberName = trim((string)$memberNameRaw);
                    $zoneName = trim((string)($newZonesInput[$teamId][$index] ?? ''));

                    if ($memberName === '' && $zoneName === '') {
                        continue;
                    }

                    if ($memberName === '' || $zoneName === '') {
                        throw ValidationException::withMessages([
                            'teams' => "Team '{$team->name}' has a participant without a name or zone.",
                        ]);
                    }

                    $zone = $zonesByName->get($zoneName);
                    if ($zone === null) {
                        throw ValidationException::withMessages([
                            'teams' => "Zone '{$zoneName}' is not valid for team '{$team->name}'.",
                        ]);
                    }

                    $zoneKey = strtoupper($zone->name);
                    if (in_array($zoneKey, $usedZones, true)) {
                        throw ValidationException::withMessages([
                            'teams' => "Zone '{$zone->name}' is already used in team '{$team->name}'.",
                        ]);
                    }

                    if ($teamSize > 0 && $currentCount >= $teamSize) {
                        throw ValidationException::withMessages([
                            'teams' => "Team '{$team->name}' cannot exceed {$teamSize} participants.",
                        ]);
                    }

                    $team->anglers()->create([
                        'name' => $memberName,
                        'zone_id' => $zone->id,
                        'is_absent' => false,
                    ]);
                    $usedZones[] = $zoneKey;
                    $currentCount++;
                }
            }
        }

        if ($teamSize > 0) {
            $teamCounts = $tournament->teams()->with(['anglers.zone'])->withCount('anglers')->get();
            foreach ($teamCounts as $team) {
                if ($team->anglers_count !== $teamSize) {
                    throw ValidationException::withMessages([
                        'teams' => "Team '{$team->name}' must have exactly {$teamSize} participants.",
                    ]);
                }

                $zoneUsage = [];
                foreach ($team->anglers as $angler) {
                    $zoneName = optional($angler->zone)->name;
                    if ($zoneName === null || $zoneName === '') {
                        continue;
                    }
                    $zoneKey = strtoupper($zoneName);
                    if (isset($zoneUsage[$zoneKey])) {
                        throw ValidationException::withMessages([
                            'teams' => "Zone '{$zoneName}' is assigned to multiple participants in team '{$team->name}'.",
                        ]);
                    }
                    $zoneUsage[$zoneKey] = true;
                }
            }
        }
    }

    private function syncEvents(Request $request, Tournament $tournament): void
    {
        $existing = $tournament->events()->get()->keyBy('id');
        $ids = $request->input('event_id', []);
        $dates = $request->input('event_date', []);
        $names = $request->input('event_name', []);
        $structures = $request->input('event_structure_id', []);

        $kept = [];

        $count = max(count($dates), count($ids));
        for ($i = 0; $i < $count; $i++) {
            $date = trim((string)($dates[$i] ?? ''));
            if ($date === '') {
                continue;
            }
            $name = trim((string)($names[$i] ?? '')) ?: 'Prova';
            $id = isset($ids[$i]) ? (int)$ids[$i] : 0;

            if ($id > 0 && $existing->has($id)) {
                $event = $existing->get($id);
                if ($event->is_locked) {
                    $kept[] = $id;
                    continue;
                }
                $structureId = $this->resolveEventStructureId($request, $tournament, $structures, $i);
                $event->update([
                    'event_date' => $date,
                    'name' => $name,
                    'structure_id' => $structureId,
                ]);
                $kept[] = $id;
            } else {
                $structureId = $this->resolveEventStructureId($request, $tournament, $structures, $i);
                $newEvent = $tournament->events()->create([
                    'event_date' => $date,
                    'name' => $name,
                    'structure_id' => $structureId,
                ]);
                $kept[] = $newEvent->id;
            }
        }

        foreach ($existing as $id => $event) {
            if (!in_array($id, $kept, true) && !$event->is_locked) {
                $event->delete();
            }
        }
    }

    private function resolveEventStructureId(Request $request, Tournament $tournament, array $structures, int $index): ?int
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            $structureId = (int)($structures[$index] ?? 0);
            if ($structureId > 0 && in_array($structureId, $this->availableStructureIds(), true)) {
                return $structureId;
            }

            return $tournament->structure_id;
        }

        return $tournament->structure_id;
    }

    /**
     * @return list<int>
     */
    private function availableStructureIds(): array
    {
        if ($this->structureIdCache === null) {
            $this->structureIdCache = Structure::pluck('id')->all();
        }

        return $this->structureIdCache;
    }

    /**
     * @return array<int, string>
     */
    private function parseParticipants(Request $request, string $field = 'participant_name'): array
    {
        $names = $request->input($field, []);
        $participants = [];
        foreach ($names as $nameRaw) {
            $name = trim((string)$nameRaw);
            if ($name === '') {
                continue;
            }
            $participants[] = $name;
        }

        $duplicates = collect($participants)
            ->map(fn ($name) => mb_strtolower($name, 'UTF-8'))
            ->duplicates()
            ->unique()
            ->all();
        if (!empty($duplicates)) {
            throw ValidationException::withMessages([
                'participants' => 'You cannot enter the same participant more than once: ' . implode(', ', $duplicates),
            ]);
        }

        return $participants;
    }

    /**
     * @return array<int, array{name:string, pegs:array<int|string>}>
     */
    private function parsePoolZones(Request $request, array $allowedPegs = []): array
    {
        $zoneNames = $request->input('pool_zone_name', []);
        $zonePegInputs = $request->input('pool_zone_pegs', []);

        $zones = [];
        $usedPegs = [];
        $allowedLookup = [];
        if (!empty($allowedPegs)) {
            foreach ($allowedPegs as $code) {
                $allowedLookup[$code] = true;
            }
        }

        foreach ($zoneNames as $index => $zoneRaw) {
            $name = trim((string)$zoneRaw);
            if ($name === '') {
                $name = 'Zona ' . ($index + 1);
            }

            $pegInput = (string)($zonePegInputs[$index] ?? '');
            $tokens = preg_split('/[\s,;]+/', $pegInput);
            $normalized = [];

            foreach ($tokens as $token) {
                $token = trim((string)$token);
                if ($token === '') {
                    continue;
                }
                if (!preg_match('/^[A-Za-z0-9]+$/', $token)) {
                    throw ValidationException::withMessages(['pool_pegs' => "Invalid value '$token' for zone '$name'. Use only letters and numbers."]);
                }
                $peg = strtoupper($token);
                if (!empty($allowedLookup) && !isset($allowedLookup[$peg])) {
                    throw ValidationException::withMessages([
                        'pool_pegs' => "Peg '$peg' is not available for this facility.",
                    ]);
                }
                if (in_array($peg, $usedPegs, true)) {
                    throw ValidationException::withMessages(['pool_pegs' => "Peg '$peg' is already present in another zone."]);
                }
                $normalized[] = $peg;
                $usedPegs[] = $peg;
            }

            if (!empty($normalized)) {
                sort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
                $zones[] = [
                    'name' => $name,
                    'pegs' => $normalized,
                ];
            }
        }

        if (empty($zones)) {
            throw ValidationException::withMessages(['pool_pegs' => 'Define at least one zone with valid pegs.']);
        }

        return $zones;
    }

    /**
     * @param array<int, array{name:string, pegs:array<int|string>}> $zones
     */
    private function countPoolPegEntries(array $zones): int
    {
        $count = 0;
        foreach ($zones as $zone) {
            $count += count($zone['pegs']);
        }
        return $count;
    }

    private function updateIndividualParticipants(Request $request, Tournament $tournament): void
    {
        $zones = $tournament->zones()->orderBy('start_number')->get();
        if ($zones->isEmpty()) {
            throw ValidationException::withMessages(['participants' => 'No zones are defined for this tournament.']);
        }

        $existingNames = $request->input('participant_existing', []);
        $absentInput = $request->input('participant_absent', []);

        $currentParticipants = $tournament->anglers()->with('team')->get();
        foreach ($currentParticipants as $participant) {
            $name = trim((string)($existingNames[$participant->id] ?? $participant->name));
            if ($name === '') {
                $name = $participant->name;
            }
            $participant->name = $name;
            $participant->is_absent = array_key_exists((string)$participant->id, $absentInput)
                || array_key_exists($participant->id, $absentInput);
            $participant->save();
        }

        $allowedPegCodes = $this->allowedPegCodesForTournament($tournament);
        $pegLookup = PegHelper::buildLookup($allowedPegCodes);
        $capacity = $zones->sum(fn ($zone) => $this->zonePegCount($zone, $allowedPegCodes, $pegLookup));
        $activeExisting = $tournament->anglers()->where('is_absent', false)->count();
        if ($capacity > 0 && $activeExisting > $capacity) {
            throw ValidationException::withMessages([
                'participants' => 'Active participants exceed the allowed limit after modifying the pegs.',
            ]);
        }

        $newParticipants = $this->parseParticipants($request, 'participant_new_name');
        if ($capacity > 0 && ($activeExisting + count($newParticipants) > $capacity)) {
            throw ValidationException::withMessages([
                'participants' => 'The number of active participants exceeds available pegs.',
            ]);
        }

        if (empty($newParticipants)) {
            return;
        }

        $team = $tournament->teams()->first();
        if ($team === null) {
            throw ValidationException::withMessages(['participants' => 'No group of participants associated with the tournament.']);
        }

        $zoneIds = $zones->pluck('id')->all();
        $zoneCount = count($zoneIds);
        $currentCount = $tournament->anglers()->count();

        foreach ($newParticipants as $index => $name) {
            $zoneId = $zoneIds[($currentCount + $index) % $zoneCount];
            $team->anglers()->create([
                'name' => $name,
                'zone_id' => $zoneId,
                'is_absent' => false,
            ]);
        }
    }

    /**
     * @param array<int, array{name:string,start_number:int,end_number:int}> $zones
     */
    private function validateTechnicalZones(array $zones, int $sectorSize, array $allowedPegCodes = []): void
    {
        if ($sectorSize <= 0) {
            return;
        }

        $lookup = PegHelper::buildLookup($allowedPegCodes);

        foreach ($zones as $index => $zone) {
            $length = PegHelper::rangeLength(
                (string) $zone['start_number'],
                (string) $zone['end_number'],
                $allowedPegCodes,
                $lookup
            );
            if ($length <= 0) {
                continue;
            }

            if ($length % $sectorSize === 0) {
                continue;
            }

            $hasFullAfter = false;
            for ($next = $index + 1; $next < count($zones); $next++) {
                $nextLength = PegHelper::rangeLength(
                    (string) $zones[$next]['start_number'],
                    (string) $zones[$next]['end_number'],
                    $allowedPegCodes,
                    $lookup
                );
                if ($nextLength > 0 && $nextLength % $sectorSize === 0) {
                    $hasFullAfter = true;
                    break;
                }
            }

            if (!$hasFullAfter) {
                throw ValidationException::withMessages([
                    'zones' => "Technical zone '{$zone['name']}' must precede a complete zone to respect the {$sectorSize} sectors.",
                ]);
            }
        }
    }

    private function participantSuggestions(): array
    {
        return ParticipantDirectory::whereNull('anonymized_at')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function updatePoolParticipants(Request $request, Tournament $tournament): void
    {
        $existingNames = $request->input('participant_existing', []);
        $absentInput = $request->input('participant_absent', []);

        $currentParticipants = $tournament->anglers()->with('team')->get();
        $absentIds = [];
        foreach ($currentParticipants as $participant) {
            $name = trim((string)($existingNames[$participant->id] ?? $participant->name));
            if ($name === '') {
                $name = $participant->name;
            }
            $participant->name = $name;
            $participant->is_absent = array_key_exists((string)$participant->id, $absentInput)
                || array_key_exists($participant->id, $absentInput);
            if ($participant->is_absent) {
                $absentIds[] = $participant->id;
            }
            $participant->save();
        }

        if (!empty($absentIds)) {
            $tournament->anglers()->whereIn('anglers.id', $absentIds)->delete();
        }

        // ricarica i partecipanti attivi dopo eventuali cancellazioni
        $tournament->unsetRelation('anglers');

        $pegCount = $tournament->poolPegs()->count();
        $capacityLimit = $tournament->pool_capacity ?? 0;
        $effectiveCapacity = $capacityLimit > 0
            ? ($pegCount > 0 ? min($capacityLimit, $pegCount) : $capacityLimit)
            : $pegCount;
        if ($effectiveCapacity <= 0) {
            throw ValidationException::withMessages(['participants' => 'No peg available for this pool tournament.']);
        }

        $activeExisting = $tournament->anglers()->where('is_absent', false)->count();
        $newParticipants = $this->parseParticipants($request, 'participant_new_name');
        if ($activeExisting + count($newParticipants) > $effectiveCapacity) {
            throw ValidationException::withMessages([
                'participants' => 'The number of participants exceeds the limit allowed by available pegs.',
            ]);
        }

        if (!empty($newParticipants)) {
            $team = $tournament->teams()->first();
            if ($team === null) {
                throw ValidationException::withMessages(['participants' => 'No group of participants associated with the tournament.']);
            }

            foreach ($newParticipants as $name) {
                $team->anglers()->create([
                    'name' => $name,
                    'zone_id' => null,
                    'is_absent' => false,
                ]);
            }
        }
    }

    private function syncPoolPegs(Request $request, Tournament $tournament, ?array $allowedPegsOverride = null, ?int $capacityLimit = null): void
    {
        $allowedPegs = $allowedPegsOverride ?? $this->allowedPegCodesForTournament($tournament);
        $zonesData = $this->parsePoolZones($request, $allowedPegs);
        $pegCount = $this->countPoolPegEntries($zonesData);

        if ($capacityLimit !== null && $capacityLimit > 0 && $pegCount > $capacityLimit) {
            throw ValidationException::withMessages([
                'pool_pegs' => "You specified {$capacityLimit} participants: you cannot enter more than {$capacityLimit} pegs (currently {$pegCount}).",
            ]);
        }

        $tournament->poolPegs()->delete();

        foreach ($zonesData as $zoneData) {
            foreach ($zoneData['pegs'] as $peg) {
                $tournament->poolPegs()->create([
                    'peg_number' => $peg,
                    'zone_name' => $zoneData['name'],
                ]);
            }
        }
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
