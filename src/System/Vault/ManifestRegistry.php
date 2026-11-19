<?php
/**
 * VGT Omega mounted artifact registry.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\ConflictException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Crypto\Manifest;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Process-local registry of authenticated artifact manifests.
 *
 * Only ArtifactMounter may register an entry: an artifact lands here after its
 * encrypted manifest was authenticated, its blobs were proven present and its
 * optional Ed25519 signature was verified. Every reader (stream wrapper,
 * classmap autoloader, asset resolver) treats this registry as the single
 * source of truth for "may this artifact be executed or read in this request".
 *
 * The per-artifact key is secret material. It is held in memory only, never
 * logged, never serialized and never exposed to diagnostics or HTTP output.
 * A duplicate registration is rejected so a compromised loader path cannot
 * shadow an already authenticated artifact with a different manifest or key.
 */
final class ManifestRegistry
{
    /** @var array<string, array{root: string, manifest: Manifest, key: string, mtime: int}> */
    private array $entries = [];

    /**
     * Register an authenticated artifact.
     *
     * @param string $root  canonical absolute artifact directory (slash normalized)
     * @param string $key   32 raw bytes of the per-artifact AES-256 key
     * @param int    $mtime stable manifest modification time used by url_stat()
     *
     * @throws SecurityException when the identifier, root or key is malformed
     * @throws ConflictException when the artifact is already registered
     */
    public function register(string $artifactId, string $root, Manifest $manifest, string $key, int $mtime): void
    {
        PathGuard::assertArtifactId($artifactId);

        if (isset($this->entries[$artifactId])) {
            throw new ConflictException('Artifact is already mounted in this request.');
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if ($normalizedRoot === ''
            || str_contains($normalizedRoot, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $normalizedRoot) === 1) {
            throw new SecurityException('Artifact root path is malformed.');
        }

        if (strlen($key) !== 32) {
            throw new SecurityException('Artifact key material is malformed.');
        }

        if ($mtime < 0) {
            throw new SecurityException('Artifact manifest timestamp is malformed.');
        }

        $this->entries[$artifactId] = [
            'root' => $normalizedRoot,
            'manifest' => $manifest,
            'key' => $key,
            'mtime' => $mtime,
        ];
    }

    public function has(string $artifactId): bool
    {
        return isset($this->entries[$artifactId]);
    }

    /**
     * @return array{root: string, manifest: Manifest, key: string, mtime: int}
     *
     * @throws AvailabilityException when the artifact is not mounted
     */
    public function get(string $artifactId): array
    {
        if (!isset($this->entries[$artifactId])) {
            throw new AvailabilityException('Artifact is not mounted in this request.');
        }

        return $this->entries[$artifactId];
    }

    /**
     * Registry view for internal consumers only (never rendered to HTTP).
     *
     * @return array<string, array{root: string, manifest: Manifest, key: string, mtime: int}>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Roll an artifact back after a failed entry load.
     *
     * @internal Used by ArtifactMounter only; never called from an HTTP layer.
     */
    public function forget(string $artifactId): bool
    {
        if (!isset($this->entries[$artifactId])) {
            return false;
        }

        unset($this->entries[$artifactId]);

        return true;
    }
}
