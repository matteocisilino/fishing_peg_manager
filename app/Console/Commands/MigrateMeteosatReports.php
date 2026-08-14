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

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateMeteosatReports extends Command
{
    protected $signature = 'meteosat:migrate-data
        {mode=migrate : Mode: migrate (default) or clean}
        {--source= : Source connection (default: database.default)}
        {--target=meteosat : Target connection}
        {--chunk=200 : Number of records per chunk}
        {--force : Do not request confirmation for destructive operations}';

    protected $description = 'Copy meteosat_reports from main DB to dedicated meteosat connection.';

    public function handle(): int
    {
        $mode = $this->argument('mode') ?? 'migrate';
        $sourceConnectionName = $this->option('source') ?: config('database.default');
        $targetConnectionName = $this->option('target') ?: 'meteosat';
        $chunkSize = (int) $this->option('chunk');

        if (!Config::has("database.connections.{$targetConnectionName}")) {
            $this->error("Target connection '{$targetConnectionName}' not configured.");
            return self::INVALID;
        }

        $target = DB::connection($targetConnectionName);

        if ($mode === 'clean') {
            return $this->cleanLegacyTables($this->option('source') ?: config('database.default'));
        }

        if ($sourceConnectionName === $targetConnectionName) {
            $this->error('Source and target connections must be different.');
            return self::INVALID;
        }

        if (!Config::has("database.connections.{$sourceConnectionName}")) {
            $this->error("Source connection '{$sourceConnectionName}' not configured.");
            return self::INVALID;
        }

        $source = DB::connection($sourceConnectionName);

        if (!$this->option('force')) {
            $confirm = $this->confirm("The meteosat_reports table will be deleted and recreated on '{$targetConnectionName}'. Continue?");
            if (!$confirm) {
                $this->info('Operation cancelled.');
                return self::INVALID;
            }
        }

        $this->warn("Deleting potential meteosat_reports table on '{$targetConnectionName}'...");
        Schema::connection($targetConnectionName)->dropIfExists('meteosat_reports');

        $this->warn("Recreating the meteosat_reports table on '{$targetConnectionName}' via dedicated migration...");
        $migrationPath = 'database/migrations/2025_12_20_000006_create_meteosat_reports_table.php';
        $exitCode = Artisan::call('migrate', [
            '--database' => $targetConnectionName,
            '--path' => $migrationPath,
            '--force' => true,
        ]);
        if ($exitCode !== 0) {
            $this->error('Error migrating the Meteosat table to the target database.');
            return self::FAILURE;
        }
        $this->info('Meteosat table recreated successfully.');

        $total = $source->table('meteosat_reports')->count();
        if ($total === 0) {
            $this->info('No data to migrate.');
            return self::SUCCESS;
        }

        $this->info("Copying {$total} records from '{$sourceConnectionName}' to '{$targetConnectionName}' (chunk {$chunkSize}).");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $columns = [
            'id',
            'fishing_event_id',
            'tournament_id',
            'structure_id',
            'comune',
            'start_at',
            'end_at',
            'payload',
            'created_at',
            'updated_at',
        ];

        $source->table('meteosat_reports')
            ->orderBy('id')
            ->chunk($chunkSize, function ($rows) use ($target, $bar, $columns) {
                $batch = [];
                foreach ($rows as $row) {
                    $record = [];
                    foreach ($columns as $column) {
                        $record[$column] = $row->{$column} ?? null;
                    }
                    $batch[] = $record;
                }

                if (!empty($batch)) {
                    $target->table('meteosat_reports')->upsert(
                        $batch,
                        ['id'],
                        array_diff($columns, ['id'])
                    );
                }

                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();
        $this->info('Meteosat migration completed.');

        return self::SUCCESS;
    }

    protected function cleanLegacyTables(?string $connectionName): int
    {
        $connectionName = $connectionName ?: config('database.default');

        if (!Config::has("database.connections.{$connectionName}")) {
            $this->error("Connection '{$connectionName}' not configured.");
            return self::INVALID;
        }

        if (!$this->option('force') && !$this->confirm("Permanently delete the meteosat_reports table from the database '{$connectionName}'?")) {
            $this->info('Operation cancelled.');
            return self::INVALID;
        }

        Schema::connection($connectionName)->dropIfExists('meteosat_reports');
        $this->info("meteosat_reports table deleted from '{$connectionName}'.");

        return self::SUCCESS;
    }
}
