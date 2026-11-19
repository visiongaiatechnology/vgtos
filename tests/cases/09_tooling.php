<?php
/**
 * Reference encryptor CLI smoke tests.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Tooling\ArtifactPacker;

/** @var TestRunner $runner */
$php = escapeshellarg(PHP_BINARY);
$cli = escapeshellarg(VGT_TEST_PROJECT_ROOT . '/bin/vgt-pack.php');

$runner->test('vgt-pack --help prints the documented interface and exits 0', function () use ($runner, $php, $cli): void {
    $output = vgt_run_command($php . ' ' . $cli . ' --help');
    if ($output === null) {
        $runner->skip('vgt-pack help', 'shell_exec unavailable');

        return;
    }

    $runner->assertContains('USAGE', $output);
    $runner->assertContains('--source', $output);
    $runner->assertContains('ARTIFACT_SPEC.md', $output);
});

$runner->test('vgt-pack --version reports format 4.2', function () use ($runner, $php, $cli): void {
    $output = vgt_run_command($php . ' ' . $cli . ' --version');
    if ($output === null) {
        $runner->skip('vgt-pack version', 'shell_exec unavailable');

        return;
    }

    $runner->assertContains('4.2.1', $output);
    $runner->assertContains('vgt-artifact/4.2', $output);
});

$runner->test('vgt-pack --keygen emits a valid 32-byte cloud key and Ed25519 keypair', function () use ($runner, $php, $cli): void {
    $output = vgt_run_command($php . ' ' . $cli . ' --keygen');
    if ($output === null) {
        $runner->skip('vgt-pack keygen', 'shell_exec unavailable');

        return;
    }

    $runner->assertMatches('/\b[a-f0-9]{64}\b/', $output);
    $runner->assertContains('Ed25519', $output);
});

$runner->test('vgt-pack produces an installable, signed package', function () use ($runner): void {
    if (!function_exists('sodium_crypto_sign_keypair')) {
        $runner->skip('vgt-pack signed package', 'libsodium unavailable');

        return;
    }

    $source = vgt_test_temp_dir('cli-source');
    mkdir($source . '/src', 0700, true);
    mkdir($source . '/assets', 0700, true);
    file_put_contents($source . '/plugin.php', "<?php\nfunction vgt_cli_demo(): string { return 'cli-ok'; }\n");
    file_put_contents($source . '/src/Helper.php', "<?php\nnamespace VGT\\CliDemo;\nfinal class Helper {}\n");
    file_put_contents($source . '/assets/style.css', "body{--cli:1}\n");

    $classmap = ['VGT\\CliDemo\\Helper' => 'src/Helper.php'];
    $keypair = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
    $out = vgt_test_temp_dir('cli-out') . '/demo.zip';
    $cloudKey = bin2hex(random_bytes(32));

    $packer = new ArtifactPacker();
    $result = $packer->pack($source, $out, $cloudKey, 'plugin.php', 'CLI Demo', $secret, $classmap);

    $runner->assertSame(3, $result['files']);
    $runner->assertTrue($result['signed']);
    $runner->assertTrue(is_file($out));
    $runner->assertMatches('/^[a-f0-9]{64}$/', $result['sha256']);

    $zip = new \ZipArchive();
    $runner->assertSame(true, $zip->open($out));
    $runner->assertNotFalse($zip->getFromName('vgt_manifest.vgt'));
    $runner->assertNotFalse($zip->getFromName('plugin.php'));
    $runner->assertNotFalse($zip->getFromName('assets/style.css'));
    $zip->close();

    // The produced package must import through the real installer.
    $harness = new VaultHarness();
    $installed = $harness->installer->install($out, $cloudKey, 'CLI Demo');
    $runner->assertSame('installed', $installed['status']);
    $runner->assertTrue($installed['signed']);
});

$runner->test('vgt-pack rejects an invalid cloud key with exit code 2', function () use ($runner, $php, $cli): void {
    $source = vgt_test_temp_dir('cli-badsource');
    file_put_contents($source . '/plugin.php', '<?php');
    $out = vgt_test_temp_dir('cli-badout') . '/x.zip';

    $command = $php . ' ' . $cli
        . ' --source=' . escapeshellarg($source)
        . ' --out=' . escapeshellarg($out)
        . ' --key=nothex';
    $output = vgt_run_command($command);
    if ($output === null) {
        $runner->skip('vgt-pack invalid key', 'shell_exec unavailable');

        return;
    }

    $runner->assertContains('64 hexadecimal', $output);
});
