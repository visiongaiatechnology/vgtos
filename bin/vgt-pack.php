#!/usr/bin/env php
<?php
/**
 * VGT Omega reference encryptor CLI.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Usage: php bin/vgt-pack.php --help
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('VGT_ACCESS_DENIED');
}

$root = dirname(__DIR__);
require_once $root . '/src/Bootstrap/Autoloader.php';
\VGT\Omega\Bootstrap\Autoloader::register($root);

exit((new \VGT\Omega\Tooling\PackCommand())->run($_SERVER['argv'] ?? []));
