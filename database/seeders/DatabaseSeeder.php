<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Impianto',
            'email' => 'admin@example.com',
            'secondary_email' => 'admin.backup@example.com',
            'role' => User::ROLE_ADMIN,
            'password' => 'password',
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Rilevatore',
            'email' => 'rilevatore@example.com',
            'role' => User::ROLE_RILEVATORE,
            'password' => 'password',
            'is_active' => true,
        ]);

        \App\Models\Structure::create([
            'name' => 'Laghi di Prova',
            'address' => 'Via Roma 1',
            'comune' => 'Milano',
            'province' => 'MI',
            'email' => 'info@laghi.test',
            'phone' => '123456789',
        ]);
    }
}
