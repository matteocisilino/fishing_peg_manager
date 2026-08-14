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

use App\Models\Draw;
use App\Models\FishingEvent;
use App\Models\MeteosatJob;
use App\Models\MeteosatReport;
use App\Services\EventService;
use App\Services\MeteostatClient;
use App\Services\SecureActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(
        private readonly SecureActionService $secureActionService,
        private readonly EventService $eventService
    ) {}

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
        $draws = $drawsQuery->map(fn (Draw $draw) => $this->eventService->formatDrawRow($draw, $sectorSize, $event->tournament));

        return view('events.show', [
            'event' => $event,
            'draws' => $draws,
            'pegOverview' => $this->eventService->buildPegOverview($event),
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
            $this->eventService->generateDraw($event);
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

        $this->eventService->consolidate($event);

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

        $this->eventService->updateDraws($event, $normalizedDraws);

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

        $this->eventService->unlock($event);

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
}
