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

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Services\Weights\PlacementCalculator;
use Illuminate\Support\Collection;

class TournamentStandingsCalculator
{
    public function __construct(
        private readonly PlacementCalculator $placementCalculator,
    ) {
    }

    /**
     * @param  callable(\App\Models\FishingEvent):bool|null  $eventFilter
     * @return array{complete:bool, events:Collection, standings:Collection}
     */
    public function calculate(Tournament $tournament, bool $requireComplete = true, ?callable $eventFilter = null): array
    {
        $events = $tournament->events ?? collect();
        if ($events->isEmpty()) {
            return [
                'complete' => false,
                'events' => collect(),
                'standings' => collect(),
            ];
        }

        $events = $events
            ->sortBy(fn($event) => [$event->event_date?->timestamp ?? PHP_INT_MAX, $event->id])
            ->values();

        if ($eventFilter !== null) {
            $events = $events->filter($eventFilter)->values();
        }

        if ($events->isEmpty()) {
            return [
                'complete' => false,
                'events' => collect(),
                'standings' => collect(),
            ];
        }

        $competitors = [];

        foreach ($events as $event) {
            $placementData = $this->placementCalculator->calculate($event);
            if (!$placementData['complete']) {
                if ($requireComplete) {
                    return [
                        'complete' => false,
                        'events' => $events,
                        'standings' => collect(),
                    ];
                }
                continue;
            }

            $drawsByAngler = $event->draws->keyBy('angler_id');
            $weightsMap = $event->weightEntries->keyBy('angler_id');

            foreach ($placementData['placements'] as $anglerId => $placement) {
                $draw = $drawsByAngler->get($anglerId);
                $name = $draw?->frozen_angler_name ?? optional($draw?->angler)->name ?? '—';
                $teamName = null;
                if ($tournament->isTeam()) {
                    $teamName = $draw?->frozen_team_name ?? optional(optional($draw?->angler)->team)->name ?? '-';
                }

                if (!isset($competitors[$anglerId])) {
                    $competitors[$anglerId] = [
                        'id' => $anglerId,
                        'name' => $name,
                        'team' => $teamName,
                        'penalties' => [],
                        'total' => 0,
                        'weight_total' => 0.0,
                        'has_absolute' => false,
                    ];
                }

                $competitors[$anglerId]['penalties'][$event->id] = [
                    'pos' => $placement['position'],
                    'tech' => (bool)($placement['is_technical'] ?? false),
                ];
                $competitors[$anglerId]['total'] += $placement['position'];
                $weightEntry = $weightsMap->get($anglerId);
                if ($weightEntry !== null && $weightEntry->weight_value !== null) {
                    $competitors[$anglerId]['weight_total'] += (float) $weightEntry->weight_value;
                }
            }
        }

        if (empty($competitors)) {
            return [
                'complete' => false,
                'events' => $events,
                'standings' => collect(),
            ];
        }

        // assegna l'assoluto solo a chi ha il peso totale maggiore (ex aequo inclusi)
        $maxTotalWeight = collect($competitors)->max('weight_total');

        foreach ($competitors as &$competitor) {
            $competitor['has_absolute'] = $maxTotalWeight !== null
                && abs($competitor['weight_total'] - $maxTotalWeight) < 0.000001;
        }
        unset($competitor);

        $eventOrder = $events->pluck('id')->all();

        $standings = collect($competitors)
            ->map(function (array $row) use ($eventOrder) {
                $series = [];
                foreach ($eventOrder as $eventId) {
                    $penalty = $row['penalties'][$eventId] ?? null;
                    if (is_array($penalty)) {
                        $series[] = $penalty['pos'];
                    } else {
                        $series[] = $penalty;
                    }
                }
                $display = array_map(
                    static function ($value, $index) use ($row, $eventOrder) {
                        if ($value === null) {
                            return '-';
                        }
                        $penalty = $row['penalties'][$eventOrder[$index]] ?? null;
                        $suffix = (is_array($penalty) && !empty($penalty['tech'])) ? 'T' : '';
                        return (string)$value . $suffix;
                    },
                    $series,
                    array_keys($series)
                );

                $row['penalties_series'] = $series;
                $row['penalties_display'] = implode(' - ', $display);
                $row['weight_total_display'] = number_format($row['weight_total'], 0, ',', '.');

                return $row;
            })
            ->sortBy([
                ['total', 'asc'],
                ['name', 'asc'],
            ])
            ->values()
            ->map(function (array $row, int $index) {
                $row['position'] = $index + 1;
                return $row;
            });

        return [
            'complete' => true,
            'events' => $events,
            'standings' => $standings,
        ];
    }
}
