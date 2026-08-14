<?php

return [
    'current_version' => env('APP_VERSION', '1.0.0'),
    'github_repo' => env('GITHUB_REPO', 'matteocisilino/fishing_peg_manager'),
    'cache_ttl' => (int) env('UPDATE_CHECK_CACHE_TTL', 43200), // 12 hours in seconds
];
