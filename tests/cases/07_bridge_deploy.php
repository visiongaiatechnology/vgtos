<?php
/**
 * Layer-0 deployment, host providers, facade integration and safe-mode kill switch.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Admin\ArtifactController;
use VGT\Omega\Bootstrap\Plugin;
use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Bridge\Core\Container;
use VGT\Omega\Bridge\Providers\StandaloneServiceProvider;
use VGT\Omega\System\Deployment\MuDeployer;
use VGT\Omega\System\Deployment\MuKernelPayload;
use VGT\Omega\System\Vault\Storage\VaultLocator;
use VGT\Omega\System\Vault\VaultManager;

/** @var TestRunner $runner */
$projectRoot = VGT_TEST_PROJECT_ROOT;

$runner->test('Source identity is unified at version 4.2.1', function () use ($runner, $projectRoot): void {
    $runner->assertSame('4.2.1', Plugin::VERSION);

    $boot = (string) file_get_contents($projectRoot . '/vgt-os.php');
    $runner->assertContains('Version:           4.2.1', $boot);
    $runner->assertContains('License:           AGPL-3.0-or-later', $boot);
    $runner->assertNotContains('4.0.0', $boot);
    $runner->assertNotContains('4.1.0', $boot);
    $runner->assertNotContains('Permission is hereby granted, free of charge', $boot);
});

$runner->test('Vault locator isolates the vault outside the document root', function () use ($runner): void {
    $harness = new VaultHarness();
    $runner->assertTrue($harness->paths->isolated);
    $runner->assertSame(
        str_replace('\\', '/', $harness->baseDir . '/.vgt-vault'),
        str_replace('\\', '/', $harness->paths->vaultDir)
    );
    $runner->assertFalse(str_starts_with(
        str_replace('\\', '/', $harness->paths->vaultDir),
        str_replace('\\', '/', $harness->documentRoot)
    ));
});

$runner->test('Vault lockdown writes Apache, IIS and index deny files', function () use ($runner): void {
    $harness = new VaultHarness();
    $vault = $harness->paths->vaultDir;

    $htaccess = (string) file_get_contents($vault . '/.htaccess');
    $runner->assertContains('Require all denied', $htaccess);
    $runner->assertContains('Deny from all', $htaccess);
    $runner->assertContains('mod_authz_core', $htaccess);

    $webConfig = (string) file_get_contents($vault . '/web.config');
    $runner->assertContains('<deny users="*"', $webConfig);

    $index = (string) file_get_contents($vault . '/index.php');
    $runner->assertContains('403', $index);
});

$runner->test('Generated layer-0 kernel uses var_export and passes php -l', function () use ($runner, $projectRoot): void {
    $pluginFile = $projectRoot . '/vgt-os.php';
    $payload = MuKernelPayload::build($pluginFile, Plugin::VERSION);

    $runner->assertContains(var_export(str_replace('\\', '/', $pluginFile), true), $payload);
    $runner->assertContains('VGT_OMEGA_MU_KERNEL', $payload);
    $runner->assertContains('VGT_OMEGA_LAYER_ZERO', $payload);
    $runner->assertContains('Version: 4.2.1', $payload);
    $runner->assertContains('AGPL-3.0-or-later', $payload);
    $runner->assertNotContains('{$plugin_path}', $payload);
    $runner->assertNotContains("'{\$plugin", $payload);

    $tmp = vgt_test_temp_dir('kernel') . '/kernel.php';
    file_put_contents($tmp, $payload);

    $output = vgt_run_command(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp));
    if ($output === null) {
        $runner->skip('Generated kernel php -l', 'shell_exec unavailable');
    } else {
        $runner->assertContains('No syntax errors', $output);
    }
});

$runner->test('MuDeployer publishes the kernel atomically and idempotently', function () use ($runner): void {
    if (!defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException('WPMU_PLUGIN_DIR missing.');
    }

    MuDeployer::deploy();
    $kernel = rtrim(str_replace('\\', '/', WPMU_PLUGIN_DIR), '/') . '/vgt-omega-kernel.php';
    $runner->assertTrue(is_file($kernel));
    $firstHash = hash_file('sha256', $kernel);

    MuDeployer::deploy();
    $runner->assertSame($firstHash, hash_file('sha256', $kernel));

    $temps = glob(rtrim(str_replace('\\', '/', WPMU_PLUGIN_DIR), '/') . '/*.tmp') ?: [];
    $runner->assertSame([], $temps);

    $content = (string) file_get_contents($kernel);
    $runner->assertContains('VGT_OMEGA_MU_KERNEL', $content);
    $runner->assertNotContains('{$plugin_path}', $content);

    $output = vgt_run_command(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($kernel));
    if ($output !== null) {
        $runner->assertContains('No syntax errors', $output);
    }
});

$runner->test('Standalone provider satisfies the bridge and environment contracts', function () use ($runner): void {
    $base = vgt_test_temp_dir('provider');
    $environment = new \VGT\Omega\Bridge\Environments\StandaloneEnvironment($base);
    $container = Container::getInstance();

    if (!$container->has(EnvironmentInterface::class)) {
        (new StandaloneServiceProvider($environment))->register($container);
    }

    $runner->assertInstanceOf(EnvironmentInterface::class, $container->get(EnvironmentInterface::class));
    $runner->assertInstanceOf(BridgeInterface::class, $container->get(BridgeInterface::class));
    $runner->assertInstanceOf(\VGT\Omega\Bridge\Adapters\StandaloneAdapter::class, $container->get(BridgeInterface::class));
});

$runner->test('VaultManager boots, mounts artifacts and exposes diagnostics', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin(), 'plugin.php', 'Facade Artifact');

    VaultManager::boot($harness->bridge, $harness->environment);
    $runner->assertTrue(VaultManager::isBooted());
    $runner->assertSame(null, VaultManager::bootError());
    $runner->assertSame([$installed['id']], VaultManager::artifacts());
    $runner->assertTrue(VaultManager::isUnlocked($installed['id']));

    $diagnostics = VaultManager::diagnostics();
    foreach (['environment', 'crypto', 'storage', 'memory', 'runtime'] as $section) {
        $runner->assertTrue(isset($diagnostics[$section]), 'Missing diagnostics section: ' . $section);
    }

    $runner->assertSame(1, $diagnostics['storage']['artifacts']);
    $runner->assertSame(1, $diagnostics['runtime']['mount_count']);
    $runner->assertTrue($diagnostics['crypto']['aad_binding']);
    $runner->assertSame('vgt-artifact/4.2', $diagnostics['crypto']['format']);

    // Boot is idempotent.
    VaultManager::boot($harness->bridge, $harness->environment);
    $runner->assertSame(1, count(VaultManager::artifacts()));
});

$runner->test('ArtifactController notice transport returns and clears exactly once', function () use ($runner): void {
    $key = ArtifactController::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
    set_transient($key, ['type' => 'error', 'message' => 'Rejected.', 'reference' => 'VGT-ERR-AAAAAAAA'], 60);

    $notice = ArtifactController::pullNotice();
    $runner->assertSame('error', $notice['type']); // @phpstan-ignore-line
    $runner->assertSame('Rejected.', $notice['message']); // @phpstan-ignore-line
    $runner->assertSame(null, ArtifactController::pullNotice());
});

$runner->test('Safe mode disables mounting but keeps the vault manageable', function () use ($runner): void {
    $script = __DIR__ . '/../isolated/safe_mode.php';
    $output = vgt_run_command(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
    if ($output === null) {
        $runner->skip('Safe mode subprocess', 'shell_exec unavailable');

        return;
    }

    $runner->assertContains('SAFE_MODE_OK', $output);
});

$runner->test('WordPress-shaped bootstrap wires environment, bridge, facade and console', function () use ($runner): void {
    $script = __DIR__ . '/../isolated/wp_boot.php';
    $output = vgt_run_command(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
    if ($output === null) {
        $runner->skip('WordPress-shaped bootstrap subprocess', 'shell_exec unavailable');

        return;
    }

    $runner->assertContains('WP_BOOT_OK', $output);
    $runner->assertContains('platform=wordpress / plugin', $output);
    $runner->assertContains('format=vgt-artifact/4.2', $output);
    $runner->assertContains('version=4.2.1', $output);
});
