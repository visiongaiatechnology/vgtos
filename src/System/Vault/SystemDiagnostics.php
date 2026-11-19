<?php
/**
 * VGT Omega system diagnostics.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Crypto\KeyDerivation;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\Storage\VaultPaths;
use VGT\Omega\System\Vault\Support\MemoryGuard;

/**
 * Read-only, non-root telemetry for the VGT console.
 *
 * The payload contains no secrets (no key material, no pepper value, no salts)
 * and is rendered by the UI with escaping. Values are collected fresh on every
 * dashboard request.
 */
final class SystemDiagnostics
{
    public function __construct(
        private readonly VaultPaths $paths,
        private readonly KeyDerivation $keys,
        private readonly ArtifactStoreInterface $store,
        private readonly SignatureVerifier $signatures
    ) {
    }

    /**
     * @param array{mounted: list<string>, locked: list<string>, failed: array<string, string>} $mountStats
     * @return array<string, array<string, mixed>>
     */
    public function collect(bool $safeMode, bool $layerZero, ?string $bootError, array $mountStats): array
    {
        $memory = new MemoryGuard();
        $keyInfo = $this->keys->describe();
        $artifacts = $this->store->listArtifactIds();

        $sodium = extension_loaded('sodium') && function_exists('sodium_crypto_sign_verify_detached');
        $zip = class_exists(\ZipArchive::class);
        $pclzipFile = defined('ABSPATH') ? (string) constant('ABSPATH') . 'wp-admin/includes/class-pclzip.php' : '';
        $pclzip = $pclzipFile !== '' && is_file($pclzipFile);

        return [
            'environment' => [
                'platform' => defined('VGT_OMEGA_MU_KERNEL') ? 'wordpress / layer 0' : 'wordpress / plugin',
                'php' => PHP_VERSION,
                'openssl' => defined('OPENSSL_VERSION_TEXT') ? (string) constant('OPENSSL_VERSION_TEXT') : 'unknown',
                'aes_gcm' => in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
                'sodium' => $sodium,
                'zip_archive' => $zip,
                'pclzip_fallback' => $pclzip,
                'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
                'multisite' => function_exists('is_multisite') && is_multisite(),
                'ssl' => function_exists('is_ssl') ? is_ssl() : false,
            ],
            'crypto' => [
                'format' => VaultFormat::SPEC_VERSION,
                'cipher' => 'AES-256-GCM',
                'kdf' => 'HKDF-SHA256 (dual-factor)',
                'binding_mode' => $keyInfo['mode'],
                'fingerprint' => $keyInfo['fingerprint'],
                'pepper_present' => $keyInfo['pepper_present'],
                'salts_present' => $keyInfo['salts_present'],
                'aad_binding' => true,
                'signature_policy' => $this->signatures->isRequired() ? 'required' : 'optional',
                'smart_compression' => 'VGT_RAW <= 16 KiB / VGT_GZ > 16 KiB',
            ],
            'storage' => [
                'vault_isolated' => $this->paths->isolated,
                'vault_location' => $this->paths->describeVaultDir(),
                'artifacts' => count($artifacts),
                'public_mirror' => $this->paths->publicDir !== '',
                'writable' => $this->probeWritable($this->paths->artifactsDir()),
            ],
            'memory' => [
                'limit' => $memory->limitBytes(),
                'limit_human' => MemoryGuard::formatBytes($memory->limitBytes()),
                'used' => $memory->usageBytes(),
                'used_human' => MemoryGuard::formatBytes($memory->usageBytes()),
                'available' => $memory->availableBytes(),
                'available_human' => MemoryGuard::formatBytes($memory->availableBytes()),
            ],
            'runtime' => [
                'layer_zero' => $layerZero,
                'safe_mode' => $safeMode,
                'boot_error' => $bootError,
                'mounted' => $mountStats['mounted'] ?? [],
                'locked' => $mountStats['locked'] ?? [],
                'failed' => $mountStats['failed'] ?? [],
                'mount_count' => count($mountStats['mounted'] ?? []),
                'lock_count' => count($mountStats['locked'] ?? []),
                'fail_count' => count($mountStats['failed'] ?? []),
                'stream_wrapper' => in_array('vgt', stream_get_wrappers(), true),
            ],
        ];
    }

    /**
     * is_writable() is unreliable on sandboxed/ACL-stripped Windows hosts;
     * prove writability with a real create/write/delete probe instead.
     */
    private function probeWritable(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $probe = rtrim(str_replace('\\', '/', $directory), '/') . '/.vgt_probe_' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'xb');

        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, 'vgt');
        fclose($handle);
        @unlink($probe);

        return $written === 3;
    }
}
