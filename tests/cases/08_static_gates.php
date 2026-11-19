<?php
/**
 * Static release gates: line budget, forbidden patterns, identity, docs, lint.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

/** @var TestRunner $runner */
$projectRoot = VGT_TEST_PROJECT_ROOT;

$collectPhp = static function (string $root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
};

$sourceFiles = array_merge(
    $collectPhp($projectRoot . '/src'),
    $collectPhp($projectRoot . '/bin')
);
$sourceFiles[] = $projectRoot . '/vgt-os.php';

$runner->test('Every production PHP file stays within the 500-line budget', function () use ($runner, $sourceFiles): void {
    $violations = [];
    foreach ($sourceFiles as $file) {
        $lines = count(file($file) ?: []);
        if ($lines > 500) {
            $violations[] = basename($file) . '=' . $lines;
        }
    }

    $runner->assertSame([], $violations, 'Files above 500 lines: ' . implode(', ', $violations));
});

$runner->test('No forbidden legacy/security anti-patterns remain in production code', function () use ($runner, $sourceFiles, $projectRoot): void {
    $rules = [
        'uniqid(' => null,
        'apcu_clear_cache(' => null,
        'apcu_cache_info(' => null,
        "strpos(\$content, 'VGT_GUARD')" => null,
        'Permission is hereby granted, free of charge' => null,
        'LICENSE: AGPLv3 (OPEN SOURCE)' => null,
        '{$plugin_path}' => null,
        'emptyDir($target)' => null,
        'confirm(' => null,
        'apcu_store(' => 'StreamWrapper.php',
        'VGT\\Bridge\\' => null,
    ];

    $violations = [];
    foreach ($sourceFiles as $file) {
        $relative = str_replace(str_replace('\\', '/', $projectRoot) . '/', '', $file);
        $content = (string) file_get_contents($file);

        foreach ($rules as $needle => $allowedFile) {
            if (!str_contains($content, (string) $needle)) {
                continue;
            }

            if ($allowedFile !== null) {
                if (basename($file) !== $allowedFile) {
                    $violations[] = $relative . ' contains ' . $needle;
                    continue;
                }

                // Ciphertext-only APCu cache: the prefix constant must exist in this file.
                if (!str_contains($content, 'APCU_BLOB_PREFIX')) {
                    $violations[] = $relative . ' caches APCu entries without an invalidation prefix';
                }
                continue;
            }

            $violations[] = $relative . ' contains ' . $needle;
        }
    }

    $runner->assertSame([], $violations, 'Anti-patterns found: ' . implode(' | ', $violations));
});

$runner->test('Legacy v4.0/v4.1 files and namespaces are gone', function () use ($runner, $projectRoot): void {
    $legacy = [
        '/src/System/VaultManager.php',
        '/src/System/MuDeployer.php',
        '/src/UI/Dashboard.php',
        '/src/Adapters/WordPressAdapter.php',
        '/src/Adapters/LaravelAdapter.php',
        '/src/Contracts/BridgeInterface.php',
        '/src/Contracts/EnvironmentInterface.php',
        '/src/Core/Container.php',
        '/src/Providers/WordPressServiceProvider.php',
    ];

    foreach ($legacy as $path) {
        $runner->assertFalse(file_exists($projectRoot . $path), 'Legacy file still present: ' . $path);
    }

    $bridgeInterface = (string) file_get_contents($projectRoot . '/src/Bridge/Contracts/BridgeInterface.php');
    $runner->assertContains('namespace VGT\\Omega\\Bridge\\Contracts;', $bridgeInterface);
});

$runner->test('Version and license identity are consistent across artifacts', function () use ($runner, $projectRoot): void {
    $runner->assertSame('4.2.3', trim((string) file_get_contents($projectRoot . '/VERSION')));

    $checks = [
        '/vgt-os.php' => 'Version:           4.2.3',
        '/src/Bootstrap/Plugin.php' => "VERSION = '4.2.3'",
        '/src/Bootstrap/Plugin.php' => 'AGPL-3.0-or-later',
        '/composer.json' => '"license": "AGPL-3.0-or-later"',
    ];

    foreach ($checks as $path => $needle) {
        $content = (string) file_get_contents($projectRoot . $path);
        $runner->assertContains($needle, $content, 'Identity mismatch in ' . $path);
    }

    $license = (string) file_get_contents($projectRoot . '/LICENSE');
    $runner->assertContains('GNU AFFERO GENERAL PUBLIC LICENSE', $license);

    $readme = $projectRoot . '/README.md';
    if (is_file($readme)) {
        $readmeContent = (string) file_get_contents($readme);
        $runner->assertContains('4.2.3', $readmeContent);
        $runner->assertContains('GeDefense WPR', $readmeContent);
        $runner->assertContains('assets/ge-defense.png', $readmeContent);
        $runner->assertContains('formerly VGT Omega', $readmeContent);
        $runner->assertNotContains('<h1 align="center">VGT Omega</h1>', $readmeContent);
        $runner->assertNotContains('MIT License', $readmeContent);
    }

    $runner->assertTrue(is_file($projectRoot . '/assets/ge-defense.png'), 'Official GeDefense logo is missing.');

    $overview = (string) file_get_contents($projectRoot . '/src/UI/Dashboard/Sections/OverviewSection.php');
    $runner->assertContains('vgt-seal__logo', $overview);
    $runner->assertContains('ConsoleData::PRODUCT_NAME', $overview);
    $runner->assertNotContains('vgt-seal__glyph', $overview);
});

$runner->test('Composer manifest is valid and exposes the PSR-4 root', function () use ($runner, $projectRoot): void {
    $json = (string) file_get_contents($projectRoot . '/composer.json');
    $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

    $runner->assertSame('AGPL-3.0-or-later', $data['license']);
    $runner->assertSame('src/', $data['autoload']['psr-4']['VGT\\Omega\\']);
    $runner->assertContains('>=8.1', $data['require']['php']);
});

$runner->test('Artifact specification and security documentation exist and are linked', function () use ($runner, $projectRoot): void {
    foreach (['/ARTIFACT_SPEC.md', '/SECURITY.md', '/CHANGELOG.md', '/docs/VERIFICATION.md'] as $doc) {
        $runner->assertTrue(is_file($projectRoot . $doc), 'Missing document: ' . $doc);
    }

    $readme = (string) file_get_contents($projectRoot . '/README.md');
    foreach (['ARTIFACT_SPEC.md', 'SECURITY.md', 'LICENSE', 'legal.md'] as $linked) {
        $runner->assertContains('(' . $linked . ')', $readme, 'README does not link ' . $linked);
        $runner->assertTrue(is_file($projectRoot . '/' . $linked), 'Linked file missing: ' . $linked);
    }

    $spec = (string) file_get_contents($projectRoot . '/ARTIFACT_SPEC.md');
    foreach (['VGT_GUARD', 'AES-256-GCM', 'HKDF-SHA256', 'AAD', 'vgt_manifest.vgt', 'VGT_GZ', 'VGT_RAW', 'Ed25519'] as $needle) {
        $runner->assertContains($needle, $spec, 'ARTIFACT_SPEC.md misses ' . $needle);
    }
});

$runner->test('UI assets are local and contain no external CDN runtime dependency', function () use ($runner, $projectRoot): void {
    $assets = glob($projectRoot . '/src/UI/Dashboard/assets/*') ?: [];
    $runner->assertTrue($assets !== [], 'No UI assets found.');

    // Scan real runtime references, not prose comments (a comment may say "no CDN").
    $runtimePatterns = [
        '/url\(\s*[\'"]?https?:\/\//i',
        '/@import\s+(?:url\()?\s*[\'"]?https?:\/\//i',
        '/\bcdnjs\b/i',
        '/\bcdn\.jsdelivr\b/i',
        '/\bunpkg\.com\b/i',
        '/\bgoogleapis\.com\b/i',
        '/\bfonts\.google\b/i',
        '/\btailwindcss\.com\b/i',
        '/<script[^>]+src=[\'"]https?:\/\//i',
        '/<link[^>]+href=[\'"]https?:\/\//i',
    ];

    foreach ($assets as $asset) {
        $content = (string) file_get_contents($asset);
        foreach ($runtimePatterns as $pattern) {
            $runner->assertFalse(
                preg_match($pattern, $content) === 1,
                'External CDN runtime reference in ' . basename($asset) . ' (pattern ' . $pattern . ')'
            );
        }
    }
});

$runner->test('No emoji icon substitutes in production PHP output', function () use ($runner, $sourceFiles): void {
    $violations = [];
    foreach ($sourceFiles as $file) {
        $content = (string) file_get_contents($file);
        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $content) === 1) {
            $violations[] = basename($file);
        }
    }

    $runner->assertSame([], $violations, 'Emoji found in: ' . implode(', ', $violations));
});

$runner->test('Every production PHP file passes php -l', function () use ($runner, $sourceFiles): void {
    if (!function_exists('shell_exec') || vgt_run_command(escapeshellarg(PHP_BINARY) . ' -v') === null) {
        $runner->skip('php -l production gate', 'shell_exec unavailable');

        return;
    }

    $failures = [];
    foreach ($sourceFiles as $file) {
        $output = vgt_run_command(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file));
        if ($output === null || !str_contains($output, 'No syntax errors')) {
            $failures[] = basename($file) . ' => ' . trim((string) $output);
        }
    }

    $runner->assertSame([], $failures, 'php -l failures: ' . implode(' | ', $failures));
});
