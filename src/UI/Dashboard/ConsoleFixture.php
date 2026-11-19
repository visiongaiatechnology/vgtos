<?php
/**
 * VGT Omega console — design-time fixture data.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard;

/**
 * Realistic console snapshots for the visual QA harness.
 *
 * Kept apart from ConsoleData so the value object stays a thin, readable data
 * carrier. The shape here mirrors SystemDiagnostics exactly; a drift between
 * fixture and collector shows up as an "unknown"/"unavailable" verdict in the
 * rendered preview, which the screenshot review would catch.
 */
final class ConsoleFixture
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $mounted = 'vgt_7f3a1c9e2b5d4f60a8c1e3b7';
        $telemetry = 'vgt_c41d8b72f0a934e5b6d2c8f1';
        $locked = 'vgt_9b6e2f05d7c3481a4e9b0c6d';
        $limit = 314572800;
        $used = 120795955;

        return [
            'state' => ConsoleData::STATE_READY,
            'version' => '4.2.0',
            'codeName' => 'OMEGA',
            'phpVersion' => '8.3.14',
            'minPhpVersion' => '8.1.0',
            'safeMode' => false,
            'layerZero' => true,
            'booted' => true,
            'bootReference' => '',
            'artifacts' => [
                [
                    'id' => $mounted,
                    'name' => 'Aegis Core Firewall',
                    'entry' => 'aegis-core.php',
                    'files' => 6,
                    'signed' => true,
                    'format' => 'vgt-artifact/4.2',
                    'updated' => 1795087200,
                    'unlocked' => true,
                ],
                [
                    'id' => $telemetry,
                    'name' => 'Helios Telemetry Relay',
                    'entry' => 'helios-relay.php',
                    'files' => 3,
                    'signed' => true,
                    'format' => 'vgt-artifact/4.2',
                    'updated' => 1795006800,
                    'unlocked' => true,
                ],
                [
                    'id' => $locked,
                    'name' => 'Atlas Recovery Suite',
                    'entry' => 'atlas-recovery.php',
                    'files' => 4,
                    'signed' => false,
                    'format' => 'vgt-artifact/4.2',
                    'updated' => 1794832800,
                    'unlocked' => false,
                ],
            ],
            'mountStats' => ['mounted' => [$mounted, $telemetry], 'locked' => [$locked], 'failed' => []],
            'diagnostics' => [
                'environment' => [
                    'platform' => 'wordpress / layer 0',
                    'php' => '8.3.14',
                    'openssl' => 'OpenSSL 3.2.1 30 Jan 2024',
                    'aes_gcm' => true,
                    'sodium' => true,
                    'zip_archive' => true,
                    'pclzip_fallback' => false,
                    'apcu' => true,
                    'multisite' => false,
                    'ssl' => true,
                ],
                'crypto' => [
                    'format' => 'vgt-artifact/4.2',
                    'cipher' => 'AES-256-GCM',
                    'kdf' => 'HKDF-SHA256 (dual-factor)',
                    'binding_mode' => 'soft',
                    'fingerprint' => '7ce41a90b2d6f813',
                    'pepper_present' => true,
                    'salts_present' => 4,
                    'aad_binding' => true,
                    'signature_policy' => 'required',
                    'smart_compression' => 'VGT_RAW <= 16 KiB / VGT_GZ > 16 KiB',
                ],
                'storage' => [
                    'vault_isolated' => true,
                    'vault_location' => 'wp-content/vgt-vault',
                    'artifacts' => 3,
                    'public_mirror' => true,
                    'writable' => true,
                ],
                'memory' => [
                    'limit' => $limit,
                    'limit_human' => '300 MiB',
                    'used' => $used,
                    'used_human' => '115.2 MiB',
                    'available' => $limit - $used,
                    'available_human' => '184.8 MiB',
                ],
                'runtime' => [
                    'layer_zero' => true,
                    'safe_mode' => false,
                    'boot_error' => null,
                    'mounted' => [$mounted, $telemetry],
                    'locked' => [$locked],
                    'failed' => [],
                    'mount_count' => 2,
                    'lock_count' => 1,
                    'fail_count' => 0,
                    'stream_wrapper' => true,
                ],
            ],
            'envFacts' => ['opcache' => true, 'diagnostics_error' => false],
            'notice' => [
                'type' => 'success',
                'message' => 'Artifact "Aegis Core Firewall" was deployed and is mounted. 6 files verified.',
                'reference' => 'VGT-REF-9C41B7E2',
            ],
            'generatedAt' => '2026-11-19 15:42 (site time)',
        ];
    }

    /**
     * Second snapshot: safe mode plus a hard boot failure.
     *
     * Exercises every degraded path at once — offline banner with reference,
     * safe-mode recovery instructions, empty registry, disabled deployment and
     * unavailable telemetry — in one reproducible page.
     *
     * @return array<string, mixed>
     */
    public static function offlineOverrides(): array
    {
        return [
            'state' => ConsoleData::STATE_OFFLINE,
            'booted' => false,
            'safeMode' => true,
            'layerZero' => true,
            'bootReference' => 'VGT-ERR-4F2A9C31',
            'artifacts' => [],
            'mountStats' => ['mounted' => [], 'locked' => [], 'failed' => []],
            'diagnostics' => [],
            'envFacts' => ['opcache' => false, 'diagnostics_error' => true],
            'notice' => [
                'type' => 'error',
                'message' => 'The vault refused to boot and nothing was mounted. No artifact code is running on this site.',
                'reference' => 'VGT-ERR-4F2A9C31',
            ],
            'generatedAt' => '2026-11-19 15:44 (site time)',
        ];
    }
}
