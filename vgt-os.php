<?php
/**
 * Plugin Name: VGT OMEGA SYSTEM
 * Plugin URI: https://visiongaiatechnology.de
 * Description: VGT Universal Bridge & Encrypted Loader Fusion. Platin Status.
 * Version: 4.0.0
 * Author: VisionGaia Technology
 */

declare(strict_types=1);

namespace VGT\Omega;

if (!defined('ABSPATH')) {
    exit;
}

// Guard: Verhindert doppeltes Laden, da das MU-Plugin diese Datei inkludiert.
if (defined('VGT_OMEGA_LOADED')) {
    return;
}
define('VGT_OMEGA_LOADED', true);
define('VGT_OMEGA_DIR', __DIR__);

/* ====================================================================
 * 1. O(1) MEMORY CACHED PSR-4 AUTOLOADER
 * ==================================================================== */
spl_autoload_register(function (string $class) {
    static $classMap = [];

    if (isset($classMap[$class])) {
        require_once $classMap[$class];
        return;
    }

    $prefix = 'VGT\\Omega\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        $legacyPrefix = 'VGT\\Bridge\\';
        if (strncmp($legacyPrefix, $class, strlen($legacyPrefix)) === 0) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($legacyPrefix))) . '.php';
            if (file_exists($file)) {
                $classMap[$class] = $file;
                require_once $file;
            }
        }
        return;
    }
    
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    
    if (file_exists($file)) {
        $classMap[$class] = $file;
        require_once $file;
    }
});

/* ====================================================================
 * 2. SYSTEM INIT & UI HOOKS
 * ==================================================================== */
\add_action('plugins_loaded', function() {
    // VGT SUPREME FIX: Fallback-Boot. 
    // Garantiert das Einhängen der Entschlüsselung in den RAM, selbst wenn 
    // das MU-Deployment (z.B. durch Server-Restriktionen oder Dead-Links) fehlschlägt.
    \VGT\Omega\System\VaultManager::boot();

    // UI & Installer Registrierung (nur im Backend relevant)
    if (\is_admin()) {
        \VGT\Omega\UI\Dashboard::init();
    }
}, 5); // Hohe Priorität, um State vor anderen Plugins aufzubauen

/* ====================================================================
 * 3. ATOMIC MU-KERNEL DEPLOYMENT
 * ==================================================================== */
\add_action('admin_init', [\VGT\Omega\System\MuDeployer::class, 'deploy']);