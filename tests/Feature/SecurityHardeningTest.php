<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_all_is_disabled_without_configured_key(): void
    {
        config(['services.sync.key' => null]);

        $this->postJson('/api/sync-all', ['api_key' => 'default-secret'])->assertStatus(401);
        $this->postJson('/api/sync-all', ['api_key' => ''])->assertStatus(401);
    }

    public function test_sync_all_rejects_wrong_key_and_accepts_right_one(): void
    {
        config(['services.sync.key' => 'k-123']);

        $this->postJson('/api/sync-all', ['api_key' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/sync-all', ['api_key' => 'k-123'])->assertStatus(200);
    }

    public function test_install_post_is_refused_when_already_installed(): void
    {
        $this->post('/install', ['app_name' => 'x'])->assertNotFound();
    }

    public function test_lang_switch_rejects_path_traversal_locale(): void
    {
        $this->get('/lang/..%2Fcomposer')->assertNotFound();
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['email' => 'a@b.it', 'password' => 'x']);
        }

        $this->post('/login', ['email' => 'a@b.it', 'password' => 'x'])->assertStatus(429);
    }

    public function test_admin_cannot_edit_super_admin_or_self_promote(): void
    {
        $super = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $payload = ['name' => 'X', 'email' => 'x@y.it', 'role' => 'admin', 'password' => 'newpassword1'];

        $this->actingAs($admin)->put(route('users.update', $super), $payload)->assertForbidden();
        $this->actingAs($admin)->get(route('users.edit', $super))->assertForbidden();

        $rilevatore = \App\Models\User::factory()->create(['role' => 'rilevatore', 'is_active' => true]);
        $this->actingAs($admin)->put(route('users.update', $rilevatore), $payload);
        $this->assertSame('rilevatore', $rilevatore->fresh()->role);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
