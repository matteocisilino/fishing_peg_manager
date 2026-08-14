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

namespace App\Services\Weights;

use App\Models\FishingEvent;
use App\Services\Tournaments\TournamentStandingsCalculator;

class EventResultsPdfDataBuilder
{
    public function __construct(
        private readonly PlacementCalculator $placementCalculator,
        private readonly TournamentStandingsCalculator $tournamentStandingsCalculator,
    ) {
    }

    /**
     * @return array{
     *     placement: array,
     *     zones: array,
     *     absolute_max: ?float,
     *     day_entries: array<int,array{zone:string,row:array}>,
     *     tournament_standings: \Illuminate\Support\Collection,
     *     tournament_events: \Illuminate\Support\Collection
     * }
     */
    public function build(FishingEvent $event): array
    {
        $event->loadMissing([
            'tournament',
            'draws.angler.team',
            'draws.angler.zone',
            'weightEntries',
        ]);

        $placementData = $this->placementCalculator->calculate($event);
        $tournamentData = $this->tournamentStandingsForEvent($event);

        return [
            'placement' => $placementData,
            'zones' => $placementData['zones'],
            'absolute_max' => $placementData['absolute_max'],
            'day_entries' => $this->flattenZoneEntries($placementData['zones'], true, $placementData['absolute_max']),
            'tournament_standings' => $tournamentData['standings'],
            'tournament_events' => $tournamentData['events'],
        ];
    }

    private function tournamentStandingsForEvent(FishingEvent $event): array
    {
        $tournament = $event->tournament;
        $tournament->loadMissing([
            'events.draws.angler.team',
            'events.weightEntries',
        ]);

        $orderedEvents = $tournament->events
            ->sortBy(fn($item) => [$item->event_date?->timestamp ?? PHP_INT_MAX, $item->id])
            ->values();

        $allowedIds = [];
        foreach ($orderedEvents as $candidate) {
            $allowedIds[] = $candidate->id;
            if ($candidate->id === $event->id) {
                break;
            }
        }

        $filter = static fn($candidate) => in_array($candidate->id, $allowedIds, true);

        return $this->tournamentStandingsCalculator->calculate($tournament, false, $filter);
    }

    private function flattenZoneEntries(array $zones, bool $sortByWeight = false, ?float $absoluteMax = null): array
    {
        $entries = [];
        foreach ($zones as $zoneName => $rows) {
            foreach ($rows as $row) {
                if (!empty($row['placeholder'])) {
                    continue;
                }
                $entries[] = [
                    'zone' => $zoneName,
                    'row' => array_merge($row, [
                        'is_absolute' => ($absoluteMax !== null && isset($row['weight']) && abs((float)$row['weight'] - $absoluteMax) < 0.000001)
                            ? true
                            : ($row['is_absolute'] ?? false),
                    ]),
                ];
            }
        }

        if ($sortByWeight) {
            usort($entries, static fn($a, $b) => $b['row']['weight'] <=> $a['row']['weight']);
        }

        return $entries;
    }
}
