<?php
/**
 * VGT Omega console — local asset loading.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard;

use VGT\Omega\Bridge\Contracts\BridgeInterface;

/**
 * Enqueues exactly one CSS file and one JS file from the plugin itself.
 *
 * Zero external runtime dependencies: no CDN, no web font, no icon font, no
 * framework. The version string is the plugin version so a release always
 * busts the browser cache.
 */
final class Assets
{
    public const CSS_HANDLE = 'vgt-omega-glass-2100';

    public const JS_HANDLE = 'vgt-omega-console';

    private const CSS_RELATIVE = 'src/UI/Dashboard/assets/glass-2100.css';

    private const JS_RELATIVE = 'src/UI/Dashboard/assets/console.js';

    public static function enqueue(BridgeInterface $bridge): void
    {
        if (!function_exists('plugins_url')) {
            return;
        }

        $pluginFile = defined('VGT_OMEGA_FILE') ? (string) constant('VGT_OMEGA_FILE') : '';
        if ($pluginFile === '') {
            return;
        }

        $version = defined('VGT_OMEGA_VERSION') ? (string) constant('VGT_OMEGA_VERSION') : '4.2.0';

        $bridge->enqueueCss(self::CSS_HANDLE, plugins_url(self::CSS_RELATIVE, $pluginFile), [], $version);
        $bridge->enqueueJs(self::JS_HANDLE, plugins_url(self::JS_RELATIVE, $pluginFile), [], $version, true);
    }
}
