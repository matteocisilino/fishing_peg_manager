<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubUpdateChecker
{
    /**
     * Check GitHub for updates.
     *
     * @param bool $forceBypassCache
     * @return array
     */
    public function check(bool $forceBypassCache = false): array
    {
        $cacheKey = 'github_latest_release_check';
        $ttl = (int) config('version.cache_ttl', 43200);

        if ($forceBypassCache) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $ttl, function () {
            return $this->fetchFromGitHub();
        });
    }

    /**
     * Fetch latest release payload directly from GitHub API.
     */
    protected function fetchFromGitHub(): array
    {
        $currentVersion = (string) config('version.current_version', '1.0.0');
        $repo = (string) config('version.github_repo', 'matteocisilino/fishing_peg_manager');
        $url = "https://api.github.com/repos/{$repo}/releases/latest";

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'FishingPegManager-UpdateChecker/1.0',
                'Accept' => 'application/vnd.github.v3+json',
            ])->timeout(8)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                $latestTag = (string) ($data['tag_name'] ?? '');
                $latestVersion = ltrim($latestTag, 'vV');
                $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

                return [
                    'success' => true,
                    'current_version' => $currentVersion,
                    'latest_version' => $latestVersion ?: $currentVersion,
                    'has_update' => $hasUpdate,
                    'release_name' => $data['name'] ?? "Release {$latestTag}",
                    'release_url' => $data['html_url'] ?? "https://github.com/{$repo}/releases",
                    'zipball_url' => $data['zipball_url'] ?? null,
                    'tarball_url' => $data['tarball_url'] ?? null,
                    'body' => $data['body'] ?? '',
                    'published_at' => $data['published_at'] ?? null,
                    'checked_at' => now()->toIso8601String(),
                    'error' => null,
                ];
            }

            if ($response->status() === 404) {
                return [
                    'success' => true,
                    'current_version' => $currentVersion,
                    'latest_version' => $currentVersion,
                    'has_update' => false,
                    'release_name' => 'No release tag published yet',
                    'release_url' => "https://github.com/{$repo}",
                    'zipball_url' => null,
                    'tarball_url' => null,
                    'body' => '',
                    'published_at' => null,
                    'checked_at' => now()->toIso8601String(),
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'current_version' => $currentVersion,
                'latest_version' => $currentVersion,
                'has_update' => false,
                'release_name' => null,
                'release_url' => "https://github.com/{$repo}",
                'zipball_url' => null,
                'tarball_url' => null,
                'body' => null,
                'published_at' => null,
                'checked_at' => now()->toIso8601String(),
                'error' => "GitHub API returned status code {$response->status()}",
            ];
        } catch (\Throwable $e) {
            Log::warning("GitHubUpdateChecker failed: {$e->getMessage()}");

            return [
                'success' => false,
                'current_version' => $currentVersion,
                'latest_version' => $currentVersion,
                'has_update' => false,
                'release_name' => null,
                'release_url' => "https://github.com/{$repo}",
                'zipball_url' => null,
                'tarball_url' => null,
                'body' => null,
                'published_at' => null,
                'checked_at' => now()->toIso8601String(),
                'error' => "Unable to connect to GitHub API: " . $e->getMessage(),
            ];
        }
    }
}
