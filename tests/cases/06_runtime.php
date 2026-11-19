<?php
/**
 * Runtime mounting and the read-only vgt:// stream wrapper.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Core\Exceptions\SecurityException;

/** @var TestRunner $runner */
$runner->test('Mounter registers stream wrapper, mounts manifests and never falls back to plaintext', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin(), 'plugin.php', 'Runtime Demo');
    $stats = $harness->mount();

    $runner->assertArrayContains($installed['id'], $stats['mounted']);
    $runner->assertSame([], $stats['failed']);
    $runner->assertTrue($harness->registry->has($installed['id']));
    $runner->assertTrue(in_array('vgt', stream_get_wrappers(), true));
});

$runner->test('vgt:// stream wrapper returns authenticated plaintext from RAM', function () use ($runner): void {
    $harness = new VaultHarness();
    $files = VaultHarness::samplePlugin();
    $installed = $harness->installFiles($files);
    $harness->mount();

    $served = file_get_contents('vgt://' . $installed['id'] . '/plugin.php');
    $runner->assertSame($files['plugin.php'], $served);

    $css = file_get_contents('vgt://' . $installed['id'] . '/assets/sample.css');
    $runner->assertSame($files['assets/sample.css'], $css);
});

$runner->test('url_stat reports the real size and a stable manifest mtime', function () use ($runner): void {
    $harness = new VaultHarness();
    $files = VaultHarness::samplePlugin();
    $installed = $harness->installFiles($files);
    $harness->mount();

    $uri = 'vgt://' . $installed['id'] . '/plugin.php';
    $first = stat($uri);
    $second = stat($uri);

    $runner->assertTrue(is_array($first));
    $runner->assertSame(strlen($files['plugin.php']), $first['size']);
    $runner->assertSame($first['mtime'], $second['mtime']);
    $manifestMtime = filemtime($harness->paths->manifestPath($installed['id']));
    $runner->assertSame((int) $manifestMtime, (int) $first['mtime']);
});

$runner->test('vgt:// directory listing exposes virtual directories without filesystem paths', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin());
    $harness->mount();

    $handle = opendir('vgt://' . $installed['id'] . '/');
    $runner->assertTrue(is_resource($handle));

    $entries = [];
    while (($entry = readdir($handle)) !== false) {
        $entries[] = $entry;
    }
    closedir($handle);

    $runner->assertTrue(in_array('plugin.php', $entries, true));
    $runner->assertTrue(in_array('assets', $entries, true));
    $runner->assertNotContains('/', implode('', $entries));
});

$runner->test('vgt:// stream rejects traversal and unreadable entries', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin());
    $harness->mount();

    $id = $installed['id'];
    $attempts = [
        'vgt://' . $id . '/../vgt_manifest.vgt',
        'vgt://' . $id . '/../../etc/passwd',
        'vgt://' . $id . '/%2e%2e/%2e%2e/etc/passwd',
        'vgt://' . $id . '/missing.php',
        'vgt://vgt_aaaaaaaaaaaaaaaaaaaaaaaa/plugin.php',
    ];

    foreach ($attempts as $uri) {
        $failed = false;
        try {
            $content = @file_get_contents($uri);
            $failed = $content === false || $content === '';
        } catch (\Throwable) {
            $failed = true;
        }
        $runner->assertTrue($failed, 'Unexpected readable stream: ' . $uri);
    }
});

$runner->test('Classmap autoloader lazily decrypts classes through vgt://', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(
        VaultHarness::samplePlugin(),
        'plugin.php',
        'Classmap Demo',
        null,
        ['VGT\\Sample\\LazyKernel' => 'src/LazyKernel.php']
    );
    $harness->mount();

    $runner->assertTrue(class_exists('VGT\\Sample\\LazyKernel'));
    $runner->assertSame('lazy-ok', \VGT\Sample\LazyKernel::ping());
});

$runner->test('Tampered ciphertext is rejected at read time', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin());
    $harness->mount();

    $manifest = $harness->registry->get($installed['id'])['manifest'];
    $blobId = $manifest->file('plugin.php')['blob'];
    $blobPath = $harness->paths->blobPath($installed['id'], $blobId);
    $bytes = file_get_contents($blobPath);
    $tampered = substr((string) $bytes, 0, -1) . chr(ord(substr((string) $bytes, -1)) ^ 0xFF);
    file_put_contents($blobPath, $tampered);

    $failed = false;
    try {
        $content = @file_get_contents('vgt://' . $installed['id'] . '/plugin.php');
        $failed = $content === false;
    } catch (SecurityException) {
        $failed = true;
    }

    $runner->assertTrue($failed);
});

$runner->test('Artifacts whose manifest cannot be authenticated are locked, not executed', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin());

    // Corrupt the manifest container before mounting.
    $manifestPath = $harness->paths->manifestPath($installed['id']);
    $bytes = (string) file_get_contents($manifestPath);
    file_put_contents($manifestPath, substr($bytes, 0, -8));

    $stats = $harness->mount();
    $runner->assertSame([], $stats['mounted']);
    $runner->assertArrayContains($installed['id'], $stats['locked']);
    $runner->assertFalse($harness->registry->has($installed['id']));
});
