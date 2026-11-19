<?php
/**
 * VGT Omega console — overview / state section.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Sections;

use VGT\Omega\UI\Dashboard\Components\Icon;
use VGT\Omega\UI\Dashboard\Components\StatusPill;
use VGT\Omega\UI\Dashboard\ConsoleData;

/**
 * Masthead, operating-state bar, recovery banners and the vault integrity rail.
 *
 * The integrity rail is the console's signature element: it answers exactly one
 * operator question — "how much of the vault is live right now?" — in one
 * glance and with a textual legend, so it never depends on colour alone.
 */
final class OverviewSection
{
    public static function render(ConsoleData $data): void
    {
        $summary = $data->mountSummary();
        $isolation = self::isolationLabel($data);

        ?>
<header class="vgt-masthead">
    <div class="vgt-masthead__identity">
        <span class="vgt-seal" aria-hidden="true"><?php Icon::render('vault', 'vgt-icon vgt-seal__glyph'); ?></span>
        <div class="vgt-masthead__text">
            <p class="vgt-eyebrow">VisionGaia Technology &middot; GeDefenseWP security console</p>
            <h1 class="vgt-title">
                VGT Omega
                <span class="vgt-title__version"><?php echo esc_html($data->version); ?></span>
                <span class="vgt-title__codename"><?php echo esc_html($data->codeName); ?></span>
            </h1>
            <p class="vgt-lede">
                Sovereign encrypted artifact vault for WordPress. Artifacts are decrypted into RAM and
                mounted only after an authenticated manifest, signature and classmap check pass.
            </p>
        </div>
    </div>
    <dl class="vgt-statebar">
        <div class="vgt-statebar__item">
            <dt class="vgt-statebar__label">Kernel</dt>
            <dd class="vgt-statebar__value"><?php echo esc_html($data->kernelLabel()); ?></dd>
        </div>
        <div class="vgt-statebar__item">
            <dt class="vgt-statebar__label">Vault isolation</dt>
            <dd class="vgt-statebar__value"><?php echo esc_html($isolation); ?></dd>
        </div>
        <div class="vgt-statebar__item">
            <dt class="vgt-statebar__label">System state</dt>
            <dd class="vgt-statebar__value">
                <?php self::renderStatePill($data); ?>
            </dd>
        </div>
    </dl>
</header>

<?php if ($data->isOffline()) : ?>
<section class="vgt-banner vgt-banner--crit" aria-labelledby="vgt-banner-offline">
    <span class="vgt-banner__icon" aria-hidden="true"><?php Icon::render('alert', 'vgt-icon'); ?></span>
    <div class="vgt-banner__body">
        <h2 id="vgt-banner-offline" class="vgt-banner__title">Vault subsystem offline &mdash; no artifact code is running</h2>
        <p class="vgt-banner__text">
            The vault did not finish booting, so mounting, deployment and runtime telemetry are
            unavailable. The console stays reachable so the failure can be read and recovered.
        </p>
        <?php if ($data->bootReference !== '') : ?>
        <p class="vgt-banner__ref">Failure reference <code><?php echo esc_html($data->bootReference); ?></code> &mdash; safe to quote in support.</p>
        <?php endif; ?>
        <ol class="vgt-banner__steps">
            <li>Confirm all four WordPress salts (<code>AUTH_KEY</code>, <code>SECURE_AUTH_KEY</code>, <code>LOGGED_IN_KEY</code>, <code>NONCE_KEY</code>) are defined in <code>wp-config.php</code>.</li>
            <li>Confirm the vault directory and its <code>artifacts/</code> folder are writable by the web server user.</li>
            <li>Reload this page. If the reference repeats, check the PHP error log for the matching <code>VGT-ERR-</code> entry.</li>
        </ol>
    </div>
</section>
<?php endif; ?>

<?php if ($data->safeMode) : ?>
<section class="vgt-banner vgt-banner--warn" aria-labelledby="vgt-banner-safe">
    <span class="vgt-banner__icon" aria-hidden="true"><?php Icon::render('lock', 'vgt-icon'); ?></span>
    <div class="vgt-banner__body">
        <h2 id="vgt-banner-safe" class="vgt-banner__title">Safe mode active &mdash; artifact mounting is suspended</h2>
        <p class="vgt-banner__text">
            Every artifact stays encrypted and locked; no artifact code executes. This is the intended
            state for incident response and recovery. Deployment remains available but new artifacts
            also stay locked until safe mode is cleared.
        </p>
        <p class="vgt-banner__text">
            To resume mounting, set <code class="vgt-code"><?php echo esc_html(ConsoleData::SAFE_MODE_RECOVERY_SNIPPET); ?></code>
            in <code>wp-config.php</code> above the <code>/* That's all */</code> line (or remove the
            <code>VGT_OMEGA_SAFE_MODE</code> define), then reload this page.
        </p>
    </div>
</section>
<?php endif; ?>

<?php if (!$data->isOffline() && $data->isDegraded()) : ?>
<section class="vgt-banner vgt-banner--warn" aria-labelledby="vgt-banner-degraded">
    <span class="vgt-banner__icon" aria-hidden="true"><?php Icon::render('activity', 'vgt-icon'); ?></span>
    <div class="vgt-banner__body">
        <h2 id="vgt-banner-degraded" class="vgt-banner__title">Partial telemetry &mdash; some runtime data could not be collected</h2>
        <p class="vgt-banner__text">
            The vault booted, but one of the collectors failed. Registry and mount state below are
            best-effort; treat the diagnostics panel as incomplete rather than healthy.
        </p>
    </div>
</section>
<?php endif; ?>

<section class="vgt-integrity" aria-labelledby="vgt-integrity-title">
    <div class="vgt-integrity__head">
        <h2 id="vgt-integrity-title" class="vgt-section-title">Vault integrity</h2>
        <p class="vgt-integrity__reading">
            <?php if ($summary['total'] === 0) : ?>
                <span class="vgt-integrity__none">No artifacts installed</span>
            <?php else : ?>
                <b><?php echo esc_html((string) $summary['mounted']); ?></b> of
                <b><?php echo esc_html((string) $summary['total']); ?></b> artifacts mounted
            <?php endif; ?>
        </p>
    </div>
    <div class="vgt-rail<?php if ($summary['total'] === 0) : ?> vgt-rail--empty<?php endif; ?>" aria-hidden="true">
        <?php if ($summary['total'] === 0) : ?>
            <?php for ($i = 0; $i < 4; $i++) : ?>
            <span class="vgt-rail__seg vgt-rail__seg--empty"></span>
            <?php endfor; ?>
        <?php else : ?>
            <?php foreach ($data->artifacts as $row) : ?>
            <span class="vgt-rail__seg vgt-rail__seg--<?php echo esc_attr($row['state']); ?>"></span>
            <?php endforeach; ?>
            <?php if ($summary['failed'] > 0) : ?>
            <span class="vgt-rail__seg vgt-rail__seg--failed"></span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <ul class="vgt-legend" role="list">
        <li class="vgt-legend__item"><span class="vgt-legend__dot vgt-legend__dot--mounted" aria-hidden="true"></span>Mounted <b><?php echo esc_html((string) $summary['mounted']); ?></b></li>
        <li class="vgt-legend__item"><span class="vgt-legend__dot vgt-legend__dot--locked" aria-hidden="true"></span>Locked <b><?php echo esc_html((string) $summary['locked']); ?></b></li>
        <li class="vgt-legend__item"><span class="vgt-legend__dot vgt-legend__dot--failed" aria-hidden="true"></span>Failed <b><?php echo esc_html((string) $summary['failed']); ?></b></li>
    </ul>
</section>
        <?php
    }

    private static function renderStatePill(ConsoleData $data): void
    {
        if ($data->isOffline()) {
            StatusPill::render('crit', 'Offline', 'alert');
        } elseif ($data->safeMode) {
            StatusPill::render('warn', 'Safe mode', 'lock');
        } elseif ($data->isDegraded()) {
            StatusPill::render('warn', 'Degraded', 'activity');
        } else {
            StatusPill::render('ok', 'Operational', 'check');
        }
    }

    private static function isolationLabel(ConsoleData $data): string
    {
        if (!$data->hasDiagnostics()) {
            return 'Unknown';
        }

        return $data->diagFlag('storage', 'vault_isolated') ? 'Outside webroot' : 'Unverified';
    }
}
