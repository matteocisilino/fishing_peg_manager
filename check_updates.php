#!/usr/bin/env php
<?php

/**
 * Fishing Peg Manager - GitHub Update Checker Script
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var \App\Services\GitHubUpdateChecker $checker */
$checker = $app->make(\App\Services\GitHubUpdateChecker::class);
$bypassCache = in_array('--force', $argv, true);

echo "Verifica aggiornamenti su GitHub per il repository (matteocisilino/fishing_peg_manager)...\n";

$result = $checker->check($bypassCache);

if (!empty($result['error'])) {
    echo "Errore durante il controllo degli aggiornamenti: " . $result['error'] . "\n";
    exit(1);
}

echo "Versione installata: " . $result['current_version'] . "\n";
echo "Ultima versione GitHub: " . $result['latest_version'] . "\n";

if ($result['has_update']) {
    echo "\n⚡ NUOVA VERSIONE DISPONIBILE!\n";
    echo "Release: " . $result['release_name'] . "\n";
    echo "URL: " . $result['release_url'] . "\n";
} else {
    echo "\n✓ L'applicazione è aggiornata all'ultima versione disponibile.\n";
}
