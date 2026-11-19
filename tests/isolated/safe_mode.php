<?php
/**
 * Isolated subprocess: proves VGT_OMEGA_SAFE_MODE prevents artifact mounting.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

define('VGT_OMEGA_SAFE_MODE', true);

require dirname(__DIR__) . '/bootstrap.php';

use VGT\Omega\System\Vault\VaultManager;

$harness = new VaultHarness();
$installed = $harness->installFiles(VaultHarness::samplePlugin(), 'plugin.php', 'Safe Mode Artifact');

VaultManager::boot($harness->bridge, $harness->environment);

$stats = VaultManager::mountStats();

$ok = $stats['mounted'] === []
    && $stats['locked'] === [$installed['id']]
    && VaultManager::isUnlocked($installed['id']) === false
    && VaultManager::isSafeMode() === true;

if (!$ok) {
    fwrite(STDERR, 'SAFE_MODE_FAIL ' . json_encode($stats) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'SAFE_MODE_OK mounted=0 locked=1' . PHP_EOL);
exit(0);
