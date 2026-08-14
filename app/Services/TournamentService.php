<?php

namespace App\Services;

use App\Models\Angler;
use App\Models\ParticipantDirectory;
use App\Models\Structure;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\Zone;
use App\Support\PegHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TournamentService
{
    private ?array $structureIdCache = null;

    public function createTeamTournament(array $data, int $structureId, array $lakeSelection, array $allowedPegCodes, array $zoneInputs): Tournament
    {
        return DB::transaction(function () use ($data, $structureId, $lakeSelection, $allowedPegCodes, $zoneInputs) {
            $tournament = Tournament::create([
                'name' => trim((string)($data['t_name'] ?? '')),
                'sector_size' => (int)($data['sector_size'] ?? 5),
                'tournament_type' => Tournament::TYPE_TEAM,
                'team_size' => (int)($data['team_size'] ?? 1),
                'allow_edge_repeats' => (bool)($data['allow_edge_repeats'] ?? false),
                'structure_id' => $structureId,
            ]);

            $tournament->lakes()->sync($lakeSelection);

            foreach ($zoneInputs as $zoneData) {
                $tournament->zones()->create($zoneData);
            }

            $zonesMap = $tournament->zones()->pluck('id', 'name')->all();

            $this->storeTeams($data, $tournament, $zonesMap, (int)($data['team_size'] ?? 1));
            $this->storeEvents($data, $tournament);

            return $tournament;
        });
    }

    public function createIndividualTournament(array $data, int $structureId, array $lakeSelection, array $allowedPegCodes, array $zoneInputs, array $participants): Tournament
    {
        return DB::transaction(function () use ($data, $structureId, $lakeSelection, $allowedPegCodes, $zoneInputs, $participants) {
            $sectorSize = (int)($data['sector_size'] ?? 5);
            $tournament = Tournament::create([
                'name' => trim((string)($data['t_name'] ?? '')),
                'sector_size' => $sectorSize,
                'tournament_type' => Tournament::TYPE_INDIVIDUAL,
                'allow_edge_repeats' => (bool)($data['allow_edge_repeats'] ?? false),
                'structure_id' => $structureId,
            ]);

            $tournament->lakes()->sync($lakeSelection);

            foreach ($zoneInputs as $zoneData) {
                $tournament->zones()->create($zoneData);
            }

            $zones = $tournament->zones()->orderBy('start_number')->get();
            $pegLookup = PegHelper::buildLookup($allowedPegCodes);
            $maxSlots = $zones->sum(fn ($zone) => $this->zonePegCount($zone, $allowedPegCodes, $pegLookup));
            if ($maxSlots <= 0) {
                throw ValidationException::withMessages(['participants' => 'No pegs available to assign participants.']);
            }

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

            $this->storeEvents($data, $tournament);

            return $tournament;
        });
    }

    public function createPoolTournament(array $data, int $structureId, array $lakeSelection, array $allowedPegs, array $zonesData): Tournament
    {
        return DB::transaction(function () use ($data, $structureId, $lakeSelection, $allowedPegs, $zonesData) {
            $capacity = (int)($data['pool_capacity'] ?? 0);
            $tournament = Tournament::create([
                'name' => trim((string)($data['t_name'] ?? '')),
                'sector_size' => 0,
                'tournament_type' => Tournament::TYPE_POOL,
                'allow_edge_repeats' => true,
                'structure_id' => $structureId,
                'pool_capacity' => $capacity,
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

            $pegNumbersCount = $this->countPoolPegEntries($zonesData);
            $effectiveCapacity = $capacity > 0
                ? ($pegNumbersCount > 0 ? min($capacity, $pegNumbersCount) : $capacity)
                : $pegNumbersCount;

            $participants = $this->parseParticipants($data, 'participant_name');
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
                ]);
            }

            $this->storeEvents($data, $tournament);

            return $tournament;
        });
    }

    public function updateTournament(Tournament $tournament, array $data, int $structureId, array $lakeSelection, Structure $structure): void
    {
        DB::transaction(function () use ($tournament, $data, $structureId, $lakeSelection, $structure) {
            $payload = [
                'name' => trim((string)($data['t_name'] ?? '')),
            ];
            if (!$tournament->isPool()) {
                $payload['sector_size'] = (int)($data['sector_size'] ?? 5);
            }
            if (!$tournament->isPool()) {
                $payload['allow_edge_repeats'] = (bool)($data['allow_edge_repeats'] ?? $tournament->allow_edge_repeats);
            }
            if ($tournament->isTeam()) {
                $payload['team_size'] = (int)($data['team_size'] ?? 1);
            }
            if ($tournament->isPool()) {
                $payload['pool_capacity'] = (int)($data['pool_capacity'] ?? 0);
            }
            $payload['structure_id'] = $structureId;
            $tournament->update($payload);
            $tournament->lakes()->sync($lakeSelection);

            if ($tournament->isTeam()) {
                $zonesByName = $tournament->zones()->get()->keyBy('name');

                foreach ($data['team_name_edit'] ?? [] as $teamId => $teamName) {
                    $teamName = trim((string)$teamName);
                    if ($teamName === '') {
                        continue;
                    }
                    Team::whereKey((int)$teamId)
                        ->where('tournament_id', $tournament->id)
                        ->update(['name' => $teamName]);
                }

                $this->updateAnglers($data, $tournament, $zonesByName);
            } elseif ($tournament->isPool()) {
                $allowedPegs = $this->pegCodesForLakeSelection($structure, $lakeSelection);
                $capacity = (int)($data['pool_capacity'] ?? 0);
                if (!empty($allowedPegs) && $capacity > count($allowedPegs)) {
                    throw ValidationException::withMessages([
                        'pool_capacity' => "The maximum number of participants ({$capacity}) exceeds the " . count($allowedPegs) . " available pegs.\nA) Add another lake if available.\nB) Reduce the maximum number of participants because the selected lakes do not allow it.",
                    ]);
                }

                $this->syncPoolPegs($data, $tournament, $allowedPegs, $capacity);
                $this->updatePoolParticipants($data, $tournament);
            } else {
                $this->updateIndividualParticipants($data, $tournament);
            }

            $this->syncEvents($data, $tournament);
        });
    }

    public function structureFormData(?Tournament $tournament = null): array
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

    public function structureLakeMap(Collection $structures, ?Structure $defaultStructure): array
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

    public function structureLakeSelections(Collection $structures, ?Structure $defaultStructure, ?Tournament $tournament = null): array
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

    public function describeStructureLakes(Structure $structure): array
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

    public function pegCodesForLakeSelection(Structure $structure, array $lakeIds): array
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

    public function resolveStructureLakeSelection(array $lakeIds, int $structureId, ?Structure $structure = null): array
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

        $normalized = [];
        foreach ($lakeIds as $value) {
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

    public function determineStructureId($user, ?int $requestStructureId, ?Tournament $tournament = null): int
    {
        if ($user?->isSuperAdmin()) {
            $structureId = (int)($requestStructureId ?: $tournament?->structure_id);
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

    public function parseZones(array $zoneNames, array $zoneStarts, array $zoneEnds, $selectedTechnical, array $allowedPegCodes = []): array
    {
        $zones = [];
        $seen = [];
        $allowedLookup = PegHelper::buildLookup($allowedPegCodes);
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

    public function zonePegCount(Zone|array $zone, array $allowedPegCodes, array $lookup = []): int
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

    public function parseParticipants(array $data, string $field = 'participant_name'): array
    {
        $names = $data[$field] ?? [];
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

    public function parsePoolZones(array $data, array $allowedPegs = []): array
    {
        $zoneNames = $data['pool_zone_name'] ?? [];
        $zonePegInputs = $data['pool_zone_pegs'] ?? [];

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

    public function countPoolPegEntries(array $zones): int
    {
        $count = 0;
        foreach ($zones as $zone) {
            $count += count($zone['pegs']);
        }
        return $count;
    }

    public function validateTechnicalZones(array $zones, int $sectorSize, array $allowedPegCodes = []): void
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

    public function participantSuggestions(): array
    {
        return ParticipantDirectory::whereNull('anonymized_at')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public function allowedPegCodesForTournament(Tournament $tournament): array
    {
        return PegHelper::allowedCodesForTournament($tournament);
    }

    private function storeTeams(array $data, Tournament $tournament, array $zonesMap, int $teamSize): void
    {
        $teamNames = $data['team_name'] ?? [];
        $memberNames = $data['member_name'] ?? [];
        $memberZones = $data['member_zone'] ?? [];

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

    private function storeEvents(array $data, Tournament $tournament): void
    {
        $dates = $data['event_date'] ?? [];
        $names = $data['event_name'] ?? [];
        $structures = $data['event_structure_id'] ?? [];

        foreach ($dates as $index => $dateRaw) {
            $date = trim((string)$dateRaw);
            if ($date === '') {
                continue;
            }
            $name = trim((string)($names[$index] ?? '')) ?: 'Prova ' . ($index + 1);
            $structureId = $this->resolveEventStructureId($tournament, $structures, $index);

            $tournament->events()->create([
                'event_date' => $date,
                'name' => $name,
                'structure_id' => $structureId,
            ]);
        }
    }

    private function updateAnglers(array $data, Tournament $tournament, Collection $zonesByName): void
    {
        $names = $data['angler_name'] ?? [];
        $zones = $data['angler_zone'] ?? [];
        $teamSize = (int)$tournament->team_size;

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

        $newMembersInput = $data['team_new_member'] ?? [];
        $newZonesInput = $data['team_new_zone'] ?? [];

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

    private function syncEvents(array $data, Tournament $tournament): void
    {
        $existing = $tournament->events()->get()->keyBy('id');
        $ids = $data['event_id'] ?? [];
        $dates = $data['event_date'] ?? [];
        $names = $data['event_name'] ?? [];
        $structures = $data['event_structure_id'] ?? [];

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
                $structureId = $this->resolveEventStructureId($tournament, $structures, $i);
                $event->update([
                    'event_date' => $date,
                    'name' => $name,
                    'structure_id' => $structureId,
                ]);
                $kept[] = $id;
            } else {
                $structureId = $this->resolveEventStructureId($tournament, $structures, $i);
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

    private function resolveEventStructureId(Tournament $tournament, array $structures, int $index): ?int
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            $structureId = (int)($structures[$index] ?? 0);
            if ($structureId > 0 && in_array($structureId, $this->availableStructureIds(), true)) {
                return $structureId;
            }

            return $tournament->structure_id;
        }

        return $tournament->structure_id;
    }

    private function availableStructureIds(): array
    {
        if ($this->structureIdCache === null) {
            $this->structureIdCache = Structure::pluck('id')->all();
        }

        return $this->structureIdCache;
    }

    private function updateIndividualParticipants(array $data, Tournament $tournament): void
    {
        $zones = $tournament->zones()->orderBy('start_number')->get();
        if ($zones->isEmpty()) {
            throw ValidationException::withMessages(['participants' => 'No zones are defined for this tournament.']);
        }

        $existingNames = $data['participant_existing'] ?? [];
        $absentInput = $data['participant_absent'] ?? [];

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

        $newParticipants = $this->parseParticipants($data, 'participant_new_name');
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

    private function updatePoolParticipants(array $data, Tournament $tournament): void
    {
        $existingNames = $data['participant_existing'] ?? [];
        $absentInput = $data['participant_absent'] ?? [];

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
        $newParticipants = $this->parseParticipants($data, 'participant_new_name');
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

    private function syncPoolPegs(array $data, Tournament $tournament, ?array $allowedPegsOverride = null, ?int $capacityLimit = null): void
    {
        $allowedPegs = $allowedPegsOverride ?? $this->allowedPegCodesForTournament($tournament);
        $zonesData = $this->parsePoolZones($data, $allowedPegs);
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
}
