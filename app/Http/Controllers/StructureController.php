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

use App\Models\Structure;
use App\Models\StructureLake;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StructureController extends Controller
{
    public function index(): View
    {
        $structures = Structure::orderBy('name')->get();
        return view('structures.index', compact('structures'));
    }

    public function create(): View|RedirectResponse
    {
        if (Structure::exists()) {
            return redirect()->route('structures.index')
                ->with('status', 'A facility is already configured.');
        }

        return view('structures.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateStructure($request, null);

        $structure = Structure::create($payload['attributes']);
        $this->syncStructureLakes($structure, $payload['lakes']);

        return redirect()->route('structures.index')->with('status', 'Facility created successfully.');
    }

    public function edit(Structure $structure): View|RedirectResponse
    {
        $structure->load('lakes');
        return view('structures.edit', compact('structure'));
    }

    public function update(Request $request, Structure $structure): RedirectResponse
    {
        $payload = $this->validateStructure($request, $structure);

        $structure->update($payload['attributes']);
        $this->syncStructureLakes($structure, $payload['lakes']);

        return redirect()->route('structures.index')->with('status', 'Facility updated successfully.');
    }

    public function destroy(Structure $structure): RedirectResponse
    {
        $structure->delete();
        return redirect()->route('structures.index')->with('status', 'Facility deleted.');
    }

    protected function validateStructure(Request $request, ?Structure $structure): array
    {
        $googleMapsUrl = trim((string) $request->input('google_maps_url', ''));
        if ($googleMapsUrl !== '') {
            $request->merge(['google_maps_url' => $googleMapsUrl]);
            $coordinates = $this->extractCoordinatesFromGoogleMapsLink($googleMapsUrl);
            if (!$coordinates) {
                throw ValidationException::withMessages([
                    'google_maps_url' => 'Unable to extract coordinates from this Google Maps link.',
                ]);
            }
            if (!$request->filled('latitude')) {
                $request->merge(['latitude' => $coordinates['latitude']]);
            }
            if (!$request->filled('longitude')) {
                $request->merge(['longitude' => $coordinates['longitude']]);
            }
            if (!$request->filled('altitude') && $coordinates['altitude'] !== null) {
                $request->merge(['altitude' => $coordinates['altitude']]);
            }
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'comune' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'altitude' => ['nullable', 'integer', 'between:-500,9000'],
            'google_maps_url' => ['nullable', 'url', 'max:2048'],
            'province' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
        ];

        $validated = $request->validate($rules);
        if (!array_key_exists('province', $validated) || $validated['province'] === null) {
            $validated['province'] = null;
        } else {
            $validated['province'] = strtoupper($validated['province']);
        }
        $validated['latitude'] = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $validated['longitude'] = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        if (!array_key_exists('altitude', $validated) || $validated['altitude'] === null || $validated['altitude'] === '') {
            $validated['altitude'] = null;
        }
        if (!array_key_exists('google_maps_url', $validated) || $validated['google_maps_url'] === null || $validated['google_maps_url'] === '') {
            $validated['google_maps_url'] = null;
        }

        $lakes = $this->collectLakeData($request);

        return [
            'attributes' => $validated,
            'lakes' => $lakes,
        ];
    }

    protected function extractCoordinatesFromGoogleMapsLink(string $url): ?array
    {
        $decoded = rawurldecode($url);
        $patterns = [
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',
            '/q=(-?\d+\.\d+),(-?\d+\.\d+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded, $matches)) {
                return [
                    'latitude' => (float) $matches[1],
                    'longitude' => (float) $matches[2],
                    'altitude' => null,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array{name:string, peg_count:int, peg_prefix:string|null, sort_order:int}>
     */
    protected function collectLakeData(Request $request): array
    {
        $names = $request->input('lake_name', []);
        $pegCounts = $request->input('lake_peg_count', []);
        $prefixes = $request->input('lake_prefix', []);

        $lakes = [];

        foreach ($names as $index => $rawName) {
            $name = trim((string) $rawName);
            $count = isset($pegCounts[$index]) ? (int) $pegCounts[$index] : 0;
            $rawPrefix = trim((string) ($prefixes[$index] ?? ''));

            if ($name === '' && $count <= 0 && $rawPrefix === '') {
                continue;
            }

            if ($name === '' || $count <= 0) {
                throw ValidationException::withMessages([
                    'lakes' => 'Each lake must have a name and a number of pegs greater than zero.',
                ]);
            }

            $prefix = null;
            if ($rawPrefix !== '') {
                if (!preg_match('/^[A-Za-z0-9]+$/', $rawPrefix)) {
                    throw ValidationException::withMessages([
                        'lakes' => 'The prefix can only contain letters and numbers, without spaces or symbols.',
                    ]);
                }
                $prefix = strtoupper($rawPrefix);
            }

            $lakes[] = [
                'name' => $name,
                'peg_count' => $count,
                'peg_prefix' => $prefix,
                'sort_order' => $index + 1,
            ];
        }

        if (empty($lakes)) {
            throw ValidationException::withMessages([
                'lakes' => 'Define at least one lake and its number of pegs.',
            ]);
        }

        return $lakes;
    }

    protected function syncStructureLakes(Structure $structure, array $lakes): void
    {
        $structure->lakes()->delete();

        foreach ($lakes as $lake) {
            $structure->lakes()->create($lake);
        }
    }

}
