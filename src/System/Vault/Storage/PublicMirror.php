<?php
/**
 * GeDefense WPR public asset mirror.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Mirrors intentionally cleartext public assets into the uploads directory.
 *
 * Artifacts stay encrypted in the vault; only manifest-approved raw assets
 * (CSS, JS, images, fonts, media, documents — never executables) are copied to
 * <uploads>/vgt-public/<artifact-id>/<relative-path> so the browser can load
 * them without a PHP proxy. The mirror is never a trust source: every write is
 * jailed with PathGuard and every URL is re-resolved against the real file.
 */
final class PublicMirror
{
    public function __construct(
        private readonly VaultPaths $paths,
        private readonly ?EnvironmentInterface $environment = null
    ) {
    }

    /** Stable public URL for a mirrored asset, or '' when it is not served. */
    public function url(string $artifactId, string $relativePath): string
    {
        try {
            PathGuard::assertArtifactId($artifactId);
            $relative = PathGuard::normalizeRelative($relativePath);
        } catch (SecurityException) {
            return '';
        }

        if (!VaultFormat::isPublicAssetPath($relative) || VaultFormat::isExecutablePath($relative)) {
            return '';
        }

        $root = $this->root();

        if ($root === null || $this->paths->publicBaseUrl === '') {
            return '';
        }

        $candidate = $root . '/' . $artifactId . '/' . $relative;

        if (is_link($candidate) || !is_file($candidate) || !PathGuard::isInside($candidate, $root)) {
            return '';
        }

        $segments = array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $relative)
        );

        return rtrim($this->paths->publicBaseUrl, '/')
            . '/' . $artifactId
            . '/' . implode('/', $segments);
    }

    /**
     * Mirror cleartext public assets; every failure is non-fatal and logged.
     *
     * @param array<string, string> $assets relative path => cleartext bytes
     */
    public function publish(string $artifactId, array $assets): void
    {
        PathGuard::assertArtifactId($artifactId);

        if ($assets === []) {
            return;
        }

        $root = $this->root();

        if ($root === null) {
            $this->log('Public mirror unavailable; raw assets will not be HTTP-served.');

            return;
        }

        try {
            $artifactRoot = SafeFilesystem::ensureDirectory($root . '/' . $artifactId, 0755);
        } catch (\Throwable $error) {
            $this->log('Public mirror root could not be provisioned: ' . $error->getMessage());

            return;
        }

        foreach ($assets as $relativePath => $bytes) {
            if (!is_string($relativePath) || !is_string($bytes)) {
                continue;
            }

            if (!VaultFormat::isPublicAssetPath($relativePath) || VaultFormat::isExecutablePath($relativePath)) {
                continue;
            }

            try {
                $target = PathGuard::joinInside($artifactRoot, $relativePath);
                SafeFilesystem::ensureDirectory(dirname($target), 0755);
                SafeFilesystem::writeAtomic($target, $bytes, 0644);
            } catch (\Throwable $error) {
                $this->log(
                    'Public asset mirror failed for "' . self::sanitizePath($relativePath) . '": ' . $error->getMessage()
                );
            }
        }
    }

    /** Remove the whole mirror directory of one artifact without following links. */
    public function remove(string $artifactId): void
    {
        PathGuard::assertArtifactId($artifactId);

        $root = $this->root();

        if ($root === null) {
            return;
        }

        $directory = $root . '/' . $artifactId;

        if (is_link($directory)) {
            if (!@unlink($directory)) {
                $this->log('Public mirror link could not be removed.');
            }

            return;
        }

        if (!is_dir($directory) || !PathGuard::isInside($directory, $root)) {
            return;
        }

        try {
            SafeFilesystem::deleteTree($directory);
        } catch (\Throwable $error) {
            $this->log('Public mirror removal failed: ' . $error->getMessage());
        }
    }

    /** Canonical mirror root, or null when the mirror is not web-served. */
    private function root(): ?string
    {
        if ($this->paths->publicDir === '') {
            return null;
        }

        $root = realpath($this->paths->publicDir);

        if ($root === false || !is_dir($root)) {
            return null;
        }

        if ($this->environment instanceof EnvironmentInterface) {
            $upload = realpath($this->environment->getUploadDir());

            if ($upload === false || !PathGuard::isInside($root, $upload)) {
                return null;
            }
        }

        return str_replace('\\', '/', $root);
    }

    private function log(string $message): void
    {
        error_log('[VGT][STORAGE] ' . ExceptionReporter::sanitizeLogMessage($message));
    }

    private static function sanitizePath(string $path): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $path) ?? '', 0, 200);
    }
}
