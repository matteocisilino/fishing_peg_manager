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
use App\Models\User;
use App\Services\SecureActionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ParticipantDirectoryController extends Controller
{
    public function __construct(private readonly SecureActionService $secureActionService)
    {
    }

    public function create(Request $request): View
    {
        return view('participants.create', [
            'defaultProvince' => $this->defaultProvinceForUser($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $defaultProvince = $this->defaultProvinceForUser($user);
        $assignedStructure = $user?->assignedStructure();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'province' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/i'],
        ]);

        $province = strtoupper(trim((string)($validated['province'] ?? '')));
        if ($province === '') {
            $province = $defaultProvince ?: null;
        }

        $fullName = trim(preg_replace('/\s+/', ' ', $validated['first_name'] . ' ' . $validated['last_name']));

        $existing = ParticipantDirectory::where('name', $fullName)->first();
        if ($existing) {
            return back()
                ->withErrors(['name' => 'A participant with this name already exists.'])
                ->withInput();
        }

        ParticipantDirectory::create([
            'structure_id' => $assignedStructure?->id,
            'name' => $fullName,
            'phone' => $validated['phone'] ?? null,
            'province' => $province,
        ]);

        return redirect()
            ->route('participants.index')
            ->with('status', 'Participant added successfully.');
    }

    public function bulkAssign(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user?->isSuperAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'participant_ids' => ['required', 'array'],
            'participant_ids.*' => ['integer', 'exists:participant_directory,id'],
            'structure_id' => ['required', 'integer', 'exists:structures,id'],
        ]);

        ParticipantDirectory::whereIn('id', $data['participant_ids'])
            ->update(['structure_id' => $data['structure_id']]);

        return redirect()
            ->route('participants.index', $request->only('q', 'page'))
            ->with('status', 'Facility assigned to selected participants.');
    }

    public function index(Request $request): View
    {
        $query = ParticipantDirectory::query();
        $search = trim((string) $request->input('q', ''));
        $user = $request->user();
        $assignedStructure = $user?->assignedStructure();

        if (!$user?->isSuperAdmin()) {
            if (!$assignedStructure) {
                abort(403, 'No associated facility.');
            }
            $query->where('structure_id', $assignedStructure->id);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('last_known_name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        $participants = $query
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $usageCounts = [];
        if ($participants->isNotEmpty()) {
            $usageCounts = Angler::query()
                ->selectRaw('name, COUNT(*) as total')
                ->whereIn('name', $participants->pluck('name'))
                ->groupBy('name')
                ->pluck('total', 'name')
                ->all();
        }

        $mergeOptions = $user?->hasRole(\App\Models\User::ROLE_ADMIN)
            ? ParticipantDirectory::orderBy('name')->get()
            : collect();
        $structures = collect();

        return view('participants.index', [
            'participants' => $participants,
            'search' => $search,
            'usageCounts' => $usageCounts,
            'mergeOptions' => $mergeOptions,
            'structures' => $structures,
        ]);
    }

    private function defaultProvinceForUser(?User $user): ?string
    {
        $province = $user?->assignedStructure()?->province;

        return $province ? strtoupper($province) : null;
    }

    public function suggest(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $user = $request->user();
        $assignedStructure = $user?->assignedStructure();

        $query = ParticipantDirectory::query()
            ->whereNull('anonymized_at');

        if (!$user?->isSuperAdmin()) {
            if (!$assignedStructure) {
                return response()->json(['data' => []]);
            }
            $query->where('structure_id', $assignedStructure->id);
        }

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $names = $query
            ->orderBy('name')
            ->limit(20)
            ->pluck('name')
            ->all();

        return response()->json(['data' => $names]);
    }

    public function update(Request $request, ParticipantDirectory $participant): RedirectResponse|JsonResponse
    {
        $this->ensureAuthorized($participant);
        $user = $request->user();
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('participant_directory')->ignore($participant->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'province' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/i'],
            'structure_id' => ['nullable', 'integer', 'exists:structures,id'],
        ]);

        DB::transaction(function () use ($participant, $data) {
            $oldName = $participant->name;
            $participant->update($this->participantAttributes($participant, $data));

            if ($oldName !== $participant->name) {
                Angler::where('name', $oldName)->update(['name' => $participant->name]);
            }
        });

        if ($request->expectsJson()) {
            $participant->refresh();

            return response()->json([
                'message' => 'Angler data updated.',
                'participant' => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'phone' => $participant->phone,
                    'province' => $participant->province,
                ],
            ]);
        }

        return back()
            ->with('status', 'Angler data updated.')
            ->with('scroll_to', 'participant-' . $participant->id);
    }

    public function anonymize(ParticipantDirectory $participant): RedirectResponse
    {
        $this->ensureAuthorized($participant);
        DB::transaction(function () use ($participant) {
            $anonymousCode = $this->generateAnonymousCode();
            $lastName = $participant->last_known_name ?: $participant->name;

            Angler::where('name', $participant->name)->update(['name' => $anonymousCode]);

            $participant->update([
                'name' => $anonymousCode,
                'last_known_name' => $lastName,
                'anonymized_code' => $anonymousCode,
                'anonymized_at' => now(),
                'phone' => null,
                'province' => null,
            ]);
        });

        return back()
            ->with('status', 'The angler has been anonymized.')
            ->with('scroll_to', 'participant-' . $participant->id);
    }

    public function requestOtp(Request $request, ParticipantDirectory $participant): RedirectResponse
    {
        $this->ensureAuthorized($participant);
        $action = 'participants.force-delete:' . $participant->id;
        $label = "eliminare definitivamente {$participant->name}";

        $this->secureActionService->issue(
            $request->user(),
            $action,
            [
                'participant_id' => $participant->id,
                'participant_name' => $participant->name,
                'label' => $label,
            ]
        );

        return back()
            ->with('status', 'OTP sent via email. Enter it to confirm deletion.')
            ->with('scroll_to', 'participant-' . $participant->id);
    }

    public function destroy(Request $request, ParticipantDirectory $participant): RedirectResponse
    {
        $this->ensureAuthorized($participant);
        $validated = $request->validate([
            'otp_code' => ['required', 'digits:6'],
        ]);

        $action = 'participants.force-delete:' . $participant->id;
        $this->secureActionService->validate($request->user(), $action, $validated['otp_code']);

        DB::transaction(function () use ($participant) {
            $namesToDelete = array_filter([
                $participant->name,
                $participant->last_known_name,
            ]);

            if (!empty($namesToDelete)) {
                Angler::whereIn('name', $namesToDelete)
                    ->chunkById(100, function ($chunk) {
                        foreach ($chunk as $angler) {
                            $angler->delete();
                        }
                    });
            }

            $participant->delete();
        });

        return back()
            ->with('status', 'Angler deleted permanently.')
            ->with('scroll_to', 'participant-' . $participant->id);
    }

    public function merge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'different:target_id', 'exists:participant_directory,id'],
            'target_id' => ['required', 'integer', 'exists:participant_directory,id'],
        ]);

        DB::transaction(function () use ($data) {
            $source = ParticipantDirectory::lockForUpdate()->findOrFail($data['source_id']);
            $target = ParticipantDirectory::lockForUpdate()->findOrFail($data['target_id']);

            Angler::where('name', $source->name)->update(['name' => $target->name]);

            if (!$target->phone && $source->phone) {
                $target->phone = $source->phone;
            }
            if (!$target->province && $source->province) {
                $target->province = $source->province;
            }
            $target->save();

            $source->delete();
        });

        return back()->with('status', 'Merge completed successfully.');
    }

    private function participantAttributes(ParticipantDirectory $participant, array $input): array
    {
        $attributes = [
            'name' => $input['name'],
            'phone' => $input['phone'] ?? null,
            'province' => isset($input['province']) ? strtoupper($input['province']) : null,
        ];

        $user = auth()->user();
        if ($user && $user->hasRole(\App\Models\User::ROLE_ADMIN) && array_key_exists('structure_id', $input)) {
            $attributes['structure_id'] = $input['structure_id'] ?: null;
        }

        if ($participant->anonymized_at && $input['name'] !== $participant->anonymized_code) {
            $attributes['anonymized_at'] = null;
            $attributes['anonymized_code'] = null;
            $attributes['last_known_name'] = null;
        }

        return $attributes;
    }

    private function generateAnonymousCode(): string
    {
        do {
            $code = 'ANONIMO-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (ParticipantDirectory::where('name', $code)->exists());

        return $code;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureAuthorized(ParticipantDirectory $participant): void
    {
        $user = auth()->user();
        if (!$user) {
            throw new AuthorizationException();
        }

        if ($user->hasRole(\App\Models\User::ROLE_ADMIN)) {
            return;
        }

        $structureId = $user->assignedStructure()?->id;

        if ($structureId && $participant->structure_id === $structureId) {
            return;
        }

        throw new AuthorizationException();
    }
}
