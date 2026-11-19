<?php
/**
 * Archive hardening: zip-slip, symlinks, bombs, entry limits, safe extraction.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Storage\SafeArchive;

/** @var TestRunner $runner */
$makeZip = static function (callable $builder): string {
    $path = vgt_test_temp_dir('zip') . '/fixture.zip';
    $zip = new \ZipArchive();

    if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException('Could not create test zip.');
    }

    $builder($zip);

    if (!$zip->close()) {
        throw new \RuntimeException('Could not close test zip.');
    }

    return $path;
};

$runner->test('SafeArchive inspects and extracts a benign archive', function () use ($runner, $makeZip): void {
    $zipPath = $makeZip(static function (\ZipArchive $zip): void {
        $zip->addFromString('plugin.php', '<?php echo "ok";');
        $zip->addFromString('assets/app.css', 'body{margin:0}');
    });

    $archive = new SafeArchive();
    $inspection = $archive->inspect($zipPath);
    $runner->assertSame(2, count($inspection['entries']));
    $runner->assertTrue($inspection['total_uncompressed'] > 0);

    $target = vgt_test_temp_dir('extract');
    $archive->extract($zipPath, $target);
    $runner->assertTrue(is_file($target . '/plugin.php'));
    $runner->assertSame('<?php echo "ok";', file_get_contents($target . '/plugin.php'));
    $runner->assertTrue(is_file($target . '/assets/app.css'));
    $runner->assertFalse(is_link($target . '/plugin.php'));
});

$runner->test('SafeArchive rejects traversal entries before extraction', function () use ($runner, $makeZip): void {
    $zipPath = $makeZip(static function (\ZipArchive $zip): void {
        $zip->addFromString('plugin.php', '<?php echo 1;');
        $zip->addFromString('../evil.php', '<?php evil();');
    });

    $archive = new SafeArchive();
    $runner->assertThrows(fn () => $archive->inspect($zipPath), SecurityException::class);

    $target = vgt_test_temp_dir('extract-traversal');
    $runner->assertThrows(fn () => $archive->extract($zipPath, $target), SecurityException::class);
    $runner->assertFalse(is_file(dirname($target) . '/evil.php'));
});

$runner->test('SafeArchive rejects backslash traversal and absolute paths', function () use ($runner, $makeZip): void {
    $backslashZip = $makeZip(static function (\ZipArchive $zip): void {
        $zip->addFromString('safe\\..\\..\\evil.php', '<?php evil();');
    });

    $absoluteZip = $makeZip(static function (\ZipArchive $zip): void {
        $zip->addFromString('plugin.php', '<?php echo 1;');
        $zip->addFromString('/etc/passwd', 'root:x:0:0');
    });

    $archive = new SafeArchive();
    $runner->assertThrows(fn () => $archive->inspect($backslashZip), SecurityException::class);
    $runner->assertThrows(fn () => $archive->inspect($absoluteZip), SecurityException::class);
});

$runner->test('SafeArchive rejects symlink entries', function () use ($runner, $makeZip): void {
    if (!defined('ZipArchive::OPSYS_UNIX')) {
        $runner->skip('SafeArchive symlink entry', 'ZipArchive::OPSYS_UNIX unavailable');

        return;
    }

    $zipPath = $makeZip(static function (\ZipArchive $zip): void {
        $zip->addFromString('plugin.php', '<?php echo 1;');
        $zip->addFromString('link.php', '/etc/passwd');
        $zip->setExternalAttributesName('link.php', \ZipArchive::OPSYS_UNIX, (0xA000 | 0777) << 16);
    });

    $archive = new SafeArchive();
    $runner->assertThrows(fn () => $archive->inspect($zipPath), SecurityException::class);
});

$runner->test('SafeArchive rejects compression bombs', function () use ($runner, $makeZip): void {
    $zipPath = $makeZip(static function (\ZipArchive $zip): void {
        $zip->addFromString('plugin.php', '<?php echo 1;');
        $zip->addFromString('bomb.bin', str_repeat("\0", 1048576));
    });

    $archive = new SafeArchive();
    $runner->assertThrows(fn () => $archive->inspect($zipPath), SecurityException::class);
});

$runner->test('SafeArchive rejects archives above the entry-count limit', function () use ($runner, $makeZip): void {
    $zipPath = $makeZip(static function (\ZipArchive $zip): void {
        for ($i = 0; $i < 1001; $i++) {
            $zip->addFromString('files/file-' . $i . '.txt', 'x');
        }
    });

    $archive = new SafeArchive();
    $runner->assertThrows(
        fn () => $archive->inspect($zipPath),
        \VGT\Omega\Core\Exceptions\AppException::class
    );
});
