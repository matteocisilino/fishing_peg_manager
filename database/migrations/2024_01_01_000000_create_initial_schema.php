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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('anglers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('team_id');
            $table->integer('zone_id')->nullable();
            $table->string('name');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_absent')->default(false);
            $table->foreign(['team_id'], null)->references(['id'])->on('teams')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['zone_id'], null)->references(['id'])->on('zones')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('draws', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id');
            $table->integer('angler_id');
            $table->string('peg_number');
            $table->string('frozen_angler_name')->nullable();
            $table->string('frozen_team_name')->nullable();
            $table->string('frozen_zone_name')->nullable();
            $table->integer('frozen_zone_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['event_id'], null)->references(['id'])->on('events')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['angler_id'], null)->references(['id'])->on('anglers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['frozen_zone_id'], null)->references(['id'])->on('zones')->onUpdate('no action')->onDelete('set null');
        });

        Schema::create('event_zone_locks', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id');
            $table->string('zone_name');
            $table->string('zone_key');
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['event_id', 'zone_key']);
            $table->foreign(['event_id'], null)->references(['id'])->on('events')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tournament_id');
            $table->date('event_date');
            $table->string('name')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('structure_id')->nullable();
            $table->foreign(['structure_id'], null)->references(['id'])->on('structures')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tournament_id'], null)->references(['id'])->on('tournaments')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->text('payload');
            $table->text('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->text('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('queue')->index();
            $table->text('payload');
            $table->integer('attempts');
            $table->integer('reserved_at')->nullable();
            $table->integer('available_at');
            $table->integer('created_at');
        });

        Schema::create('meteosat_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('fishing_event_id')->unique();
            $table->dateTime('run_at')->index();
            $table->string('status')->default('pending')->index();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['fishing_event_id'], null)->references(['id'])->on('events')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('meteosat_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('fishing_event_id')->unique();
            $table->integer('tournament_id')->index();
            $table->integer('structure_id')->nullable()->index();
            $table->string('comune');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->text('payload');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('participant_directory', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->unique();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('phone')->nullable();
            $table->string('province')->nullable();
            $table->string('last_known_name')->nullable();
            $table->string('anonymized_code')->nullable();
            $table->dateTime('anonymized_at')->nullable();
            $table->integer('structure_id')->nullable();
            $table->foreign(['structure_id'], null)->references(['id'])->on('structures')->onUpdate('no action')->onDelete('set null');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('pool_pegs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tournament_id');
            $table->string('peg_number');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('zone_name')->default('Zona');

            $table->unique(['tournament_id', 'peg_number']);
            $table->foreign(['tournament_id'], null)->references(['id'])->on('tournaments')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('secure_action_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('action');
            $table->string('code_hash');
            $table->text('payload')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['user_id', 'action']);
            $table->foreign(['user_id'], null)->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('security_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('require_admin_otp')->default(false);
            $table->boolean('require_rilevatore_otp')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('user_id')->nullable()->index();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('structure_lake_tournament', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tournament_id');
            $table->integer('structure_lake_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tournament_id', 'structure_lake_id']);
            $table->foreign(['structure_lake_id'], null)->references(['id'])->on('structure_lakes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tournament_id'], null)->references(['id'])->on('tournaments')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('structure_lakes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('structure_id');
            $table->string('name');
            $table->integer('peg_count');
            $table->string('peg_prefix')->nullable();
            $table->integer('sort_order')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['structure_id'], null)->references(['id'])->on('structures')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('structures', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('address');
            $table->string('email');
            $table->string('phone');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('province')->nullable();
            $table->string('comune')->nullable();
            $table->decimal('latitude')->nullable();
            $table->decimal('longitude')->nullable();
            $table->integer('altitude')->nullable();
            $table->string('google_maps_url')->nullable();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tournament_id');
            $table->string('name');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['tournament_id'], null)->references(['id'])->on('tournaments')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('tournaments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('sector_size')->default(5);
            $table->string('tournament_type')->default('team');
            $table->integer('team_size')->nullable();
            $table->integer('pool_capacity')->nullable();
            $table->boolean('allow_edge_repeats')->default(false);
            $table->integer('structure_id')->nullable();
            $table->foreign(['structure_id'], null)->references(['id'])->on('structures')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('device_name')->nullable();
            $table->string('token_hash');
            $table->string('user_agent')->nullable();
            $table->string('ip_address')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['user_id'], null)->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('role')->default('admin');
            $table->boolean('is_active')->default(true);
            $table->string('secondary_email')->nullable();
            $table->string('otp_code')->nullable();
            $table->dateTime('otp_expires_at')->nullable();
        });

        Schema::create('weight_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tournament_id');
            $table->integer('event_id');
            $table->integer('angler_id');
            $table->integer('draw_id')->nullable();
            $table->string('peg_number')->nullable();
            $table->decimal('weight_value');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['tournament_id'], null)->references(['id'])->on('tournaments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['event_id'], null)->references(['id'])->on('events')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['angler_id'], null)->references(['id'])->on('anglers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['draw_id'], null)->references(['id'])->on('draws')->onUpdate('no action')->onDelete('set null');
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tournament_id');
            $table->string('name');
            $table->string('start_number');
            $table->string('end_number');
            $table->boolean('is_technical')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tournament_id', 'name']);
            $table->foreign(['tournament_id'], null)->references(['id'])->on('tournaments')->onUpdate('no action')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('zones');

        Schema::dropIfExists('weight_entries');

        Schema::dropIfExists('users');

        Schema::dropIfExists('trusted_devices');

        Schema::dropIfExists('tournaments');

        Schema::dropIfExists('teams');

        Schema::dropIfExists('structures');

        Schema::dropIfExists('structure_lakes');

        Schema::dropIfExists('structure_lake_tournament');

        Schema::dropIfExists('sessions');

        Schema::dropIfExists('security_settings');

        Schema::dropIfExists('secure_action_tokens');

        Schema::dropIfExists('pool_pegs');

        Schema::dropIfExists('password_reset_tokens');

        Schema::dropIfExists('participant_directory');

        Schema::dropIfExists('meteosat_reports');

        Schema::dropIfExists('meteosat_jobs');

        Schema::dropIfExists('jobs');

        Schema::dropIfExists('job_batches');

        Schema::dropIfExists('failed_jobs');

        Schema::dropIfExists('events');

        Schema::dropIfExists('event_zone_locks');

        Schema::dropIfExists('draws');

        Schema::dropIfExists('cache_locks');

        Schema::dropIfExists('cache');

        Schema::dropIfExists('anglers');
    }
};
