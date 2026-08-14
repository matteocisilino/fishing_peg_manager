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

class PlacementCalculator
{
    private function parsePegNumber(string $value): int
    {
        if (preg_match('/(\d+)$/', $value, $match)) {
            return (int) $match[1];
        }
        return 0;
    }

    /**
     * @return array{complete:bool, placements:array<int,array{position:float,zone:string,is_absolute:bool,is_technical:bool}>, zones:array<string,array<int,array{position:float,weight:float,draw:\App\Models\Draw,is_absolute:bool,is_technical:bool}>>, absolute_max:?float}
     */
    public function calculate(FishingEvent $event): array
    {
        $draws = $event->draws;
        $weightMap = $event->weightEntries->keyBy('angler_id');
        $zonesMeta = $event->tournament?->zones()->orderBy('name')->get() ?? collect();
        $zoneOrder = $zonesMeta->pluck('name')->values()->all();
        $zoneTechnicalMap = $zonesMeta
            ->mapWithKeys(fn ($zone) => [strtoupper($zone->name) => (bool)$zone->is_technical])
            ->all();

        if ($draws->isEmpty()) {
            return [
                'complete' => false,
                'placements' => [],
                'zones' => [],
                'absolute_max' => null,
            ];
        }

        $complete = true;
        $zonesBuckets = [];
        $absoluteMax = null;
        $absoluteAnglers = [];

        foreach ($draws as $draw) {
            if ($draw->angler_id === null) {
                $complete = false;
                break;
            }
            $entry = $weightMap->get($draw->angler_id);
            if ($entry === null || $entry->weight_value === null) {
                $complete = false;
                break;
            }
            $weight = (float) $entry->weight_value;
            $zoneLabel = $draw->frozen_zone_name
                ?? optional($draw->angler->zone)->name
                ?? 'Zona';
            $zonesBuckets[$zoneLabel][] = [
                'draw' => $draw,
                'weight' => $weight,
            ];
            if ($absoluteMax === null || $weight > $absoluteMax) {
                $absoluteMax = $weight;
                $absoluteAnglers = [$draw->angler_id];
            } elseif ($weight === $absoluteMax) {
                $absoluteAnglers[] = $draw->angler_id;
            }
        }

        if (!$complete) {
            return [
                'complete' => false,
                'placements' => [],
                'zones' => [],
                'absolute_max' => null,
            ];
        }

        $placements = [];
        $zones = [];
        $baseSectorSize = max(1, (int)($event->tournament->sector_size ?? 5));
        $lastSectorSnapshot = [];

        ksort($zonesBuckets);

        foreach ($zonesBuckets as $zoneName => $entries) {
            $sectorSize = ($event->tournament?->isPool() ?? false) && ($event->tournament?->sector_size ?? 0) <= 0
                ? max(1, count($entries))
                : $baseSectorSize;
            $isTechnicalZone = $zoneTechnicalMap[strtoupper($zoneName)] ?? false;
            $currentZoneIndex = array_search($zoneName, $zoneOrder, true);
            $previousZoneName = ($currentZoneIndex !== false && $currentZoneIndex > 0) ? $zoneOrder[$currentZoneIndex - 1] : null;

            // ordina i picchetti per numero, mantenendo i prefissi
            usort($entries, fn($a, $b) => $this->parsePegNumber($a['draw']->peg_number) <=> $this->parsePegNumber($b['draw']->peg_number));

            // suddivide la zona in settori di dimensione fissa; l'ultimo blocco incompleto è il tecnico
            $sectors = array_chunk($entries, $sectorSize);

            foreach ($sectors as $sectorIndex => $sectorEntries) {
                // ordinamento per peso decrescente dentro il settore
                usort($sectorEntries, fn($a, $b) => $b['weight'] <=> $a['weight']);

                $count = count($sectorEntries);
                // penalità settore tecnico: aggiungi posti mancanti in testa per simulare settore pieno
                $missing = max(0, $sectorSize - $count);
                $currentPosition = 1;
                $isTechnical = $missing > 0;

                $index = 0;
                $labelBase = $isTechnicalZone && $previousZoneName ? $previousZoneName : $zoneName;
                $sectorLabel = $labelBase . ' (Settore ' . ($sectorIndex + 1) . ($isTechnical ? ' T' : '') . ')';

                // prepara il settore completo includendo le repliche dal settore precedente (per la penalità tecnica)
                $filler = [];
                if ($missing > 0) {
                    $filler = array_slice($lastSectorSnapshot, 0, $missing);
                }
                $sectorForRanking = array_merge($filler, $sectorEntries);
                // ordina per peso decrescente tenendo conto dei filler
                usort($sectorForRanking, fn($a, $b) => $b['weight'] <=> $a['weight']);

                $processed = count($sectorForRanking);
                while ($index < $processed) {
                    $groupWeight = $sectorForRanking[$index]['weight'];
                    $group = [];
                    while ($index < $processed && abs($sectorForRanking[$index]['weight'] - $groupWeight) < 0.000001) {
                        $group[] = $sectorForRanking[$index];
                        $index++;
                    }

                    $groupSize = count($group);
                    $assignedPosition = $currentPosition + ($groupSize - 1) / 2;

                    foreach ($group as $entry) {
                        $draw = $entry['draw'] ?? null;
                        $anglerId = $draw?->angler_id;
                        $isPlaceholder = in_array($entry, $filler, true);
                        $isAbsolute = !$isPlaceholder
                            && $entry['weight'] !== null
                            && $absoluteMax !== null
                            && abs($entry['weight'] - $absoluteMax) < 0.000001;

                        $zoneRow = [
                            'position' => $assignedPosition,
                            'weight' => $entry['weight'] ?? null,
                            'draw' => $draw,
                            'is_absolute' => !$isPlaceholder && $isAbsolute,
                            'is_technical' => $isTechnical,
                            'peg_number' => $draw?->peg_number,
                            'placeholder' => $isPlaceholder,
                            'display_name' => $draw
                                ? ($draw->frozen_angler_name ?? ($draw->angler->name ?? '—'))
                                : 'Posto tecnico (vuoto)',
                        ];
                        $zones[$sectorLabel][] = $zoneRow;

                        if (!$isPlaceholder && $anglerId !== null) {
                            $placements[$anglerId] = [
                                'position' => $assignedPosition,
                                'zone' => $sectorLabel,
                                'is_absolute' => $isAbsolute,
                                'is_technical' => $isTechnical,
                            ];
                        }
                    }

                    $currentPosition += $groupSize;
                }

                // conserva l'ultimo settore non tecnico/tecnico (solo i reali) per le eventuali repliche del successivo settore tecnico
                $lastSectorSnapshot = $sectorEntries;
            }
        }

        return [
            'complete' => true,
            'placements' => $placements,
            'zones' => $zones,
            'absolute_max' => $absoluteMax,
        ];
    }
}
