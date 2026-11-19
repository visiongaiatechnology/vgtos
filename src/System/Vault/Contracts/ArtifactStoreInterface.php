<?php
/**
 * GeDefense WPR artifact store contract.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Contracts;

use VGT\Omega\System\Vault\Crypto\Manifest;

/**
 * Persistence boundary for installed artifacts.
 *
 * Implementations must never expose plaintext: every method returns either the
 * encrypted container bytes or validated metadata. All paths are derived from
 * validated artifact/blob identifiers, never from user input.
 */
interface ArtifactStoreInterface
{
    /** @return list<string> canonical artifact ids present in the vault */
    public function listArtifactIds(): array;

    public function exists(string $artifactId): bool;

    /** Raw encrypted manifest container of an installed artifact, or null. */
    public function readManifestContainer(string $artifactId): ?string;

    /** Raw blob container (ciphertext or intentional public asset), or null. */
    public function readBlob(string $artifactId, string $blobId): ?string;

    /** Canonical absolute directory of an installed artifact (validated id only). */
    public function artifactRoot(string $artifactId): string;

    /**
     * Non-secret artifact metadata.
     *
     * @return array{name: string, updated: int, entry: string, files: int, signed: bool, format: string}
     */
    public function getMeta(string $artifactId): array;

    /** Persist metadata for an artifact. */
    public function setMeta(string $artifactId, array $meta): void;

    /** Atomically remove an artifact directory and its metadata. */
    public function forget(string $artifactId): void;

    /** Stable public URL for a mirrored public asset, or '' when not mirrored. */
    public function publicAssetUrl(string $artifactId, string $relativePath): string;
}
