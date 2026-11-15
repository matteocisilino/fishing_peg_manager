<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishingEvent;
use App\Models\WeightEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    /**
     * Download event data to the mobile device.
     */
    public function syncDown($eventId): JsonResponse
    {
        $event = FishingEvent::with([
            'tournament',
            'tournament.teams.anglers.zone',
            'tournament.structure.zones'
        ])->find($eventId);

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        // Get current recorded weights for this event
        $weights = WeightEntry::where('event_id', $eventId)->get();

        return response()->json([
            'event' => $event,
            'weights' => $weights,
        ]);
    }

    /**
     * Upload weights from the mobile device to the server.
     */
    public function syncUp(Request $request, $eventId): JsonResponse
    {
        $event = FishingEvent::find($eventId);

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        $incomingWeights = $request->input('weights', []);

        foreach ($incomingWeights as $w) {
            if (!isset($w['angler_id'])) {
                continue;
            }

            WeightEntry::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'angler_id' => $w['angler_id'],
                ],
                [
                    'weight' => $w['weight'] ?? 0,
                    'penalty_id' => $w['penalty_id'] ?? null,
                    'peg_number' => $w['peg_number'] ?? null,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['success' => true, 'message' => 'Synced successfully']);
    }
    public function syncAllUp(Request $request): JsonResponse
    {
        // Simple API Key check
        $apiKey = (string) $request->input('api_key');
        $expectedKey = (string) config('services.sync.key');
        if ($expectedKey === '' || !hash_equals($expectedKey, $apiKey)) {
            return response()->json(['error' => 'Invalid API Key. Check settings.'], 401);
        }

        $tournaments = $request->input('tournaments', []);
        $zonesMap = $request->input('zones', []);
        $participantsMap = $request->input('participants', []);
        $weightsMap = $request->input('weights', []);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            foreach ($tournaments as $t) {
                $tId = $t['id'];
                
                // Create Tournament
                $tournament = \App\Models\Tournament::create([
                    'name' => $t['name'] . ' (Mobile)',
                    'start_date' => $t['date'],
                    'end_date' => $t['date'],
                ]);

                // Create Structure
                $structure = \App\Models\Structure::create([
                    'name' => 'Structure for ' . $tournament->name
                ]);

                // Create Event
                $event = FishingEvent::create([
                    'tournament_id' => $tournament->id,
                    'structure_id' => $structure->id,
                    'event_date' => $t['date'],
                    'name' => 'Gara 1'
                ]);

                // Create Zones
                $zoneIdMapping = []; // mobile_zone_id -> db_zone_id
                if (isset($zonesMap[$tId])) {
                    foreach ($zonesMap[$tId] as $z) {
                        $zone = \App\Models\Zone::create([
                            'structure_id' => $structure->id,
                            'name' => $z['name'],
                            'pegs' => $z['pegs']
                        ]);
                        $zoneIdMapping[$z['id']] = $zone->id;
                    }
                }

                // Create Participants (Teams/Anglers)
                $anglerIdMapping = []; // mobile_participant_id -> db_angler_id
                if (isset($participantsMap[$tId])) {
                    foreach ($participantsMap[$tId] as $p) {
                        $team = \App\Models\Team::create([
                            'tournament_id' => $tournament->id,
                            'name' => $p['name']
                        ]);
                        
                        $zoneId = null;
                        if (!empty($p['zone_id']) && isset($zoneIdMapping[$p['zone_id']])) {
                            $zoneId = $zoneIdMapping[$p['zone_id']];
                        }

                        $angler = \App\Models\Angler::create([
                            'team_id' => $team->id,
                            'name' => $p['name'],
                            'zone_id' => $zoneId
                        ]);

                        $anglerIdMapping[$p['id']] = $angler->id;
                    }
                }

                // Insert Weights & Draws
                if (isset($weightsMap[$tId])) {
                    foreach ($weightsMap[$tId] as $w) {
                        if (!isset($anglerIdMapping[$w['participant_id']])) continue;
                        
                        $realAnglerId = $anglerIdMapping[$w['participant_id']];
                        
                        if (!empty($w['peg_number'])) {
                            \App\Models\Draw::create([
                                'event_id' => $event->id,
                                'angler_id' => $realAnglerId,
                                'peg_number' => $w['peg_number']
                            ]);
                        }

                        if (isset($w['weight'])) {
                            WeightEntry::updateOrCreate(
                                ['event_id' => $event->id, 'angler_id' => $realAnglerId],
                                ['weight' => $w['weight']]
                            );
                        }
                    }
                }
            }
            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
