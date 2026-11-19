<?php
/**
 * GeDefense WPR test entry point.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Usage: php tests/run.php [filter]
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('VGT_ACCESS_DENIED');
}

require __DIR__ . '/bootstrap.php';

$filter = isset($_SERVER['argv'][1]) && is_string($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : null;
$runner = new TestRunner($filter);

$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases);

foreach ($cases as $caseFile) {
    fwrite(STDOUT, PHP_EOL . '== ' . basename($caseFile) . ' ==' . PHP_EOL);
    require $caseFile;
}

exit($runner->summary());
