<?php
/**
 * VGT Omega artifact format constants.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

/**
 * Single source of truth for the encrypted artifact format v4.2.
 *
 * File layout (all integers are raw bytes):
 *
 *   offset 0   : "VGT_GUARD" magic (9 bytes)
 *   offset 9   : format version byte 0x42
 *   offset 10  : flags (bit 0 = gzip DEFLATE applied to the plaintext)
 *   offset 11  : AES-256-GCM IV (12 bytes)
 *   offset 23  : AES-256-GCM authentication tag (16 bytes)
 *   offset 39  : ciphertext (variable)
 *
 * Legacy v4.0/4.1 artifacts (magic + IV + tag + ciphertext, no version/flags)
 * are accepted for cloud-key import only; they are never mounted directly.
 */
final class VaultFormat
{
    public const MAGIC = 'VGT_GUARD';

    public const MAGIC_LENGTH = 9;

    public const VERSION_BYTE = 0x42;

    public const FLAG_NONE = 0x00;

    public const FLAG_GZIP = 0x01;

    public const IV_LENGTH = 12;

    public const TAG_LENGTH = 16;

    public const HEADER_LENGTH = 39;

    /** Legacy v4.x header: magic + IV + tag (no version/flags byte). */
    public const LEGACY_HEADER_LENGTH = 37;

    public const SPEC_VERSION = 'vgt-artifact/4.2';

    public const LEGACY_SPEC_VERSIONS = ['vgt-artifact/4.0', 'vgt-artifact/4.1'];

    public const MANIFEST_FILENAME = 'vgt_manifest.vgt';

    public const BLOB_EXTENSION = '.vgtblob';

    public const ARTIFACTS_DIR = 'artifacts';

    public const STAGING_DIR = '.staging';

    public const TRASH_DIR = '.trash';

    public const PUBLIC_DIR_NAME = 'vgt-public';

    /** Cap for the encrypted manifest container (2 MiB). */
    public const MAX_MANIFEST_BYTES = 2097152;

    /** Cap for one decrypted payload file (8 MiB). */
    public const MAX_PLAINTEXT_FILE_BYTES = 8388608;

    /** Cap for one uploaded artifact archive on disk (16 MiB). */
    public const MAX_ARCHIVE_BYTES = 16777216;

    /** Cap for the sum of all decrypted payload bytes in one artifact (32 MiB). */
    public const MAX_TOTAL_PLAINTEXT_BYTES = 33554432;

    /** Files below this size are stored without DEFLATE ("VGT_RAW" smart compression). */
    public const RAW_COMPRESSION_THRESHOLD = 16384;

    /** Extensions that may be stored as cleartext public assets (mirrored outside the vault). */
    public const PUBLIC_ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
        'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm',
        'mp3', 'ogg', 'wav', 'txt', 'md', 'json', 'pdf',
    ];

    /**
     * Mirrored public assets must never execute same-origin script. SVG is
     * deliberately excluded from cleartext asset delivery because navigating
     * directly to an SVG document can execute embedded script.
     */
    public const SVG_EXTENSION_BLOCKED = true;

    /** APCu key prefix for authenticated ciphertext caching (never plaintext). */
    public const APCU_BLOB_PREFIX = 'vgt_blob_';

    /** APCu ciphertext cache TTL in seconds. */
    public const APCU_BLOB_TTL = 600;

    /** Extensions that must never be marked as "raw" inside an artifact. */
    public const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'inc', 'module', 'install', 'theme', 'engine', 'profile', 'sh', 'bash',
        'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py',
    ];

    /** AAD for payload files inside an installed (hardware-bound) artifact. */
    public static function aad(string $artifactId, string $relativePath): string
    {
        return 'vgt:v4:' . $artifactId . ':' . $relativePath;
    }

    /** AAD for the encrypted manifest of an installed artifact. */
    public static function manifestAad(string $artifactId): string
    {
        return 'vgt:v4:' . $artifactId . ':/' . self::MANIFEST_FILENAME;
    }

    /** AAD used by the reference encryptor while a cloud key protects the package. */
    public static function cloudAad(string $relativePath): string
    {
        return 'vgt:pack:' . $relativePath;
    }

    /** AAD used by the reference encryptor for the package manifest. */
    public static function cloudManifestAad(): string
    {
        return 'vgt:pack:/' . self::MANIFEST_FILENAME;
    }

    public static function isPublicAssetPath(string $relativePath): bool
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::PUBLIC_ASSET_EXTENSIONS, true);
    }

    public static function isExecutablePath(string $relativePath): bool
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::EXECUTABLE_EXTENSIONS, true);
    }
}
