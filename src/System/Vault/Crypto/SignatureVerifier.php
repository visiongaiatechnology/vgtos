<?php
/**
 * VGT Omega Ed25519 manifest signature verification.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Crypto;

use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\SecurityException;

/**
 * Verifies the optional Ed25519 signature of an artifact manifest against the
 * canonical manifest payload.
 *
 * Policy: unsigned artifacts remain importable by default for backwards
 * compatibility; define VGT_OMEGA_REQUIRE_SIGNATURES = true in wp-config.php to
 * reject every unsigned or wrongly signed artifact (recommended for production
 * supply-chain hardening).
 */
final class SignatureVerifier
{
    public function __construct(private readonly bool $requireSignature = false)
    {
    }

    public static function fromPolicy(): self
    {
        $required = defined('VGT_OMEGA_REQUIRE_SIGNATURES')
            && filter_var(constant('VGT_OMEGA_REQUIRE_SIGNATURES'), FILTER_VALIDATE_BOOL);

        return new self($required);
    }

    public function isRequired(): bool
    {
        return $this->requireSignature;
    }

    /**
     * @throws SecurityException on missing (when required), malformed or invalid signature
     * @throws AvailabilityException when libsodium is unavailable but a signature is present
     */
    public function verify(Manifest $manifest, string $context = 'artifact'): void
    {
        $signature = $manifest->signature();

        if ($signature === null) {
            if ($this->requireSignature) {
                throw new SecurityException('Unsigned ' . $context . ' rejected: Ed25519 signature is required.');
            }

            return;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new AvailabilityException('libsodium is required to verify the signed artifact manifest.');
        }

        $publicKey = base64_decode($signature['public_key'], true);
        $signatureBytes = base64_decode($signature['sig'], true);

        if (!is_string($publicKey) || strlen($publicKey) !== 32
            || !is_string($signatureBytes) || strlen($signatureBytes) !== 64) {
            throw new SecurityException('Malformed Ed25519 signature material.');
        }

        $verified = sodium_crypto_sign_verify_detached(
            $signatureBytes,
            $manifest->canonicalPayload(),
            $publicKey
        );

        if (!$verified) {
            throw new SecurityException('Ed25519 signature verification failed for the artifact manifest.');
        }
    }
}
