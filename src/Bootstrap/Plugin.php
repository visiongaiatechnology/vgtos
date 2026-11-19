<?php
/**
 * VGT Omega bootstrap plugin.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bootstrap;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Bridge\Core\Container;
use VGT\Omega\Bridge\Providers\WordPressServiceProvider;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\System\Deployment\MuDeployer;
use VGT\Omega\System\Vault\VaultManager;
use VGT\Omega\UI\Dashboard\Dashboard;

/**
 * Single composition root for the WordPress plugin lifecycle.
 *
 * Handles all loading orders (regular plugin, mu-plugin layer 0, late
 * injection) exactly once and never lets a boot failure produce a white screen.
 */
final class Plugin
{
    public const VERSION = '4.2.1';

    public const CODE_NAME = 'OMEGA';

    public const MIN_PHP_VERSION = '8.1.0';

    private static ?Container $container = null;

    private static ?string $bootError = null;

    public static function boot(string $mainFile): void
    {
        if (defined('VGT_OMEGA_LOADED')) {
            return;
        }

        define('VGT_OMEGA_LOADED', true);
        define('VGT_OMEGA_VERSION', self::VERSION);
        define('VGT_OMEGA_CODE_NAME', self::CODE_NAME);

        // Constants may already exist in embedded/test harnesses; never redefine.
        if (!defined('VGT_OMEGA_FILE')) {
            define('VGT_OMEGA_FILE', $mainFile);
        }

        if (!defined('VGT_OMEGA_DIR')) {
            define('VGT_OMEGA_DIR', dirname($mainFile));
        }

        if (!function_exists('add_action')) {
            // Loaded outside WordPress (CLI tooling / tests): nothing to hook.
            return;
        }

        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>'
                    . esc_html('VGT Omega requires PHP ' . Plugin::MIN_PHP_VERSION . ' or newer.')
                    . '</p></div>';
            });

            return;
        }

        if (defined('VGT_OMEGA_MU_KERNEL')) {
            if (did_action('muplugins_loaded')) {
                self::bootKernel();
            } else {
                add_action('muplugins_loaded', [self::class, 'bootKernel'], 1);
            }

            return;
        }

        if (did_action('plugins_loaded')) {
            self::bootKernel();
        } else {
            add_action('plugins_loaded', [self::class, 'bootKernel'], 5);
        }
    }

    public static function bootKernel(): void
    {
        if (defined('VGT_OMEGA_BOOTED')) {
            return;
        }

        define('VGT_OMEGA_BOOTED', true);

        try {
            $container = self::container();
            // Legacy integration point for host code that resolved VGT services
            // before the bridge existed. The container itself stays immutable.
            $GLOBALS['vgt_container'] = $container;
            $bridge = $container->get(BridgeInterface::class);
            $environment = $container->get(EnvironmentInterface::class);

            VaultManager::boot($bridge, $environment);

            if (function_exists('is_admin') && is_admin()) {
                Dashboard::init($bridge);
            }

            add_action('admin_init', [MuDeployer::class, 'deploy'], 5);
        } catch (\Throwable $error) {
            self::$bootError = ExceptionReporter::report($error, 'bootstrap');
        }
    }

    public static function container(): Container
    {
        if (self::$container instanceof Container) {
            return self::$container;
        }

        $container = Container::getInstance();
        (new WordPressServiceProvider())->register($container);
        self::$container = $container;

        return $container;
    }

    public static function resolve(string $id): object
    {
        return self::container()->get($id);
    }

    public static function bootError(): ?string
    {
        return self::$bootError;
    }

    /** @return array{name: string, version: string, code_name: string, php: string} */
    public static function identity(): array
    {
        return [
            'name' => 'VGT Omega System',
            'version' => self::VERSION,
            'code_name' => self::CODE_NAME,
            'php' => PHP_VERSION,
        ];
    }
}
