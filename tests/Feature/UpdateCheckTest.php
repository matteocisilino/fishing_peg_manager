<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_artisan_command_checks_updates(): void
    {
        Http::fake([
            'https://api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.2.0',
                'name' => 'Release v1.2.0',
                'html_url' => 'https://github.com/matteocisilino/fishing_peg_manager/releases/tag/v1.2.0',
                'body' => 'Bug fixes and performance improvements.',
                'published_at' => '2026-08-14T20:00:00Z',
            ], 200),
        ]);

        $this->artisan('app:check-update', ['--force' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('UPDATE AVAILABLE');
    }

    public function test_updates_index_route_requires_auth(): void
    {
        $response = $this->get(route('updates.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_updates_index_page_loads_for_admin(): void
    {
        Http::fake([
            'https://api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v1.0.0',
                'name' => 'Release v1.0.0',
                'html_url' => 'https://github.com/matteocisilino/fishing_peg_manager/releases/tag/v1.0.0',
                'body' => 'Initial release.',
                'published_at' => '2026-08-14T20:00:00Z',
            ], 200),
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($user)->get(route('updates.index', ['force' => 1]));
        $response->assertStatus(200);
        $response->assertSee('Application Updates');
        $response->assertSee('v1.0.0');
    }
}
