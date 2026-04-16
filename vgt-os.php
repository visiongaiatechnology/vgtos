<?php
/**
 * Plugin Name: VGT OMEGA SYSTEM
 * Plugin URI: https://visiongaiatechnology.de
 * Description: VGT Universal Bridge & Encrypted Loader Fusion. Platin Status.
 * Version: 4.1.0
 * Author: VisionGaia Technology
 # LICENSE: AGPLv3 (OPEN SOURCE) - GLOBAL PROLIFERATION PROTOCOL
# ==============================================================================
# 
# Copyright (c) 2026 VISIONGAIATECHNOLOGY
# 
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
# 
# The above copyright notice and this permission notice shall be included in all
# copies or substantial portions of the Software.
# 
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.
# ==============================================================================
 */

declare(strict_types=1);

namespace VGT\Omega;

if (!defined('ABSPATH')) {
    exit('VGT_ACCESS_DENIED');
}

// Guard: Verhindert doppeltes Laden und sichert den globalen Status.
if (defined('VGT_OMEGA_LOADED')) {
    return;
}
define('VGT_OMEGA_LOADED', true);
define('VGT_OMEGA_DIR', __DIR__);

/* ====================================================================
 * 1. O(1) MEMORY CACHED PSR-4 AUTOLOADER (DIAMANT SHIELDED)
 * ==================================================================== */
spl_autoload_register(static function (string $class) {
    static $classMap = [];

    // [ DIAMANT VGT FIX: O(1) MEMORY CACHE ]
    // Kein erneutes require_once, wenn die Klasse bereits registriert ist.
    if (isset($classMap[$class])) {
        return;
    }

    // [ DIAMANT VGT FIX: ANTI-LFI & FILESYSTEM SCANNING GUARD ]
    // Verhindert, dass dynamische Instanziierungen (new $_GET['x']()) das Dateisystem absuchen.
    if (preg_match('/[^a-zA-Z0-9_\\\\]/', $class)) {
        return;
    }

    $prefixOmega = 'VGT\\Omega\\';
    $prefixLegacy = 'VGT\\Bridge\\';

    if (str_starts_with($class, $prefixOmega)) {
        $relative_class = substr($class, strlen($prefixOmega));
    } elseif (str_starts_with($class, $prefixLegacy)) {
        $relative_class = substr($class, strlen($prefixLegacy));
    } else {
        return;
    }
    
    $file = VGT_OMEGA_DIR . '/src/' . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        $classMap[$class] = true; // State speichern, CPU entlasten
        require_once $file;
    }
});

/* ====================================================================
 * 2. SYSTEM INIT & UI HOOKS (RACE-CONDITION SECURED)
 * ==================================================================== */
$vgt_boot_routine = static function() {
    // Idempotenz-Guard: Exakt ein Boot-Vorgang pro Request.
    if (defined('VGT_OMEGA_BOOTED')) return;
    define('VGT_OMEGA_BOOTED', true);

    // VGT SUPREME FIX: Fallback-Boot. 
    // Garantiert das Einhängen der Entschlüsselung in den RAM.
    \VGT\Omega\System\VaultManager::boot();

    // UI & Installer Registrierung (nur im Backend relevant)
    if (\is_admin()) {
        \VGT\Omega\UI\Dashboard::init();
    }
};

// [ DIAMANT VGT FIX: LIFECYCLE PARADOX RESOLUTION ]
// Fängt den Boot ab, egal ob als Standard-Plugin, MU-Plugin oder späte Injektion.
if (\did_action('plugins_loaded')) {
    $vgt_boot_routine();
} else {
    \add_action('plugins_loaded', $vgt_boot_routine, 5); // Priority 5: Vor dem WP Core
}

/* ====================================================================
 * 3. ATOMIC MU-KERNEL DEPLOYMENT
 * ==================================================================== */
\add_action('admin_init', [\VGT\Omega\System\MuDeployer::class, 'deploy']);
