<?php
/**
 * GeDefense WPR console — system diagnostics strip.
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
 * Read-only runtime telemetry in three dense definition-list columns.
 *
 * Every row is a label/value pair with the verdict as text, so the strip stays
 * fully readable without colour, blur or icons. Values that are paths or hashes
 * use monospace; the RAM headroom uses a native <meter> with a text reading.
 */
final class DiagnosticsSection
{
    public static function render(ConsoleData $data): void
    {
        $available = $data->hasDiagnostics();

        ?>
<section class="vgt-panel vgt-panel--opaque vgt-diag" id="vgt-diagnostics" aria-labelledby="vgt-diag-title">
    <header class="vgt-panel__head">
        <div class="vgt-panel__heading">
            <p class="vgt-eyebrow">Telemetry</p>
            <h2 id="vgt-diag-title" class="vgt-panel__title">System diagnostics</h2>
            <p class="vgt-panel__sub">
                Captured <?php echo esc_html($data->generatedAt); ?>. Read-only &mdash; no key material,
                salt value or pepper is displayed.
            </p>
        </div>
        <?php if ($available) : ?>
            <?php StatusPill::render('ok', 'Collected', 'check'); ?>
        <?php else : ?>
            <?php StatusPill::render('crit', 'Unavailable', 'alert'); ?>
        <?php endif; ?>
    </header>

    <?php if (!$available) : ?>
    <div class="vgt-diag__unavailable">
        <span class="vgt-diag__unavailable-icon" aria-hidden="true"><?php Icon::render('activity', 'vgt-icon'); ?></span>
        <div>
            <h3 class="vgt-diag__unavailable-title">Runtime telemetry cannot be collected</h3>
            <p class="vgt-diag__unavailable-text">
                The vault subsystem is offline, so environment, cryptography and storage telemetry are
                unavailable. Nothing is mounted and no artifact code is running while this state lasts.
            </p>
            <p class="vgt-diag__unavailable-text">
                Recover the vault using the checklist above, then reload this page. Until then, treat every
                runtime assumption as unverified.
            </p>
        </div>
    </div>
    <?php else : ?>
    <div class="vgt-diag__grid">
        <?php self::renderEnvironment($data); ?>
        <?php self::renderCrypto($data); ?>
        <?php self::renderStorage($data); ?>
    </div>
    <?php endif; ?>
</section>
        <?php
    }

    private static function renderEnvironment(ConsoleData $data): void
    {
        $platform = $data->diagText('environment', 'platform', 'unknown');
        $platformLabel = str_contains($platform, 'layer') ? 'WordPress / layer-0 kernel'
            : (str_contains($platform, 'plugin') ? 'WordPress / plugin' : $platform);

        ?>
<div class="vgt-diag__group">
    <h3 class="vgt-diag__group-title">Environment</h3>
    <dl class="vgt-diag__list">
        <?php self::row('Platform', $platformLabel); ?>
        <?php self::row('PHP runtime', $data->diagText('environment', 'php', $data->phpVersion), '', 'minimum ' . $data->minPhpVersion); ?>
        <?php self::row('OpenSSL', $data->diagText('environment', 'openssl', 'unknown')); ?>
        <?php self::statusRow('AES-256-GCM', $data->diagFlag('environment', 'aes_gcm'), 'Available', 'Unavailable'); ?>
        <?php self::statusRow('Sodium signatures', $data->diagFlag('environment', 'sodium'), 'Available', 'Unavailable'); ?>
        <?php self::row('Archive support', self::archiveLabel($data)); ?>
        <?php self::statusRow('APCu ciphertext cache', $data->diagFlag('environment', 'apcu'), 'Enabled', 'Disabled', 'ok', 'muted'); ?>
        <?php self::statusRow('OPcache', (bool) ($data->envFacts['opcache'] ?? false), 'Enabled', 'Disabled', 'ok', 'warn', 'accelerates console requests'); ?>
        <?php self::statusRow('HTTPS transport', $data->diagFlag('environment', 'ssl'), 'Yes', 'Not detected', 'ok', 'warn'); ?>
        <?php self::row('Multisite', $data->diagFlag('environment', 'multisite') ? 'Yes' : 'No'); ?>
    </dl>
</div>
        <?php
    }

    private static function renderCrypto(ConsoleData $data): void
    {
        $binding = $data->diagText('crypto', 'binding_mode', 'unknown');
        $bindingLabel = $binding === 'strict' ? 'Strict (hardware-bound)' : ($binding === 'soft' ? 'Soft (host-migration safe)' : $binding);
        $policy = $data->diagText('crypto', 'signature_policy', 'unknown');
        $policyLabel = $policy === 'required' ? 'Required' : ($policy === 'optional' ? 'Optional' : $policy);

        ?>
<div class="vgt-diag__group">
    <h3 class="vgt-diag__group-title">Cryptography &amp; keys</h3>
    <dl class="vgt-diag__list">
        <?php self::row('Artifact format', $data->diagText('crypto', 'format', 'unknown')); ?>
        <?php self::row('Cipher', $data->diagText('crypto', 'cipher', 'unknown')); ?>
        <?php self::row('Key derivation', $data->diagText('crypto', 'kdf', 'unknown')); ?>
        <?php self::row('Binding mode', $bindingLabel); ?>
        <?php self::statusRow('Signature policy', $policy === 'required', $policyLabel, $policyLabel, $policy === 'required' ? 'ok' : 'warn', $policy === 'required' ? 'ok' : 'warn'); ?>
        <?php self::statusRow('Key pepper', $data->diagFlag('crypto', 'pepper_present'), 'Present', 'Missing'); ?>
        <?php self::row('WordPress salts', $data->diagInt('crypto', 'salts_present') . ' of 4 present'); ?>
        <?php self::statusRow('AEAD binding (AAD)', $data->diagFlag('crypto', 'aad_binding'), 'Enabled', 'Disabled'); ?>
        <?php self::row('Key fingerprint', $data->diagText('crypto', 'fingerprint', 'unavailable'), 'muted'); ?>
    </dl>
    <p class="vgt-diag__note">
        Smart compression: <?php echo esc_html($data->diagText('crypto', 'smart_compression', 'not reported')); ?>
    </p>
</div>
        <?php
    }

    private static function renderStorage(ConsoleData $data): void
    {
        $summary = $data->mountSummary();
        $failed = $data->diagInt('runtime', 'fail_count');
        $ram = $data->ramHeadroomPercent();

        ?>
<div class="vgt-diag__group">
    <h3 class="vgt-diag__group-title">Storage &amp; runtime</h3>
    <dl class="vgt-diag__list">
        <?php self::statusRow('Vault isolation', $data->diagFlag('storage', 'vault_isolated'), 'Outside webroot', 'Unverified', 'ok', 'warn'); ?>
        <?php self::row('Vault location', $data->diagText('storage', 'vault_location', 'unknown'), 'muted'); ?>
        <?php self::statusRow('Vault storage', $data->diagFlag('storage', 'writable'), 'Writable', 'Read-only'); ?>
        <?php self::row('Registered artifacts', (string) $data->artifactCount()); ?>
        <?php self::row('Mount state', $summary['mounted'] . ' mounted · ' . $summary['locked'] . ' locked · ' . $summary['failed'] . ' failed', $failed > 0 ? 'crit' : 'ok'); ?>
        <?php self::statusRow('Safe mode', !$data->safeMode, 'Off', 'Active', 'ok', 'warn'); ?>
        <?php self::statusRow('Layer-0 kernel', $data->diagFlag('runtime', 'layer_zero'), 'Active', 'Not active', 'ok', 'muted', 'mu-plugin bootstraps the vault before plugins'); ?>
        <?php self::statusRow('vgt:// stream wrapper', $data->diagFlag('runtime', 'stream_wrapper'), 'Registered', 'Not registered'); ?>
        <?php self::meterRow('RAM headroom', $ram, $data->diagText('memory', 'available_human', '—') . ' of ' . $data->diagText('memory', 'limit_human', '—') . ' free'); ?>
    </dl>
    <?php if ($failed > 0) : ?>
    <p class="vgt-diag__fail">
        <?php Icon::render('alert', 'vgt-icon'); ?>
        <?php echo esc_html((string) $failed); ?> artifact(s) failed verification and were not executed.
        Check the PHP error log for the mount reference.
    </p>
    <?php endif; ?>
</div>
        <?php
    }

    private static function archiveLabel(ConsoleData $data): string
    {
        if ($data->diagFlag('environment', 'zip_archive')) {
            return 'ZipArchive';
        }

        if ($data->diagFlag('environment', 'pclzip_fallback')) {
            return 'PclZip fallback';
        }

        return 'Unavailable';
    }

    /** One definition-list row with a plain, escaped value. */
    private static function row(string $label, string $value, string $tone = '', string $suffix = ''): void
    {
        echo '<div class="vgt-diag__row">';
        echo '<dt class="vgt-diag__label">' . esc_html($label) . '</dt>';
        echo '<dd class="vgt-diag__cell">';
        echo '<span class="vgt-diag__value' . self::toneClass($tone) . '">' . esc_html($value) . '</span>';
        if ($suffix !== '') {
            echo '<span class="vgt-diag__suffix">' . esc_html($suffix) . '</span>';
        }
        echo '</dd></div>';
    }

    /** Boolean verdict row: text verdict plus tone, never colour alone. */
    private static function statusRow(
        string $label,
        bool $ok,
        string $okLabel,
        string $failLabel,
        string $okTone = 'ok',
        string $failTone = 'crit',
        string $suffix = ''
    ): void {
        echo '<div class="vgt-diag__row">';
        echo '<dt class="vgt-diag__label">' . esc_html($label) . '</dt>';
        echo '<dd class="vgt-diag__cell">';
        echo '<span class="vgt-diag__value' . self::toneClass($ok ? $okTone : $failTone) . '">'
            . esc_html($ok ? $okLabel : $failLabel) . '</span>';
        if ($suffix !== '') {
            echo '<span class="vgt-diag__suffix">' . esc_html($suffix) . '</span>';
        }
        echo '</dd></div>';
    }

    /** Accessible capacity row using a native <meter> plus a text reading. */
    private static function meterRow(string $label, int $percent, string $reading): void
    {
        echo '<div class="vgt-diag__row">';
        echo '<dt class="vgt-diag__label">' . esc_html($label) . '</dt>';
        echo '<dd class="vgt-diag__cell">';
        echo '<span class="vgt-diag__value">' . esc_html((string) $percent) . ' %</span>';
        echo '<meter class="vgt-meter" min="0" max="100" low="25" high="60" optimum="100"'
            . ' value="' . esc_attr((string) $percent) . '" aria-label="' . esc_attr($label) . '">'
            . esc_html((string) $percent) . ' %</meter>';
        echo '<span class="vgt-diag__suffix">' . esc_html($reading) . '</span>';
        echo '</dd></div>';
    }

    private static function toneClass(string $tone): string
    {
        return in_array($tone, ['ok', 'warn', 'crit', 'muted'], true)
            ? ' vgt-diag__value--' . $tone
            : '';
    }
}
