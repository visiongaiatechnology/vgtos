<?php
/**
 * Isolated subprocess: WordPress-shaped bootstrap of the real plugin entry point.
 *
 * This does not boot a real WordPress installation (none is available); it uses
 * the test host stubs from tests/bootstrap.php to exercise the actual WordPress
 * environment adapter, the service provider, the bootstrap composition root and
 * the vault facade together.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

// WordPress salts exist before the plugin boots (normally in wp-config.php).
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'wp-test-auth-key-0123456789abcdef0123456789');
    define('SECURE_AUTH_KEY', 'wp-test-secure-key-0123456789abcdef012345');
    define('LOGGED_IN_KEY', 'wp-test-logged-in-key-0123456789abcdef0123');
    define('NONCE_KEY', 'wp-test-nonce-key-0123456789abcdef0123456');
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return true;
    }
}

// Give the environment adapter a document root that does not contain ABSPATH so
// the vault locator can prove out-of-webroot isolation in this subprocess.
$documentRoot = VGT_TEST_TMP_ROOT . '/public_html';
if (!is_dir($documentRoot)) {
    mkdir($documentRoot, 0700, true);
}
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;

// The real plugin entry point (plugin header + autoloader + Plugin::boot).
require VGT_TEST_PROJECT_ROOT . '/vgt-os.php';

\VGT\Omega\Bootstrap\Plugin::bootKernel();

$problems = [];

if (!\VGT\Omega\System\Vault\VaultManager::isBooted()) {
    $problems[] = 'vault-not-booted:' . (string) \VGT\Omega\System\Vault\VaultManager::bootError();
}

$container = \VGT\Omega\Bootstrap\Plugin::container();

if (!($container->get(\VGT\Omega\Bridge\Contracts\BridgeInterface::class) instanceof \VGT\Omega\Bridge\Adapters\WordPressAdapter)) {
    $problems[] = 'wrong-bridge-adapter';
}

if ($container->get(\VGT\Omega\Bridge\Contracts\EnvironmentInterface::class)->getPlatformName() !== 'wordpress') {
    $problems[] = 'wrong-environment';
}

try {
    $diagnostics = \VGT\Omega\System\Vault\VaultManager::diagnostics();
} catch (\Throwable $error) {
    $problems[] = 'diagnostics-failed:' . $error->getMessage();
    $diagnostics = [];
}

if (($diagnostics['environment']['platform'] ?? '') === '') {
    $problems[] = 'missing-platform-diagnostics';
}

if ((\VGT\Omega\Bootstrap\Plugin::bootError() ?? '') !== '') {
    $problems[] = 'bootstrap-error:' . (string) \VGT\Omega\Bootstrap\Plugin::bootError();
}

if ($problems !== []) {
    fwrite(STDERR, 'WP_BOOT_FAIL ' . implode(' | ', $problems) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'WP_BOOT_OK platform=' . $diagnostics['environment']['platform']
    . ' format=' . $diagnostics['crypto']['format']
    . ' version=' . \VGT\Omega\System\Vault\VaultManager::version() . PHP_EOL);
exit(0);
