<?php
/**
 * VGT Omega authenticated encryption engine.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Crypto;

use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\Support\MemoryGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * AES-256-GCM with mandatory AAD context binding and a strict header parser.
 *
 * Hardening (FIND-003 / FIND-006):
 * - AAD is mandatory for every operation; artifact id + relative path are bound
 *   into the GCM tag, so ciphertexts cannot be swapped inside an artifact.
 * - The magic header must be the literal prefix of the container. The legacy
 *   "search anywhere for VGT_GUARD" behaviour is removed.
 * - VGT_RAW / VGT_GZ flags per file: small files skip gzip entirely.
 * - gzdecode() always receives a hard max_length budget (zip-bomb / OOM guard).
 */
final class CryptoEngine
{
    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private readonly int $maxPlainBytes;

    private readonly int $rawThreshold;

    public function __construct(
        int $maxPlainBytes = VaultFormat::MAX_PLAINTEXT_FILE_BYTES,
        int $rawThreshold = VaultFormat::RAW_COMPRESSION_THRESHOLD,
        private readonly ?MemoryGuard $memoryGuard = null
    ) {
        if ($maxPlainBytes < 1 || $maxPlainBytes > VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES) {
            throw new \InvalidArgumentException('VGT Crypto: plaintext budget out of range.');
        }

        $this->maxPlainBytes = $maxPlainBytes;
        $this->rawThreshold = max(0, $rawThreshold);
    }

    /**
     * Encrypt plaintext into the v4.2 container.
     *
     * @param string $key 32 raw bytes
     * @param string $aad non-empty context string
     */
    public function encrypt(
        string $plaintext,
        #[\SensitiveParameter] string $key,
        string $aad,
        bool $preferCompression = true
    ): string {
        $this->assertKey($key);
        $this->assertAad($aad);

        if (strlen($plaintext) > $this->maxPlainBytes) {
            throw new StorageException('Payload exceeds the configured plaintext budget.');
        }

        if ($this->memoryGuard instanceof MemoryGuard) {
            $this->memoryGuard->assertCanAllocate((int) (strlen($plaintext) * 1.6) + 196608, 'encryption');
        }

        $flags = VaultFormat::FLAG_NONE;
        $payload = $plaintext;

        if ($preferCompression && strlen($plaintext) >= $this->rawThreshold) {
            $compressed = gzencode($plaintext, 9, FORCE_GZIP);

            if ($compressed !== false && strlen($compressed) < strlen($plaintext)) {
                $payload = $compressed;
                $flags |= VaultFormat::FLAG_GZIP;
            }
        }

        $iv = random_bytes(VaultFormat::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            VaultFormat::TAG_LENGTH
        );

        if ($ciphertext === false || strlen($tag) !== VaultFormat::TAG_LENGTH) {
            throw new StorageException('OpenSSL failed to encrypt the payload.');
        }

        return VaultFormat::MAGIC
            . chr(VaultFormat::VERSION_BYTE)
            . chr($flags)
            . $iv
            . $tag
            . $ciphertext;
    }

    /**
     * Decrypt and authenticate a v4.2 container.
     *
     * Legacy v4.0/4.1 containers (no AAD, always gzip) are accepted only when
     * $allowLegacy is true, i.e. during the cloud-key import of a package.
     *
     * @throws SecurityException on tampering, header mismatch, AAD mismatch or budget overflow
     */
    public function decrypt(
        string $container,
        #[\SensitiveParameter] string $key,
        string $aad,
        bool $allowLegacy = false
    ): string {
        $this->assertKey($key);
        $this->assertAad($aad);

        if (!str_starts_with($container, VaultFormat::MAGIC)) {
            throw new SecurityException('Artifact header missing or not at byte offset zero.');
        }

        if (strlen($container) < VaultFormat::LEGACY_HEADER_LENGTH + 1) {
            throw new SecurityException('Artifact container is truncated.');
        }

        $version = ord($container[VaultFormat::MAGIC_LENGTH]);

        if ($version === VaultFormat::VERSION_BYTE && strlen($container) >= VaultFormat::HEADER_LENGTH + 1) {
            try {
                return $this->decryptModern($container, $key, $aad);
            } catch (SecurityException $modernFailure) {
                if (!$allowLegacy) {
                    throw $modernFailure;
                }
                // A legacy IV may coincidentally start with 0x42; retry legacy.
                return $this->decryptLegacy($container, $key);
            }
        }

        if ($allowLegacy) {
            return $this->decryptLegacy($container, $key);
        }

        throw new SecurityException('Unsupported artifact container version.');
    }

    /** Cheap structural probe used by diagnostics; never authenticates. */
    public static function hasModernHeader(string $container): bool
    {
        return strlen($container) >= VaultFormat::HEADER_LENGTH
            && str_starts_with($container, VaultFormat::MAGIC)
            && ord($container[VaultFormat::MAGIC_LENGTH]) === VaultFormat::VERSION_BYTE;
    }

    private function decryptModern(string $container, string $key, string $aad): string
    {
        $flags = ord($container[VaultFormat::MAGIC_LENGTH + 1]);
        $known = VaultFormat::FLAG_GZIP;

        if (($flags & ~$known) !== 0) {
            throw new SecurityException('Unknown payload flags in artifact container.');
        }

        $iv = substr($container, VaultFormat::MAGIC_LENGTH + 2, VaultFormat::IV_LENGTH);
        $tag = substr($container, VaultFormat::MAGIC_LENGTH + 2 + VaultFormat::IV_LENGTH, VaultFormat::TAG_LENGTH);
        $ciphertext = substr($container, VaultFormat::HEADER_LENGTH);

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($decrypted === false) {
            throw new SecurityException('AES-GCM authentication failed (tampered payload or AAD mismatch).');
        }

        return $this->decodePayload($decrypted, ($flags & VaultFormat::FLAG_GZIP) !== 0);
    }

    private function decryptLegacy(string $container, string $key): string
    {
        if (strlen($container) < VaultFormat::LEGACY_HEADER_LENGTH) {
            throw new SecurityException('Legacy artifact container is truncated.');
        }

        $iv = substr($container, VaultFormat::MAGIC_LENGTH, VaultFormat::IV_LENGTH);
        $tag = substr($container, VaultFormat::MAGIC_LENGTH + VaultFormat::IV_LENGTH, VaultFormat::TAG_LENGTH);
        $ciphertext = substr($container, VaultFormat::LEGACY_HEADER_LENGTH);

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($decrypted === false) {
            throw new SecurityException('Legacy AES-GCM authentication failed.');
        }

        return $this->decodePayload($decrypted, true);
    }

    private function decodePayload(string $decrypted, bool $gzip): string
    {
        if ($gzip) {
            $this->assertInflateBudget(strlen($decrypted));

            $plain = gzdecode($decrypted, $this->maxPlainBytes);

            if ($plain === false) {
                throw new SecurityException('DEFLATE payload could not be inflated within the budget.');
            }

            return $plain;
        }

        if (strlen($decrypted) > $this->maxPlainBytes) {
            throw new SecurityException('Plaintext exceeds the configured budget.');
        }

        return $decrypted;
    }

    private function assertInflateBudget(int $compressedBytes): void
    {
        if ($this->memoryGuard instanceof MemoryGuard) {
            // DEFLATE can expand up to ~1032:1; cap the pre-flight at the hard budget.
            $this->memoryGuard->assertCanAllocate(
                min($this->maxPlainBytes, $compressedBytes * 32 + 262144),
                'decompression'
            );
        }
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new SecurityException('Invalid AES-256 key length.');
        }
    }

    private function assertAad(string $aad): void
    {
        if ($aad === '' || strlen($aad) > 512) {
            throw new SecurityException('AAD binding context is missing or malformed.');
        }
    }
}
