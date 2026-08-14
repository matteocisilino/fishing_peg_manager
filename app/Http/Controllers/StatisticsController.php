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
use App\Models\Structure;
use App\Models\WeightEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StatisticsController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string)$request->input('angler_search', ''));

        $structure = $this->allowedStructure();
        $pegTotals = WeightEntry::query()
            ->selectRaw('weight_entries.peg_number, tournaments.structure_id, COALESCE(structures.name, "N/D") as structure_name, SUM(weight_entries.weight_value) as total_weight')
            ->join('events', 'weight_entries.event_id', '=', 'events.id')
            ->join('tournaments', 'events.tournament_id', '=', 'tournaments.id')
            ->leftJoin('structures', 'tournaments.structure_id', '=', 'structures.id')
            ->when($structure, fn ($query) => $query->where('tournaments.structure_id', $structure->id))
            ->groupBy('weight_entries.peg_number', 'tournaments.structure_id', 'structures.name')
            ->orderBy('structure_name')
            ->orderBy('weight_entries.peg_number')
            ->get();

        $anglerTotals = WeightEntry::query()
            ->selectRaw('weight_entries.angler_id, SUM(weight_entries.weight_value) as total_weight')
            ->with('angler')
            ->join('events', 'weight_entries.event_id', '=', 'events.id')
            ->join('tournaments', 'events.tournament_id', '=', 'tournaments.id')
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('angler', function ($sub) use ($search) {
                    $sub->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($structure, fn ($query) => $query->where('tournaments.structure_id', $structure->id))
            ->groupBy('angler_id')
            ->orderByDesc('total_weight')
            ->get();

        return view('statistics.index', [
            'pegTotals' => $pegTotals,
            'anglerTotals' => $anglerTotals,
            'anglerSearch' => $search,
        ]);
    }

    public function showPeg(Structure $structure, string $pegNumber): View
    {
        $this->authorizeStructure($structure);
        $pegNumber = strtoupper(trim($pegNumber));
        $entries = WeightEntry::with(['event.weightEntries', 'event.tournament'])
            ->where('peg_number', $pegNumber)
            ->whereHas('event.tournament', fn ($query) => $query->where('structure_id', $structure->id))
            ->get()
            ->sortBy(function ($entry) {
                $date = optional($entry->event)->event_date;
                if ($date) {
                    return $date->timestamp;
                }
                return optional($entry->created_at)?->timestamp ?? 0;
            })
            ->values();

        $totalWeight = $entries->sum('weight_value');
        $averageWeight = $entries->avg('weight_value');

        $lineWeights = [];
        $lineCumulative = [];
        $running = 0.0;
        $eventAverages = [];
        $scatterData = [];

        foreach ($entries as $entry) {
            $dateObj = optional($entry->event)->event_date ?? $entry->created_at ?? now();
            $timestamp = $dateObj->format('c');
            $weight = (float) $entry->weight_value;
            $running += $weight;

            $lineWeights[] = [
                'x' => $timestamp,
                'y' => $weight,
            ];

            $lineCumulative[] = [
                'x' => $timestamp,
                'y' => $running,
            ];

            $eventId = $entry->event_id;
            if ($eventId && !isset($eventAverages[$eventId])) {
                $eventAverages[$eventId] = optional($entry->event)
                    ? (float) $entry->event->weightEntries->avg('weight_value')
                    : null;
            }
            $eventAverage = $eventId ? ($eventAverages[$eventId] ?? null) : null;

            $scatterData[] = [
                'x' => $eventAverage ?? 0,
                'y' => $weight,
                'event' => optional($entry->event)->name ?? 'N/D',
                'date' => $dateObj->format('d/m/Y'),
            ];
        }

        abort_if($entries->isEmpty(), 404);

        return view('statistics.peg', [
            'pegNumber' => $pegNumber,
            'structure' => $structure,
            'entries' => $entries,
            'totalWeight' => $totalWeight,
            'averageWeight' => $averageWeight,
            'lineWeights' => $lineWeights,
            'lineCumulative' => $lineCumulative,
            'scatterData' => $scatterData,
        ]);
    }

    public function showAngler(Angler $angler): View
    {
        $structure = $this->allowedStructure();
        $entries = WeightEntry::with(['event.weightEntries', 'event.tournament'])
            ->where('angler_id', $angler->id)
            ->when($structure, fn ($query) => $query->whereHas('event.tournament', fn ($sub) => $sub->where('structure_id', $structure->id)))
            ->get()
            ->sortBy(function ($entry) {
                $date = optional($entry->event)->event_date;
                if ($date) {
                    return $date->timestamp;
                }
                return optional($entry->created_at)?->timestamp ?? 0;
            })
            ->values();

        if ($entries->isEmpty()) {
            abort(403, 'This angler has no data available for your facility.');
        }

        $totalWeight = $entries->sum('weight_value');
        $averageWeight = $entries->avg('weight_value');

        $lineWeights = [];
        $lineCumulative = [];
        $running = 0.0;
        $eventAverages = [];
        $scatterData = [];

        foreach ($entries as $entry) {
            $dateObj = optional($entry->event)->event_date ?? $entry->created_at ?? now();
            $timestamp = $dateObj->format('c');
            $weight = (float) $entry->weight_value;
            $running += $weight;

            $lineWeights[] = [
                'x' => $timestamp,
                'y' => $weight,
            ];

            $lineCumulative[] = [
                'x' => $timestamp,
                'y' => $running,
            ];

            $eventId = $entry->event_id;
            if ($eventId && !isset($eventAverages[$eventId])) {
                $eventAverages[$eventId] = optional($entry->event)
                    ? (float) $entry->event->weightEntries->avg('weight_value')
                    : null;
            }
            $eventAverage = $eventId ? ($eventAverages[$eventId] ?? null) : null;

            $scatterData[] = [
                'x' => $eventAverage ?? 0,
                'y' => $weight,
                'event' => optional($entry->event)->name ?? 'N/D',
                'date' => $dateObj->format('d/m/Y'),
            ];
        }

        return view('statistics.angler', [
            'angler' => $angler,
            'entries' => $entries,
            'totalWeight' => $totalWeight,
            'averageWeight' => $averageWeight,
            'lineWeights' => $lineWeights,
            'lineCumulative' => $lineCumulative,
            'scatterData' => $scatterData,
        ]);
    }

    private function allowedStructure(): ?Structure
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return null;
        }

        $structure = $user?->assignedStructure();
        if (!$structure) {
            abort(403, 'You do not have an associated facility.');
        }

        return $structure;
    }

    private function authorizeStructure(Structure $structure): void
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return;
        }

        $allowedStructure = $user?->assignedStructure();
        if (!$allowedStructure || $allowedStructure->id !== $structure->id) {
            abort(403, 'You do not have access to this facility.');
        }
    }
}
