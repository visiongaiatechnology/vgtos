<?php
/**
 * VGT Omega vault path value object.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\System\Vault\VaultFormat;

/**
 * Immutable description of the resolved vault storage layout.
 */
final class VaultPaths
{
    public function __construct(
        public readonly string $vaultDir,
        public readonly bool $isolated,
        public readonly string $publicDir,
        public readonly string $publicBaseUrl
    ) {
    }

    public function artifactsDir(): string
    {
        return $this->vaultDir . '/' . VaultFormat::ARTIFACTS_DIR;
    }

    public function stagingDir(): string
    {
        return $this->vaultDir . '/' . VaultFormat::STAGING_DIR;
    }

    public function trashDir(): string
    {
        return $this->vaultDir . '/' . VaultFormat::TRASH_DIR;
    }

    public function artifactDir(string $artifactId): string
    {
        return $this->artifactsDir() . '/' . $artifactId;
    }

    public function manifestPath(string $artifactId): string
    {
        return $this->artifactDir($artifactId) . '/' . VaultFormat::MANIFEST_FILENAME;
    }

    public function blobDir(string $artifactId): string
    {
        return $this->artifactDir($artifactId) . '/blobs';
    }

    public function blobPath(string $artifactId, string $blobId): string
    {
        return $this->blobDir($artifactId) . '/' . $blobId . VaultFormat::BLOB_EXTENSION;
    }

    /** Display path with the filesystem root hidden from the client. */
    public function describeVaultDir(): string
    {
        $normalized = str_replace('\\', '/', $this->vaultDir);
        $content = str_replace('\\', '/', (string) (defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : ''));

        if ($content !== '' && str_starts_with($normalized, $content)) {
            return 'wp-content' . substr($normalized, strlen($content));
        }

        return basename($normalized) === '' ? $normalized : '…/' . basename($normalized);
    }
}
