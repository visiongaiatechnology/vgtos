<?php
declare(strict_types=1);

namespace VGT\Omega\System;

/**
 * Überwacht und injiziert den MU-Kernel in die WordPress-Umgebung.
 * STATUS: DIAMANT VGT SUPREME (Atomic Write, Namespace-Safe, Zero-Downtime)
 */
final class MuDeployer {
    
    public static function deploy(): void {
        $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        $kernel_file = wp_normalize_path($mu_dir . '/vgt-omega-kernel.php');
        
        $payload = self::getKernelPayload();
        
        // VGT APEX: SHA-256 statt MD5 für kryptografische Konsistenz
        if (!file_exists($kernel_file) || hash_file('sha256', $kernel_file) !== hash('sha256', $payload)) {
            if (!\wp_is_writable($mu_dir) && !\wp_mkdir_p($mu_dir)) {
                return; // Silent fail falls keine Rechte (CI/CD Environment)
            }
            
            // [ DIAMANT VGT FIX: ATOMIC WRITE ]
            // Verhindert Parse Errors/WSOD, wenn der Server exakt während des Schreibens den MU-Kernel lädt.
            $tmp_file = $kernel_file . '.' . uniqid('tmp_') . '.php';
            if (@file_put_contents($tmp_file, $payload) !== false) {
                @chmod($tmp_file, 0644);
                @rename($tmp_file, $kernel_file); // rename() ist auf OS-Ebene atomar
            }
        }
    }

    private static function getKernelPayload(): string {
        // [ DIAMANT VGT FIX: CORRECT BINDING ] 
        // Bindet die tatsächliche Hauptdatei an (vgt-os.php)
        $plugin_path = wp_normalize_path(VGT_OMEGA_DIR . '/vgt-os.php');
        
        return "<?php
/**
 * Plugin Name: VGT OMEGA KERNEL (AUTO-GENERATED)
 * Description: Bootstrapper & Stream Wrapper für VGT Artifacts.
 * Version: 4.0.0
 * Status: VGT DIAMANT SUPREME
 */
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

// 1. Lade Bridge Infrastruktur (Autoloader & DI) VOR allen Plugins
\$bridge_core = '{$plugin_path}';
if (file_exists(\$bridge_core)) {
    require_once \$bridge_core;
    
    // 2. Initialisiere Container
    \call_user_func(static function() {
        // [ DIAMANT VGT FIX: NAMESPACE MATCHING ]
        // Container liegt im Bridge-Namespace, referenziert exakt die gehärtete Klasse
        \$container = \VGT\Bridge\Core\Container::getInstance();
        
        if (!\$container->has(\VGT\Omega\Contracts\BridgeInterface::class)) {
            if (class_exists(\VGT\Omega\Providers\WordPressServiceProvider::class)) {
                \$provider = new \VGT\Omega\Providers\WordPressServiceProvider();
                \$provider->register(\$container);
            }
        }
        \$GLOBALS['vgt_container'] = \$container;
    });

    // 3. Boot Artifact Vault (Mount vgt:// and load apps)
    if (class_exists(\VGT\Omega\System\VaultManager::class)) {
        \add_action('muplugins_loaded', [\VGT\Omega\System\VaultManager::class, 'boot'], 1);
    }
}
";
    }
}
