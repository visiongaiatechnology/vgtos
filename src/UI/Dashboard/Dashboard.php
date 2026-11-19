<?php
/**
 * GeDefense WPR console — WordPress admin dashboard entry point.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\UI\Dashboard\Components\ConfirmDialog;
use VGT\Omega\UI\Dashboard\Components\Icon;
use VGT\Omega\UI\Dashboard\Components\Notice;
use VGT\Omega\UI\Dashboard\Sections\DeploySection;
use VGT\Omega\UI\Dashboard\Sections\DiagnosticsSection;
use VGT\Omega\UI\Dashboard\Sections\OverviewSection;
use VGT\Omega\UI\Dashboard\Sections\VaultSection;

/**
 * Registers the "GeDefense WPR" admin page and renders the Glass 2100 console.
 *
 * The class is transport-thin: it owns the menu registration, the capability
 * gate and the page composition. All data collection lives in ConsoleData and
 * all markup in Components/Sections, so the same markup is what the visual QA
 * harness renders via `renderWith()`.
 */
final class Dashboard
{
    public const MENU_SLUG = 'vgt-console';

    public const CAPABILITY = 'activate_plugins';

    private static ?BridgeInterface $bridge = null;

    private static ?string $pageHook = null;

    /** Register menu + assets on the host. Called once from the bootstrap. */
    public static function init(BridgeInterface $bridge): void
    {
        self::$bridge = $bridge;
        $bridge->addAction('admin_menu', [self::class, 'registerMenu']);
        $bridge->addAction('admin_enqueue_scripts', [self::class, 'enqueueAssets'], 10, 1);
    }

    public static function registerMenu(): void
    {
        $bridge = self::$bridge;

        if (!$bridge instanceof BridgeInterface) {
            return;
        }

        $hook = $bridge->addMenuMain(
            'GeDefense WPR',
            'GeDefense WPR',
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render'],
            'dashicons-superhero',
            2
        );

        self::$pageHook = is_string($hook) ? $hook : '';
    }

    /** Load the stylesheet and script on the console page only. */
    public static function enqueueAssets(string $hookSuffix = ''): void
    {
        if (self::$pageHook === null || $hookSuffix !== self::$pageHook) {
            return;
        }

        if (self::$bridge instanceof BridgeInterface) {
            Assets::enqueue(self::$bridge);
        }
    }

    /** Admin page callback. */
    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html('GeDefense WPR: your account is not allowed to open the security console.'),
                esc_html('GeDefense WPR — access denied'),
                ['response' => 403]
            );
        }

        self::renderWith(ConsoleData::collect());
    }

    /** Render one console snapshot. Public so the visual harness can call it. */
    public static function renderWith(ConsoleData $data): void
    {
        $classes = ['vgt-console'];

        if ($data->isOffline()) {
            $classes[] = 'vgt-console--offline';
        }

        if ($data->safeMode) {
            $classes[] = 'vgt-console--safe';
        }

        if ($data->isDegraded()) {
            $classes[] = 'vgt-console--degraded';
        }

        ?>
<div class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-vgt-console-state="<?php echo esc_attr($data->state); ?>">
    <a class="vgt-skip" href="#vgt-main">Skip to console content</a>
    <?php OverviewSection::render($data); ?>
    <main class="vgt-console__body" id="vgt-main" tabindex="-1">
        <?php Notice::render($data->notice); ?>
        <div class="vgt-workbench">
            <?php VaultSection::render($data); ?>
            <?php DeploySection::render($data); ?>
        </div>
        <?php DiagnosticsSection::render($data); ?>
        <footer class="vgt-footer">
            <p class="vgt-footer__line">
                Glass 2100 material system &middot; GeDefense WPR
                <?php echo esc_html($data->version); ?> <?php echo esc_html($data->codeName); ?>
                &middot; AGPL-3.0-or-later
            </p>
            <p class="vgt-footer__line vgt-footer__meta">
                PHP <?php echo esc_html($data->phpVersion); ?>
                (minimum <?php echo esc_html($data->minPhpVersion); ?>)
                &middot; snapshot <?php echo esc_html($data->generatedAt); ?>
                &middot; Vault <?php echo esc_html($data->booted ? 'booted' : 'not booted'); ?>
            </p>
        </footer>
    </main>
    <nav class="vgt-mobilebar" aria-label="Console quick actions">
        <a class="vgt-mobilebar__action" href="#vgt-vault">
            <?php Icon::render('vault', 'vgt-icon'); ?>
            <span>Vault</span>
        </a>
        <a class="vgt-mobilebar__action vgt-mobilebar__action--primary" href="#vgt-deploy" data-vgt-open-deploy>
            <?php Icon::render('rotate', 'vgt-icon'); ?>
            <span>Deploy</span>
        </a>
        <a class="vgt-mobilebar__action" href="#vgt-diagnostics">
            <?php Icon::render('activity', 'vgt-icon'); ?>
            <span>Diagnostics</span>
        </a>
    </nav>
    <?php ConfirmDialog::render(); ?>
</div>
        <?php
    }
}
