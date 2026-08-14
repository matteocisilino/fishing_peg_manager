<?php

namespace Tests\Feature;

use App\Models\Angler;
use App\Models\Draw;
use App\Models\FishingEvent;
use App\Models\Structure;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentRefactoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array']);
    }

    public function test_admin_can_create_and_manage_tournament_flow(): void
    {
        // 1. Create a facility (Structure) and a user
        $structure = Structure::create([
            'name' => 'Lago di Prova',
            'address' => 'Via Laghi 1',
            'email' => 'lago@example.com',
            'phone' => '12345678',
            'comune' => 'Verona',
            'latitude' => 45.43,
            'longitude' => 10.99,
            'altitude' => 50,
        ]);

        $lake = $structure->lakes()->create([
            'name' => 'Lago Principale',
            'peg_count' => 15,
            'peg_prefix' => 'A',
            'sort_order' => 1,
        ]);

        $admin = User::create([
            'name' => 'Matteo Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // 2. Act as Admin and POST to tournament store
        $response = $this->actingAs($admin)->post(route('tournaments.store'), [
            't_name' => 'Campionato di Prova',
            'sector_size' => 5,
            'team_size' => 2,
            'allow_edge_repeats' => 1,
            'structure_id' => $structure->id,
            'structure_lake_ids' => [$lake->id],
            'zone_name' => ['ZONA A', 'ZONA B'],
            'zone_start' => ['A1', 'A6'],
            'zone_end' => ['A5', 'A10'],
            'team_name' => ['Team Uno'],
            'member_name' => [
                ['Pescatore Rossi', 'Pescatore Bianchi']
            ],
            'member_zone' => [
                ['ZONA A', 'ZONA B']
            ],
            'event_date' => [now()->toDateString()],
            'event_name' => ['Prova 1'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertDatabaseHas('tournaments', [
            'name' => 'Campionato di Prova',
            'sector_size' => 5,
            'team_size' => 2,
        ]);

        $tournament = Tournament::where('name', 'Campionato di Prova')->first();
        $this->assertNotNull($tournament);

        $event = FishingEvent::where('tournament_id', $tournament->id)->first();
        $this->assertNotNull($event);

        $angler1 = Angler::where('name', 'Pescatore Rossi')->first();
        $angler2 = Angler::where('name', 'Pescatore Bianchi')->first();
        $this->assertNotNull($angler1);
        $this->assertNotNull($angler2);

        $drawResponse = $this->actingAs($admin)->post(route('events.draw', $event));
        $drawResponse->assertSessionHasNoErrors();
        $drawResponse->assertRedirect();
        $this->assertDatabaseHas('draws', [
            'event_id' => $event->id,
            'angler_id' => $angler1->id,
        ]);

        $draw1 = Draw::where('event_id', $event->id)->where('angler_id', $angler1->id)->first();
        $draw2 = Draw::where('event_id', $event->id)->where('angler_id', $angler2->id)->first();

        // 4. Save weight entries
        $weightResponse = $this->actingAs($admin)->post(route('weights.store', $event), [
            'zone_name' => ['ZONA A', 'ZONA B'],
            'weights' => [
                'ZONA A' => [
                    $angler1->id => 1250,
                ],
                'ZONA B' => [
                    $angler2->id => 850,
                ]
            ]
        ]);
        $weightResponse->assertRedirect();
        $this->assertDatabaseHas('weight_entries', [
            'event_id' => $event->id,
            'angler_id' => $angler1->id,
            'weight_value' => 1250,
        ]);
        $this->assertDatabaseHas('weight_entries', [
            'event_id' => $event->id,
            'angler_id' => $angler2->id,
            'weight_value' => 850,
        ]);

        // 5. Lock the zone
        $lockResponse = $this->actingAs($admin)->post(route('weights.lock_zone', $event), [
            'zone_name' => 'ZONA A',
        ]);
        $lockResponse->assertRedirect();
        $this->assertDatabaseHas('event_zone_locks', [
            'event_id' => $event->id,
            'zone_name' => 'ZONA A',
        ]);
    }
}
