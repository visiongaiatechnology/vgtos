<?php
/**
 * GeDefense WPR read-only virtual file system (vgt://).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Read-only stream wrapper for authenticated vault artifacts.
 *
 * URI grammar: vgt://<artifactId>/<relative path>
 *
 * Security invariants: the URI is parsed strictly (PathGuard: no traversal,
 * control bytes, drive or ADS syntax); only files declared in the authenticated
 * manifest exist; blob file names come from the manifest, are re-validated (32
 * lowercase hex) and realpath-jailed inside the artifact root. Encrypted
 * payloads are AES-256-GCM authenticated with artifact id + relative path as
 * AAD (modern v4.2 header only, legacy rejected); raw payloads are verified
 * against the authenticated manifest SHA-256 with hash_equals(). No write path
 * exists. Decrypted bytes live only in a bounded process-local cache (64
 * entries / 16 MiB FIFO); APCu may cache ciphertext only, opt-in via
 * VGT_OMEGA_APCU_BLOB_CACHE === true. url_stat() reports the real manifest
 * plaintext size and the stable manifest mtime (never time() and never 1024).
 */
final class StreamWrapper
{
    private const CACHE_MAX_ENTRIES = 64;
    private const CACHE_MAX_BYTES = 16777216;
    private const APCU_FLAG = 'VGT_OMEGA_APCU_BLOB_CACHE';

    /** @var resource|null set by PHP when a stream context is supplied */
    public $context;

    private static ?ManifestRegistry $registry = null;
    private static ?CryptoEngine $crypto = null;
    private static ?bool $apcuEnabled = null;
    /** @var array<string, string> bounded decrypted-bytes cache (process-local) */
    private static array $plaintextCache = [];
    /** @var list<string> FIFO eviction order of the plaintext cache */
    private static array $plaintextOrder = [];
    private static int $plaintextCacheBytes = 0;

    private string $buffer = '';
    private int $position = 0;
    /** @var array<string, int>|null */
    private ?array $openStat = null;
    /** @var list<string> */
    private array $directoryNames = [];
    private int $directoryIndex = 0;

    /**
     * Bind the per-request runtime services. Re-entrant and idempotent: rebinding
     * retargets the static pointers at the current vault context and clears every
     * cached cleartext byte so a new context never reads through a stale registry.
     */
    public static function configure(ManifestRegistry $registry, CryptoEngine $crypto): void
    {
        self::$registry = $registry;
        self::$crypto = $crypto;
        self::$apcuEnabled = null;
        self::clearCleartextCache();
    }

    /**
     * Drop every cached cleartext byte.
     *
     * The mounter calls this when the boot phase ends: decrypted entry code only
     * has to be readable while it is included, so the buffer does not linger
     * afterwards. It also guarantees a read after a mid-request blob change is
     * re-authenticated from disk instead of being served from the RAM cache.
     */
    public static function clearCleartextCache(): void
    {
        self::$plaintextCache = [];
        self::$plaintextOrder = [];
        self::$plaintextCacheBytes = 0;
    }

    /** True when this class configured the wrapper at least once in this process. */
    public static function isConfigured(): bool
    {
        return self::$registry instanceof ManifestRegistry && self::$crypto instanceof CryptoEngine;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->resetHandle();
        // Read-only protocol: accept exactly "r" and "rb".
        if ($mode !== 'r' && $mode !== 'rb') {
            return false;
        }
        $registry = self::$registry;
        if (!$registry instanceof ManifestRegistry) {
            return false;
        }
        $parsed = StreamPath::parse($path);
        if ($parsed === null || $parsed['rel'] === '') {
            return false;
        }
        try {
            // "Not mounted" (AvailabilityException) and any registry fault fail closed.
            $entry = $registry->get($parsed['id']);
        } catch (\Throwable) {
            return false;
        }
        $spec = $entry['manifest']->file($parsed['rel']);
        if ($spec === null) {
            return false;
        }
        try {
            $plaintext = self::readPlaintext($parsed['id'], $parsed['rel'], $spec, $entry);
        } catch (\Throwable $error) {
            ExceptionReporter::report($error, 'vgt-stream');
            return false;
        }
        if ($plaintext === null || strlen($plaintext) > VaultFormat::MAX_PLAINTEXT_FILE_BYTES) {
            return false;
        }
        $this->buffer = $plaintext;
        $this->position = 0;
        $this->openStat = StreamStat::file($entry['mtime'], strlen($plaintext), $parsed['id'] . '/' . $parsed['rel']);
        $opened_path = $path;
        return true;
    }
    public function stream_read(int $count): string
    {
        if ($count <= 0) {
            return '';
        }
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }
    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }
    /** @return array<string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->openStat ?? false;
    }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $length = strlen($this->buffer);
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $length + $offset,
            default => -1,
        };
        if ($target < 0 || $target > $length) {
            return false;
        }
        $this->position = $target;
        return true;
    }
    public function stream_tell(): int
    {
        return $this->position;
    }
    public function stream_close(): void
    {
        $this->resetHandle();
    }
    /** No pending writes exist on a read-only stream (returns true as a no-op). */
    public function stream_flush(): bool
    {
        return true;
    }
    public function stream_write(string $data): int
    {
        return 0;
    }
    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return false;
    }
    public function stream_lock(int $operation): bool
    {
        return false;
    }

    /** @param int $flags STREAM_URL_STAT_* bit mask */
    public function url_stat(string $path, int $flags): array|false
    {
        if (($flags & STREAM_URL_STAT_LINK) !== 0) {
            return false; // No symlinks/hard links in the virtual file system.
        }
        $registry = self::$registry;
        if (!$registry instanceof ManifestRegistry) {
            return false;
        }
        $parsed = StreamPath::parse($path);
        if ($parsed === null) {
            return false;
        }
        try {
            $entry = $registry->get($parsed['id']);
        } catch (\Throwable) {
            return false;
        }
        $manifest = $entry['manifest'];
        if ($parsed['rel'] === '') {
            return StreamStat::directory($entry['mtime'], $parsed['id'] . ':/');
        }
        $spec = $manifest->file($parsed['rel']);
        if ($spec !== null) {
            $size = $spec['size'] ?? 0;
            return StreamStat::file($entry['mtime'], is_int($size) ? $size : 0, $parsed['id'] . '/' . $parsed['rel']);
        }
        if (StreamPath::directoryEntries($manifest, $parsed['rel']) !== null) {
            return StreamStat::directory($entry['mtime'], $parsed['id'] . '/' . $parsed['rel']);
        }
        return false;
    }
    public function dir_opendir(string $path, int $options): bool
    {
        $this->directoryNames = [];
        $this->directoryIndex = 0;
        $registry = self::$registry;
        if (!$registry instanceof ManifestRegistry) {
            return false;
        }
        $parsed = StreamPath::parse($path);
        if ($parsed === null) {
            return false;
        }
        try {
            $entry = $registry->get($parsed['id']);
        } catch (\Throwable) {
            return false;
        }
        $names = StreamPath::directoryEntries($entry['manifest'], $parsed['rel']);
        if ($names === null) {
            return false;
        }
        $this->directoryNames = array_merge(['.', '..'], $names);
        return true;
    }
    public function dir_readdir(): string|false
    {
        if (!array_key_exists($this->directoryIndex, $this->directoryNames)) {
            return false;
        }
        return $this->directoryNames[$this->directoryIndex++];
    }
    public function dir_closedir(): bool
    {
        $this->directoryNames = [];
        $this->directoryIndex = 0;
        return true;
    }
    public function dir_rewinddir(): bool
    {
        $this->directoryIndex = 0;
        return true;
    }
    public function mkdir(string $path, int $mode = 0777, int $options = 0): bool
    {
        return false;
    }
    public function rmdir(string $path, int $options = 0): bool
    {
        return false;
    }
    public function rename(string $from, string $to, int $options = 0): bool
    {
        return false;
    }
    public function unlink(string $path, int $options = 0): bool
    {
        return false;
    }

    private function resetHandle(): void
    {
        $this->buffer = '';
        $this->position = 0;
        $this->openStat = null;
        $this->directoryNames = [];
        $this->directoryIndex = 0;
    }

    /**
     * @param array<string, mixed> $spec manifest file record
     * @param array{root: string, manifest: \VGT\Omega\System\Vault\Crypto\Manifest, key: string, mtime: int} $entry
     */
    private static function readPlaintext(string $artifactId, string $relativePath, array $spec, array $entry): ?string
    {
        $cacheKey = $artifactId . '|' . $entry['mtime'] . '|' . $relativePath;
        $cached = self::plaintextCacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $blobId = $spec['blob'] ?? null;
        if (!is_string($blobId) || preg_match('/^[a-f0-9]{32}$/', $blobId) !== 1) {
            return null;
        }
        $root = $entry['root'];
        $blobPath = $root . '/blobs/' . $blobId . VaultFormat::BLOB_EXTENSION;
        if (!is_file($blobPath) || is_link($blobPath) || !PathGuard::isInside($blobPath, $root)) {
            error_log('[VGT][STREAM] Blob file unavailable for a mounted artifact.');
            return null;
        }
        $encrypted = ($spec['mode'] ?? '') === 'encrypted';
        $cipherKey = $encrypted ? self::apcuKey($artifactId, $blobId) : '';
        $container = $encrypted ? self::apcuGet($cipherKey) : null;
        if ($container === null) {
            $container = self::readBoundedFile($blobPath);
            if ($container === null) {
                return null;
            }
            if ($encrypted) {
                self::apcuPut($cipherKey, $container); // Ciphertext only, never plaintext.
            }
        }
        if ($encrypted) {
            $crypto = self::$crypto;
            if (!$crypto instanceof CryptoEngine) {
                return null;
            }
            $plaintext = $crypto->decrypt($container, $entry['key'], VaultFormat::aad($artifactId, $relativePath), false);
            $declaredSize = $spec['size'] ?? null;
            if (!is_int($declaredSize) || strlen($plaintext) !== $declaredSize) {
                return null;
            }
            self::plaintextCachePut($cacheKey, $plaintext);
            return $plaintext;
        }
        $declaredSize = $spec['size'] ?? null;
        $expectedHash = $spec['sha256'] ?? null;
        if (!is_int($declaredSize) || !is_string($expectedHash) || strlen($container) !== $declaredSize) {
            return null;
        }
        if (!hash_equals($expectedHash, hash('sha256', $container))) {
            return null;
        }
        self::plaintextCachePut($cacheKey, $container);
        return $container;
    }

    private static function readBoundedFile(string $path): ?string
    {
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > VaultFormat::MAX_ARCHIVE_BYTES) {
            return null;
        }
        $bytes = @file_get_contents($path, false, null, 0, $size);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            return null;
        }
        return $bytes;
    }

    private static function apcuEnabled(): bool
    {
        if (self::$apcuEnabled !== null) {
            return self::$apcuEnabled;
        }
        self::$apcuEnabled = defined(self::APCU_FLAG)
            && constant(self::APCU_FLAG) === true
            && function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && (!function_exists('apcu_enabled') || apcu_enabled());
        return self::$apcuEnabled;
    }
    private static function apcuKey(string $artifactId, string $blobId): string
    {
        return VaultFormat::APCU_BLOB_PREFIX . hash('sha256', $artifactId . '|' . $blobId);
    }
    private static function apcuGet(string $key): ?string
    {
        if (!self::apcuEnabled()) {
            return null;
        }
        $cached = @apcu_fetch($key);
        return is_string($cached) && $cached !== '' ? $cached : null;
    }
    /** Cache the authenticated ciphertext container only; never plaintext. */
    private static function apcuPut(string $key, string $ciphertext): void
    {
        if (!self::apcuEnabled() || $ciphertext === '' || strlen($ciphertext) > VaultFormat::MAX_ARCHIVE_BYTES) {
            return;
        }
        @apcu_store($key, $ciphertext, VaultFormat::APCU_BLOB_TTL);
    }
    private static function plaintextCacheGet(string $key): ?string
    {
        return array_key_exists($key, self::$plaintextCache) ? self::$plaintextCache[$key] : null;
    }
    private static function plaintextCachePut(string $key, string $plaintext): void
    {
        $bytes = strlen($plaintext);
        if ($bytes < 1 || $bytes > self::CACHE_MAX_BYTES) {
            return;
        }
        if (array_key_exists($key, self::$plaintextCache)) {
            self::$plaintextCacheBytes -= strlen(self::$plaintextCache[$key]);
            unset(self::$plaintextCache[$key]);
            self::$plaintextOrder = array_values(array_filter(
                self::$plaintextOrder,
                static fn (string $candidate): bool => $candidate !== $key
            ));
        }
        while (self::$plaintextOrder !== []
            && (count(self::$plaintextCache) >= self::CACHE_MAX_ENTRIES
                || self::$plaintextCacheBytes + $bytes > self::CACHE_MAX_BYTES)) {
            $oldest = array_shift(self::$plaintextOrder);
            if ($oldest !== null && array_key_exists($oldest, self::$plaintextCache)) {
                self::$plaintextCacheBytes -= strlen(self::$plaintextCache[$oldest]);
                unset(self::$plaintextCache[$oldest]);
            }
        }
        if (self::$plaintextCacheBytes + $bytes > self::CACHE_MAX_BYTES) {
            return;
        }
        self::$plaintextCache[$key] = $plaintext;
        self::$plaintextOrder[] = $key;
        self::$plaintextCacheBytes += $bytes;
    }
}
