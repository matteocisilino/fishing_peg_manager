<?php

namespace App\Console\Commands;

use App\Services\GitHubUpdateChecker;
use Illuminate\Console\Command;

class CheckAppUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-update {--force : Bypass cached result and force an instant GitHub check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check GitHub for available application updates';

    /**
     * Execute the console command.
     */
    public function handle(GitHubUpdateChecker $checker): int
    {
        $force = (bool) $this->option('force');
        $this->info('Checking GitHub repository for updates...');

        $status = $checker->check($force);

        if (!empty($status['error'])) {
            $this->error('Error checking updates: ' . $status['error']);
            return self::FAILURE;
        }

        $this->line("Installed Version: {$status['current_version']}");
        $this->line("Latest GitHub Release: {$status['latest_version']}");

        if ($status['has_update']) {
            $this->newLine();
            $this->warn("⚡ UPDATE AVAILABLE! Version {$status['latest_version']} is available on GitHub.");
            $this->line("Release Name: {$status['release_name']}");
            $this->line("Release URL:  {$status['release_url']}");
        } else {
            $this->info("✓ Your application is up to date!");
        }

        return self::SUCCESS;
    }
}
