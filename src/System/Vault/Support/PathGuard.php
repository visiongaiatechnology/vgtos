<?php
/**
 * VGT Omega path security primitives.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Support;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;

/**
 * Canonical path validation and jail enforcement (VGT Coder Pattern 1.5.E).
 *
 * All artifact-relative paths coming from ZIP archives, manifests, HTTP input
 * or stream-wrapper URIs pass through this class. It rejects traversal in both
 * slash directions, NUL/control bytes, Windows drive/ADS syntax, overlong
 * paths and empty segments before any file system call happens.
 */
final class PathGuard
{
    public const MAX_PATH_LENGTH = 255;

    public const MAX_SEGMENT_LENGTH = 100;

    public const MAX_DEPTH = 20;

    public const ARTIFACT_ID_PATTERN = '/^vgt_[a-f0-9]{24}$/';

    /** Legacy timestamp-based ids from v4.0/4.1 migrations. */
    public const LEGACY_ARTIFACT_ID_PATTERN = '/^vgt_[a-f0-9]{13}$/';

    public const BLOB_NAME_PATTERN = '/^[a-f0-9]{32}\.vgtblob$/';

    /**
     * Normalize and validate an artifact-relative path.
     *
     * @throws SecurityException when the path could escape the artifact root
     */
    public static function normalizeRelative(string $path): string
    {
        if ($path === '') {
            throw new SecurityException('Empty artifact path rejected.');
        }

        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new SecurityException('Control character in artifact path rejected.');
        }

        $path = str_replace('\\', '/', $path);

        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new SecurityException('Artifact path exceeds the maximum length.');
        }

        if (str_starts_with($path, '/')) {
            throw new SecurityException('Absolute artifact path rejected.');
        }

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new SecurityException('Windows drive path rejected.');
        }

        if (str_contains($path, ':')) {
            throw new SecurityException('Alternate data stream path rejected.');
        }

        $segments = explode('/', $path);
        $clean = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new SecurityException('Path traversal rejected.');
            }

            if (strlen($segment) > self::MAX_SEGMENT_LENGTH) {
                throw new SecurityException('Artifact path segment exceeds the maximum length.');
            }

            if (str_ends_with($segment, ' ') || str_ends_with($segment, '.')) {
                throw new SecurityException('Windows-hostile path segment rejected.');
            }

            $clean[] = $segment;
        }

        if ($clean === []) {
            throw new SecurityException('Artifact path resolves to the root.');
        }

        if (count($clean) > self::MAX_DEPTH) {
            throw new SecurityException('Artifact path depth exceeds the limit.');
        }

        return implode('/', $clean);
    }

    /** True when the relative path passes validation (no exception). */
    public static function isSafeRelative(string $path): bool
    {
        try {
            self::normalizeRelative($path);

            return true;
        } catch (SecurityException) {
            return false;
        }
    }

    /**
     * Join a validated relative path onto an existing jail directory and prove
     * the canonical result stays inside that directory.
     */
    public static function joinInside(string $jailDir, string $relativePath): string
    {
        $rootReal = realpath($jailDir);

        if ($rootReal === false || !is_dir($rootReal)) {
            throw new StorageException('Storage jail directory is unavailable.');
        }

        $normalized = self::normalizeRelative($relativePath);
        $root = rtrim(str_replace('\\', '/', $rootReal), '/');
        $candidate = $root . '/' . $normalized;

        if (!str_starts_with($candidate, $root . '/')) {
            throw new SecurityException('Path escaped the storage jail.');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $candidate);
    }

    /** Assert that a path is inside a root directory (both must already exist). */
    public static function isInside(string $path, string $rootDir): bool
    {
        $root = realpath($rootDir);
        if ($root === false) {
            return false;
        }

        $target = realpath($path);
        if ($target === false) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $target = str_replace('\\', '/', $target);

        return $target === $root || str_starts_with($target, $root . '/');
    }

    public static function isValidArtifactId(string $id, bool $allowLegacy = true): bool
    {
        if (preg_match(self::ARTIFACT_ID_PATTERN, $id) === 1) {
            return true;
        }

        return $allowLegacy && preg_match(self::LEGACY_ARTIFACT_ID_PATTERN, $id) === 1;
    }

    /** @throws SecurityException */
    public static function assertArtifactId(string $id, bool $allowLegacy = true): void
    {
        if (!self::isValidArtifactId($id, $allowLegacy)) {
            throw new SecurityException('Invalid artifact identifier.');
        }
    }

    /** @throws SecurityException */
    public static function assertBlobName(string $name): void
    {
        if (preg_match(self::BLOB_NAME_PATTERN, $name) !== 1) {
            throw new SecurityException('Invalid blob identifier.');
        }
    }

    /** Generate a collision-resistant 96-bit artifact id. */
    public static function newArtifactId(): string
    {
        return 'vgt_' . bin2hex(random_bytes(12));
    }

    /** Generate a 128-bit blob id (hex, without extension). */
    public static function newBlobId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
