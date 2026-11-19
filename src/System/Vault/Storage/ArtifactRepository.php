<?php
/**
 * VGT Omega artifact repository.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * File-system persistence boundary of the vault.
 *
 * Returned bytes are always encrypted containers or intentional cleartext
 * public assets; no method ever writes plaintext into the vault. Installed
 * artifact directories are published and removed by rename() based atomic
 * swaps, so a reader can never observe a half-written artifact and an existing
 * artifact is never destroyed before its replacement is fully staged.
 */
final class ArtifactRepository implements ArtifactStoreInterface
{
    private readonly ArtifactMetaStore $meta;

    private readonly PublicMirror $mirror;

    public function __construct(
        private readonly VaultPaths $paths,
        BridgeInterface $bridge,
        EnvironmentInterface $environment
    ) {
        $this->meta = new ArtifactMetaStore($bridge);
        $this->mirror = new PublicMirror($paths, $environment);
    }

    /** @return list<string> */
    public function listArtifactIds(): array
    {
        $artifactsDir = $this->paths->artifactsDir();

        if (is_link($artifactsDir)) {
            throw new SecurityException('The vault artifacts directory is a symbolic link.');
        }

        if (!is_dir($artifactsDir)) {
            throw new StorageException('The vault artifacts directory is unavailable.');
        }

        $entries = @scandir($artifactsDir);

        if ($entries === false) {
            throw new StorageException('The vault artifacts directory could not be listed.');
        }

        $root = realpath($artifactsDir);

        if ($root === false) {
            throw new StorageException('The vault artifacts directory could not be resolved.');
        }

        $root = str_replace('\\', '/', $root);
        $rootPrefix = $root . '/';
        $ids = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !PathGuard::isValidArtifactId($entry)) {
                continue;
            }

            $absolute = $root . '/' . $entry;

            if (is_link($absolute) || !is_dir($absolute)) {
                continue;
            }

            $real = realpath($absolute);

            if ($real === false || !str_starts_with(str_replace('\\', '/', $real) . '/', $rootPrefix)) {
                continue;
            }

            $ids[] = $entry;
        }

        sort($ids, SORT_STRING);

        return $ids;
    }

    public function exists(string $artifactId): bool
    {
        PathGuard::assertArtifactId($artifactId);

        $directory = $this->paths->artifactDir($artifactId);

        if (is_link($directory) || !is_dir($directory)) {
            return false;
        }

        return $this->artifactsRoot() !== null
            && PathGuard::isInside($directory, $this->artifactsRoot());
    }

    public function readManifestContainer(string $artifactId): ?string
    {
        PathGuard::assertArtifactId($artifactId);

        $path = $this->paths->manifestPath($artifactId);

        if (is_link($path)) {
            throw new SecurityException('The artifact manifest path is a symbolic link.');
        }

        clearstatcache(true, $path);

        if (!is_file($path)) {
            return null;
        }

        $size = @filesize($path);

        if ($size === false) {
            throw new StorageException('The artifact manifest could not be measured.');
        }

        if ($size > VaultFormat::MAX_MANIFEST_BYTES) {
            throw new SecurityException('The artifact manifest container exceeds the permitted size.');
        }

        $contents = @file_get_contents($path);

        if (!is_string($contents) || $contents === '') {
            throw new StorageException('The artifact manifest container could not be read.');
        }

        return $contents;
    }

    public function readBlob(string $artifactId, string $blobId): ?string
    {
        PathGuard::assertArtifactId($artifactId);

        if (preg_match('/^[a-f0-9]{32}$/', $blobId) !== 1) {
            throw new SecurityException('Invalid blob identifier.');
        }

        $path = $this->paths->blobPath($artifactId, $blobId);

        if (is_link($path)) {
            throw new SecurityException('An artifact blob path is a symbolic link.');
        }

        if (!is_file($path)) {
            return null;
        }

        return SafeFilesystem::readRegularFile($path, VaultFormat::MAX_ARCHIVE_BYTES);
    }

    public function artifactRoot(string $artifactId): string
    {
        PathGuard::assertArtifactId($artifactId);

        $directory = $this->paths->artifactDir($artifactId);

        if (is_link($directory) || !is_dir($directory)) {
            throw new StorageException('The artifact storage directory is unavailable.');
        }

        $real = realpath($directory);
        $root = $this->artifactsRoot();

        if ($real === false || $root === null || !PathGuard::isInside($real, $root)) {
            throw new SecurityException('The artifact storage directory escaped the vault.');
        }

        return str_replace('\\', '/', $real);
    }

    /** @return array{name: string, updated: int, entry: string, files: int, signed: bool, format: string} */
    public function getMeta(string $artifactId): array
    {
        return $this->meta->get($artifactId);
    }

    public function setMeta(string $artifactId, array $meta): void
    {
        $this->meta->set($artifactId, $meta);
    }

    public function forget(string $artifactId): void
    {
        PathGuard::assertArtifactId($artifactId);

        if (!in_array($artifactId, $this->listArtifactIds(), true)) {
            throw new ValidationException('The selected artifact no longer exists.');
        }

        $target = $this->paths->artifactDir($artifactId);

        if (is_link($target)) {
            throw new SecurityException('The artifact storage directory is a symbolic link.');
        }

        $trashDir = SafeFilesystem::ensureDirectory($this->paths->trashDir(), 0700);
        $detached = $trashDir . '/' . $artifactId . '.' . bin2hex(random_bytes(8));

        if (!@rename($target, $detached)) {
            throw new StorageException('The artifact could not be detached for atomic deletion.');
        }

        try {
            SafeFilesystem::deleteTree($detached);
        } catch (\Throwable $cleanupError) {
            error_log(
                '[VGT][STORAGE] Deleted artifact quarantine cleanup failed: '
                . ExceptionReporter::sanitizeLogMessage($cleanupError->getMessage())
            );
        }

        $this->meta->remove($artifactId);
        CiphertextCache::invalidate();
    }

    public function publicAssetUrl(string $artifactId, string $relativePath): string
    {
        return $this->mirror->url($artifactId, $relativePath);
    }

    /**
     * Atomically replace (or create) the installed artifact directory.
     *
     * The current artifact stays mounted and readable until the fully staged
     * replacement is renamed into place. If the second rename fails the
     * previous artifact is restored, so no failure path can destroy the live
     * artifact.
     *
     * @param array{name?: string, updated?: int, entry?: string, files?: int, signed?: bool, format?: string} $meta
     * @throws SecurityException when the staging directory is outside the vault
     * @throws StorageException when the atomic swap cannot be completed
     */
    public function publish(string $stagingArtifactDir, string $artifactId, array $meta): void
    {
        PathGuard::assertArtifactId($artifactId);

        $staging = realpath($stagingArtifactDir);

        if ($staging === false || !is_dir($staging) || is_link($stagingArtifactDir)) {
            throw new StorageException('The staged artifact directory is unavailable.');
        }

        $stagingRoot = realpath($this->paths->stagingDir());
        $staging = str_replace('\\', '/', $staging);

        if ($stagingRoot === false
            || $staging === str_replace('\\', '/', $stagingRoot)
            || !PathGuard::isInside($staging, $stagingRoot)
        ) {
            throw new SecurityException('The staged artifact is outside the vault staging area.');
        }

        $manifestPath = $staging . '/' . VaultFormat::MANIFEST_FILENAME;

        if (is_link($manifestPath) || !is_file($manifestPath)) {
            throw new StorageException('The staged artifact has no authenticated manifest container.');
        }

        $artifactsRoot = $this->artifactsRoot();

        if ($artifactsRoot === null) {
            throw new StorageException('The vault artifacts directory is unavailable.');
        }

        $target = $artifactsRoot . '/' . $artifactId;

        if (is_link($target)) {
            throw new SecurityException('The artifact target path is a symbolic link.');
        }

        $trashDir = SafeFilesystem::ensureDirectory($this->paths->trashDir(), 0700);
        $backup = null;

        if (is_dir($target)) {
            $backup = $trashDir . '/' . $artifactId . '.' . bin2hex(random_bytes(8));

            if (!@rename($target, $backup)) {
                throw new StorageException('The existing artifact could not be detached for the atomic swap.');
            }
        }

        if (!@rename($staging, $target)) {
            if ($backup !== null && !@rename($backup, $target)) {
                error_log('[VGT][STORAGE][CRITICAL] Artifact rotation rollback failed; manual vault review required.');
            }

            throw new StorageException('The staged artifact could not be published atomically.');
        }

        if ($backup !== null) {
            try {
                SafeFilesystem::deleteTree($backup);
            } catch (\Throwable $cleanupError) {
                error_log(
                    '[VGT][STORAGE] Rotated artifact quarantine cleanup failed: '
                    . ExceptionReporter::sanitizeLogMessage($cleanupError->getMessage())
                );
            }
        }

        try {
            $this->meta->set($artifactId, $meta);
        } catch (\Throwable $metaError) {
            // Metadata is non-secret presentation data: the artifact itself is
            // already published and must not be rolled back for it.
            error_log(
                '[VGT][STORAGE] Artifact metadata persistence failed: '
                . ExceptionReporter::sanitizeLogMessage($metaError->getMessage())
            );
        }

        CiphertextCache::invalidate();
    }

    /** Canonical artifacts directory, or null when it is unusable. */
    private function artifactsRoot(): ?string
    {
        $root = realpath($this->paths->artifactsDir());

        return $root === false || !is_dir($root) ? null : str_replace('\\', '/', $root);
    }
}
