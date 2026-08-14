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

namespace App\Support;

use App\Models\Structure;
use App\Models\Tournament;

class PegHelper
{
    public static function normalizeCode(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Z]*\\d+$/', $code) === 1;
    }

    /**
     * Restituisce i codici picchetto disponibili per una struttura.
     *
     * @return list<string>
     */
    public static function allowedCodesForStructure(Structure $structure): array
    {
        $structure->loadMissing('lakes');

        $codes = [];
        foreach ($structure->lakes as $lake) {
            $prefix = $lake->peg_prefix ? strtoupper($lake->peg_prefix) : '';
            $count = (int) $lake->peg_count;
            for ($i = 1; $i <= $count; $i++) {
                $codes[] = $prefix !== '' ? $prefix . $i : (string) $i;
            }
        }

        return $codes;
    }

    /**
     * @param array<int> $lakeIds
     * @return list<string>
     */
    public static function allowedCodesForLakeSelection(Structure $structure, array $lakeIds): array
    {
        $structure->loadMissing('lakes');
        if (empty($lakeIds)) {
            return self::allowedCodesForStructure($structure);
        }

        $lookup = array_flip($lakeIds);
        $codes = [];
        foreach ($structure->lakes as $lake) {
            if (!isset($lookup[$lake->id])) {
                continue;
            }
            $prefix = $lake->peg_prefix ? strtoupper($lake->peg_prefix) : '';
            $count = (int) $lake->peg_count;
            for ($i = 1; $i <= $count; $i++) {
                $codes[] = $prefix !== '' ? $prefix . $i : (string) $i;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public static function allowedCodesForTournament(Tournament $tournament): array
    {
        $tournament->loadMissing(['structure.lakes', 'lakes']);
        $structure = $tournament->structure;
        if (!$structure) {
            return [];
        }

        $selected = $tournament->lakes->pluck('id')->all();
        return self::allowedCodesForLakeSelection($structure, $selected);
    }

    /**
     * @param array<int, string> $codes
     * @return array<string, int>
     */
    public static function buildLookup(array $codes): array
    {
        $map = [];
        foreach ($codes as $index => $code) {
            $normalized = self::normalizeCode($code);
            $map[$normalized] = $index;
        }
        return $map;
    }

    /**
        * @param array<string,int> $lookup
        */
    public static function position(string $code, array $lookup): ?int
    {
        $normalized = self::normalizeCode($code);
        return $lookup[$normalized] ?? null;
    }

    /**
     * @param array<int, string> $codes
     * @param array<string,int>|null $lookup
     * @return list<string>
     */
    public static function sliceRange(string $startCode, string $endCode, array $codes, ?array $lookup = null): array
    {
        if (empty($codes)) {
            return [];
        }
        $map = $lookup ?? self::buildLookup($codes);
        $start = self::normalizeCode($startCode);
        $end = self::normalizeCode($endCode);
        if (!isset($map[$start]) || !isset($map[$end])) {
            return [];
        }
        $startIndex = $map[$start];
        $endIndex = $map[$end];
        if ($startIndex > $endIndex) {
            return [];
        }
        return array_slice($codes, $startIndex, $endIndex - $startIndex + 1);
    }

    /**
     * @param array<int, string> $codes
     * @param array<string,int>|null $lookup
     */
    public static function rangeLength(string $startCode, string $endCode, array $codes, ?array $lookup = null): int
    {
        if (empty($codes)) {
            $start = self::numericValue(self::normalizeCode($startCode));
            $end = self::numericValue(self::normalizeCode($endCode));
            return $end >= $start ? ($end - $start + 1) : 0;
        }
        return count(self::sliceRange($startCode, $endCode, $codes, $lookup));
    }

    /**
     * @param array<string,int> $lookup
     */
    public static function compareCodes(string $a, string $b, array $lookup = []): int
    {
        $na = self::normalizeCode($a);
        $nb = self::normalizeCode($b);

        $posA = $lookup[$na] ?? null;
        $posB = $lookup[$nb] ?? null;

        if ($posA !== null && $posB !== null) {
            return $posA <=> $posB;
        }

        return self::numericValue($na) <=> self::numericValue($nb);
    }

    public static function numericValue(string $code): int
    {
        if (preg_match('/(\\d+)$/', $code, $match)) {
            return (int) $match[1];
        }
        return 0;
    }
}
