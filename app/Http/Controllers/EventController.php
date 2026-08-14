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
use App\Models\Draw;
use App\Models\FishingEvent;
use App\Models\MeteosatJob;
use App\Models\MeteosatReport;
use App\Models\Zone;
use App\Support\PegHelper;
use App\Services\MeteostatClient;
use App\Services\ParticipantDirectoryService;
use App\Services\SecureActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(
        private readonly ParticipantDirectoryService $directoryService,
        private readonly SecureActionService $secureActionService,
    )
        {
    }

    private function parsePegNumber(string $peg): int
    {
        if (preg_match('/(\d+)$/', $peg, $match)) {
            return (int) $match[1];
        }
        return 0;
    }

    /**
     * @return list<string>
     */
    private function getAllowedPegCodesForTournament(?\App\Models\Tournament $tournament): array
    {
        if (!$tournament) {
            return [];
        }

        $id = (int) $tournament->id;
        if (!isset($this->allowedPegCache[$id])) {
            $this->allowedPegCache[$id] = PegHelper::allowedCodesForTournament($tournament);
        }

        return $this->allowedPegCache[$id];
    }

    /**
     * @param \App\Models\Tournament|null $tournament
     * @return array<string, int>
     */
    private function getPegLookupForTournament(?\App\Models\Tournament $tournament): array
    {
        if (!$tournament) {
            return [];
        }

        $id = (int) $tournament->id;
        if (!isset($this->pegLookupCache[$id])) {
            $codes = $this->getAllowedPegCodesForTournament($tournament);
            $this->pegLookupCache[$id] = PegHelper::buildLookup($codes);
        }

        return $this->pegLookupCache[$id];
    }

    /**
     * @param array<int, string> $allowedPegs
     * @param array<string,int> $lookup
     * @return list<string>
     */
    private function pegCodesForZone(Zone $zone, array $allowedPegs, array $lookup): array
    {
        return PegHelper::sliceRange(
            (string) $zone->start_number,
            (string) $zone->end_number,
            $allowedPegs,
            $lookup
        );
    }

    /**
     * Cache dei picchetti di bordo (primo/ultimo) per torneo, per evitare query ripetute.
     *
     * @var array<int, array<int, string>>
     */
    private array $edgePegCache = [];
    /**
     * @var array<int, array<int, string>>
     */
    private array $allowedPegCache = [];
    /**
     * @var array<int, array<string, int>>
     */
    private array $pegLookupCache = [];

    public function show(FishingEvent $event): View
    {
        $this->authorizeEventAccess($event);
        $event->load(['tournament.structure', 'structure', 'meteosatReport']);

        $sectorSize = max(1, (int)($event->tournament->sector_size ?? 5));

        $drawsQuery = $event->draws()
            ->with(['angler.team', 'angler.zone', 'frozenZone'])
            ->orderBy('frozen_zone_name')
            ->orderBy('peg_number')
            ->get();
        $draws = $drawsQuery->map(fn (Draw $draw) => $this->formatDrawRow($draw, $sectorSize, $event->tournament));

        return view('events.show', [
            'event' => $event,
            'draws' => $draws,
            'pegOverview' => $this->buildPegOverview($event),
            'assignedPegNumbers' => $draws->pluck('peg_number')->map(fn ($peg) => strtoupper((string) $peg))->all(),
            'showTeamColumn' => $event->tournament?->isTeam() ?? false,
            'isPoolEvent' => $event->tournament?->isPool() ?? false,
        ]);
    }

    public function generateDraw(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        if ($event->is_locked) {
            return back()->withErrors(['general' => 'This event is consolidated and cannot be modified.']);
        }

        try {
            $this->buildDraw($event);
        } catch (ValidationException $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        } catch (\Throwable $throwable) {
            report($throwable);
            return back()->withErrors(['general' => "Error during draw generation."]);
        }

        return redirect()->route('events.show', $event)->with('status', 'Draw updated successfully.');
    }

    public function consolidate(FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        if ($event->draws()->count() === 0) {
            return back()->withErrors(['general' => 'There is no draw to consolidate for this event.']);
        }

        $event->update(['is_locked' => true]);
        $this->syncParticipantDirectory($event);
        $this->scheduleMeteosatJob($event);

        return redirect()->route('events.show', $event)->with('status', 'Draw consolidated.');
    }

    public function updateDraws(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        if ($event->is_locked) {
            return back()->withErrors(['general' => 'This draw is consolidated and cannot be modified.']);
        }

        $data = $request->validate([
            'draws' => ['required', 'array'],
            'draws.*' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $normalizedDraws = [];
        foreach ($data['draws'] as $drawId => $pegNumber) {
            $normalizedDraws[$drawId] = strtoupper($pegNumber);
        }

        $drawIds = array_keys($normalizedDraws);
        $values = array_values($normalizedDraws);

        if (count($values) !== count(array_unique($values))) {
            return back()->withErrors(['general' => 'Each peg can be assigned to only one participant.'])->withInput();
        }

        $existingDraws = $event->draws()->whereIn('id', $drawIds)->get()->keyBy('id');

        foreach ($normalizedDraws as $drawId => $pegNumber) {
            if ($existingDraws->has((int) $drawId)) {
                $existingDraws->get((int) $drawId)->update([
                    'peg_number' => $pegNumber,
                ]);
            }
        }

        return back()->with('status', 'Pegs updated manually.');
    }

    public function destroy(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        $data = $request->validate([
            'otp_code' => ['required', 'digits:6'],
        ]);
        $this->secureActionService->validate(
            $request->user(),
            $this->deleteActionKey($event),
            $data['otp_code']
        );

        // Elimina eventuali dati Meteosat associati all'evento
        MeteosatReport::where('fishing_event_id', $event->id)->delete();
        MeteosatJob::where('fishing_event_id', $event->id)->delete();

        $event->draws()->delete();
        $event->weightEntries()->delete();
        $event->zoneLocks()->delete();
        $event->delete();

        return redirect()->route('weights.index')->with('status', 'Event deleted successfully.');
    }

    public function requestDeleteOtp(Request $request, FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);

        $this->secureActionService->issue(
            $request->user(),
            $this->deleteActionKey($event),
            [
                'label' => "eliminare la prova {$event->name} ({$event->event_date?->format('d/m/Y')})",
                'event_id' => $event->id,
            ]
        );

        return back()->with('status', 'OTP sent via email. Enter it to confirm deletion.');
    }

    public function unlock(FishingEvent $event): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        if (!$event->is_locked) {
            return back()->with('status', 'The draw is already unlocked.');
        }

        $event->update(['is_locked' => false]);

        return back()->with('status', 'Draw unlocked and now editable.');
    }

    public function fetchMeteosat(Request $request, FishingEvent $event, MeteostatClient $meteostatClient): RedirectResponse
    {
        $this->authorizeEventAccess($event);
        $user = $request->user();
        if (!$user?->hasRole(\App\Models\User::ROLE_ADMIN)) {
            abort(403);
        }

        if (!$event->tournament->isPool()) {
            return back()->withErrors(['general' => 'This feature is only available for pools.']);
        }
        if (!$event->is_locked) {
            return back()->withErrors(['general' => 'Retrieve Meteosat data after the event is closed.']);
        }
        if ($event->meteosatReport) {
            return back()->with('status', 'Meteosat data has already been acquired for this event.');
        }

        $structure = $event->structure ?? $event->tournament->structure;
        if (
            !$structure
            || !$structure->comune
            || $structure->latitude === null
            || $structure->longitude === null
        ) {
            return back()->withErrors(['general' => 'Unable to retrieve Meteosat data: missing facility, municipality or coordinates.']);
        }

        $timezone = config('app.timezone', 'UTC');
        $eventDate = $event->event_date
            ? $event->event_date->clone()->setTimezone($timezone)
            : Carbon::now($timezone);
        $endLocal = $eventDate->copy()->setTime(15, 0, 0);
        $startLocal = $eventDate->copy()->subDays(3)->startOfDay();

        try {
            $payload = $meteostatClient->fetchHourlySeries(
                $structure->comune,
                (float) $structure->latitude,
                (float) $structure->longitude,
                $structure->altitude,
                $startLocal->copy(),
                $endLocal->copy()
            );
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['general' => 'Error retrieving Meteosat data: ' . $exception->getMessage()]);
        }

        MeteosatReport::create([
            'fishing_event_id' => $event->id,
            'tournament_id' => $event->tournament_id,
            'structure_id' => $structure->id,
            'comune' => $structure->comune,
            'start_at' => $startLocal->copy()->setTimezone('UTC'),
            'end_at' => $endLocal->copy()->setTimezone('UTC'),
            'payload' => $payload,
        ]);

        return back()->with('status', 'Meteosat data registered successfully.');
    }

    private function buildDraw(FishingEvent $event): void
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
            /** @var Zone|null $zone */
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
            /** @var Zone $zone */
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
                        /** @var Angler $angler */
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

        // se i picchetti superano i partecipanti, limita ai più "bassi" eliminando gli ultimi a destra
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
                    /** @var Angler $angler */
                    $angler = $row['angler'];
                    /** @var Zone $zone */
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

    /**
     * @return array{zone_name:?string, sector_label:?string, sector_start:?int, sector_end:?int, peg_number:string, angler_name:string, team_name:string}
     */
    private function formatDrawRow(Draw $draw, int $sectorSize, ?\App\Models\Tournament $tournament = null): array
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

    /**
     * @return array<int, array{label:string, pegs:array<int|string>}>
     */
    private function buildPegOverview(FishingEvent $event): array
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
                    /** @var Angler $angler */
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

    /**
     * @param int|string $firstPeg
     * @param int|string $lastPeg
     * @return array<int,string>
     */
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

    private function authorizeEventAccess(FishingEvent $event): void
    {
        $event->loadMissing('tournament');
        $user = auth()->user();
        if ($user?->hasRole(\App\Models\User::ROLE_ADMIN)) {
            return;
        }

        $structure = $user?->assignedStructure();
        if (!$structure || $event->tournament?->structure_id !== $structure->id) {
            abort(403, 'You do not have access to this event.');
        }
    }

    private function deleteActionKey(FishingEvent $event): string
    {
        return 'events.delete:' . $event->id;
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

        // Se si consolida dopo l'orario previsto, programma al più presto
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
