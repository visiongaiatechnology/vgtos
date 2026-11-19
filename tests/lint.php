<?php
/**
 * VGT Omega lint gate.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Usage: php tests/lint.php
 *
 * Runs `php -l` on every production PHP file and enforces the 500-line file
 * budget. Exit code 0 = green, 1 = violation.
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('VGT_ACCESS_DENIED');
}

$root = dirname(__DIR__);
$targets = [];

foreach (['src', 'bin', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $path = str_replace('\\', '/', $file->getPathname());

            // Temporary test workspaces are generated artifacts, not sources.
            if (str_contains($path, '/tests/tmp/')) {
                continue;
            }

            $targets[] = $path;
        }
    }
}

$targets[] = $root . '/vgt-os.php';
sort($targets);

$failures = 0;
$overBudget = 0;

foreach ($targets as $file) {
    $relative = str_replace(str_replace('\\', '/', $root) . '/', '', $file);
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        $failures++;
        fwrite(STDERR, 'LINT FAIL ' . $relative . PHP_EOL . implode(PHP_EOL, $output) . PHP_EOL);
    }

    $lines = count(file($file) ?: []);
    if (str_starts_with($relative, 'src/') || str_starts_with($relative, 'bin/') || $relative === 'vgt-os.php') {
        if ($lines > 500) {
            $overBudget++;
            fwrite(STDERR, 'LINE BUDGET FAIL ' . $relative . ' = ' . $lines . ' (max 500)' . PHP_EOL);
        }
    }
}

fwrite(STDOUT, sprintf(
    'LINT_LEDGER: files=%d lint_failures=%d line_budget_failures=%d exit=%d%s',
    count($targets),
    $failures,
    $overBudget,
    ($failures + $overBudget) === 0 ? 0 : 1,
    PHP_EOL
));

exit(($failures + $overBudget) === 0 ? 0 : 1);
