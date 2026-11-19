<?php
/**
 * VGT Omega console — artifact registry row normalization.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard;

/**
 * Turns raw vault metadata plus mount state into one render-safe row.
 *
 * Isolated from ConsoleData because the row carries its own display policy:
 * which tone a mount state gets, which plain-text verdict is shown, and how a
 * timestamp is formatted. Values are clamped and control characters removed
 * here, so Sections only ever echo already-normalized scalar fields.
 */
final class ArtifactRow
{
    /** @var string */
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i';

    /**
     * @param array<string, mixed> $raw artifact meta (name/updated/entry/files/signed/format)
     * @param array{mounted: list<string>, locked: list<string>, failed: array<string, string>} $mountStats
     * @return array<string, mixed>
     */
    public static function build(string $id, array $raw, bool $unlocked, array $mountStats, bool $safeMode): array
    {
        $name = self::cleanLabel($raw['name'] ?? '', 120);
        if ($name === '') {
            $name = 'Artifact ' . substr($id, 4, 8);
        }

        $failedIds = [];
        foreach (array_keys($mountStats['failed']) as $failedId) {
            $failedIds[] = (string) $failedId;
        }

        $tone = 'neutral';
        $icon = 'lock';
        $stateLabel = 'Locked';
        $detail = 'The decryption key for this node is unavailable, so nothing was mounted.';

        if ($safeMode) {
            $tone = 'warn';
            $stateLabel = 'Locked by safe mode';
            $detail = 'Mounting is suspended by VGT_OMEGA_SAFE_MODE. Deployed code is not executed.';
        } elseif (in_array($id, $failedIds, true)) {
            $tone = 'crit';
            $icon = 'alert';
            $stateLabel = 'Mount failed';
            $detail = 'Manifest, signature or code check rejected this artifact. Its code was not executed.';
        } elseif ($unlocked) {
            $tone = 'ok';
            $icon = 'check';
            $stateLabel = 'Mounted';
            $detail = 'Verified and decrypted into RAM for this request only.';
        }

        $rawUpdated = $raw['updated'] ?? 0;
        $rawFiles = $raw['files'] ?? 0;
        $updated = is_int($rawUpdated) ? $rawUpdated : (int) (is_numeric($rawUpdated) ? $rawUpdated : 0);
        $files = is_int($rawFiles) ? $rawFiles : (int) (is_numeric($rawFiles) ? $rawFiles : 0);
        $format = self::cleanLabel($raw['format'] ?? '', 32);
        $entry = self::cleanLabel($raw['entry'] ?? '', 120);

        return [
            'id' => $id,
            'short_id' => substr($id, 4, 8),
            'name' => $name,
            'entry' => $entry !== '' ? $entry : 'not recorded',
            'files' => max(0, min($files, 100000)),
            'signed' => ($raw['signed'] ?? false) === true,
            'format' => $format !== '' ? $format : 'unknown',
            'updated' => max(0, $updated),
            'updated_label' => self::formatTimestamp(max(0, $updated)),
            'state' => $safeMode ? 'locked' : (in_array($id, $failedIds, true) ? 'failed' : ($unlocked ? 'mounted' : 'locked')),
            'state_label' => $stateLabel,
            'state_detail' => $detail,
            'tone' => $tone,
            'icon' => $icon,
        ];
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @return array{mounted: list<string>, locked: list<string>, failed: array<string, string>}
     */
    public static function normalizeMountStats(array $diagnostics): array
    {
        $runtime = $diagnostics['runtime'] ?? null;
        $runtime = is_array($runtime) ? $runtime : [];

        $mounted = is_array($runtime['mounted'] ?? null) ? array_values(array_map('strval', $runtime['mounted'])) : [];
        $locked = is_array($runtime['locked'] ?? null) ? array_values(array_map('strval', $runtime['locked'])) : [];
        $failedRaw = is_array($runtime['failed'] ?? null) ? $runtime['failed'] : [];
        $failed = [];

        foreach ($failedRaw as $failedId => $reason) {
            $failed[(string) $failedId] = is_string($reason) ? $reason : '';
        }

        return ['mounted' => $mounted, 'locked' => $locked, 'failed' => $failed];
    }

    private static function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'unknown';
        }

        if (function_exists('date_i18n')) {
            return (string) date_i18n(self::TIMESTAMP_FORMAT, $timestamp);
        }

        return gmdate(self::TIMESTAMP_FORMAT, $timestamp) . ' UTC';
    }

    /** Strip control characters and clamp length for a plain-text label. */
    private static function cleanLabel(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $clean = trim($clean);

        return function_exists('mb_substr') ? mb_substr($clean, 0, $maxLength) : substr($clean, 0, $maxLength);
    }
}
