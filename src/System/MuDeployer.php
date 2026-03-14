<?php
declare(strict_types=1);

namespace VGT\Omega\System;

/**
 * Überwacht und injiziert den MU-Kernel in die WordPress-Umgebung.
 */
final class MuDeployer {
    
    public static function deploy(): void {
        $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        $kernel_file = $mu_dir . '/vgt-omega-kernel.php';
        
        $payload = self::getKernelPayload();
        
        if (!file_exists($kernel_file) || md5_file($kernel_file) !== md5($payload)) {
            if (!\wp_is_writable($mu_dir) && !\wp_mkdir_p($mu_dir)) {
                return; // Silent fail falls keine Rechte (CI/CD Environment)
            }
            @file_put_contents($kernel_file, $payload);
        }
    }

    private static function getKernelPayload(): string {
        $plugin_path = VGT_OMEGA_DIR . '/vgt-omega-system.php';
        
        return "<?php
/**
 * Plugin Name: VGT OMEGA KERNEL (AUTO-GENERATED)
 * Description: Bootstrapper & Stream Wrapper für VGT Artifacts.
 * Version: 4.0.0
 */
declare(strict_types=1);

if (!defined('ABSPATH')) exit;

// 1. Lade Bridge Infrastruktur (Autoloader & DI) VOR allen Plugins
\$bridge_core = '{$plugin_path}';
if (file_exists(\$bridge_core)) {
    require_once \$bridge_core;
    
    // 2. Initialisiere Container
    \call_user_func(function() {
        \$container = \VGT\Omega\Core\Container::getInstance();
        if (!\$container->has(\VGT\Omega\Contracts\BridgeInterface::class)) {
            \$provider = new \VGT\Omega\Providers\WordPressServiceProvider();
            \$provider->register(\$container);
        }
        \$GLOBALS['vgt_container'] = \$container;
    });

    // 3. Boot Artifact Vault (Mount vgt:// and load apps)
    add_action('muplugins_loaded', [\VGT\Omega\System\VaultManager::class, 'boot'], 1);
}
";
    }
}