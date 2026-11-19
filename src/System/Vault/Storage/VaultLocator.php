<?php
/**
 * GeDefense WPR vault locator.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Resolves and provisions the encrypted vault directory.
 *
 * Candidate order (first writable candidate wins, isolated candidates are
 * preferred over fallbacks that live inside the document root):
 *   1. VGT_OMEGA_VAULT_DIR override (absolute paths only)
 *   2. dirname(ABSPATH)/.vgt-vault
 *   3. dirname(ABSPATH, 2)/.vgt-vault
 *   4. ContentDir/vgt-vault (document-root fallback, never isolated)
 *
 * Writability is proven with a random byte probe instead of trusting
 * is_writable() alone, all vault subdirectories are created with 0700 and the
 * public mirror lives under the uploads directory with its own URL. When no
 * candidate can be provisioned the locator fails closed with an
 * AvailabilityException (the vault never falls back to plaintext storage).
 */
final class VaultLocator
{
    private const PROBE_PREFIX = 'vgt_probe_';

    public function __construct(private readonly EnvironmentInterface $environment)
    {
    }

    /** @throws AvailabilityException when no writable vault directory exists */
    public function locate(): VaultPaths
    {
        $documentRoot = $this->canonicalDirectory($this->environment->getDocumentRoot(), false);
        $fallback = null;

        foreach ($this->candidateDirectories() as $label => $candidate) {
            $prepared = $this->prepareVaultDirectory($candidate, $label);

            if ($prepared === null) {
                continue;
            }

            $isolated = $documentRoot !== null && !$this->isInside($prepared, $documentRoot);

            if ($isolated) {
                return $this->buildPaths($prepared, true);
            }

            $fallback ??= $prepared;
        }

        if ($fallback === null) {
            throw new AvailabilityException('No writable vault directory is available on this host.');
        }

        error_log(
            '[VGT][SECURITY] Vault directory is reachable from the document root; '
            . 'web-server isolation is unverifiable. Harden the host or define VGT_OMEGA_VAULT_DIR.'
        );

        return $this->buildPaths($fallback, false);
    }

    /** @return array<string, string> */
    private function candidateDirectories(): array
    {
        $candidates = [];
        $override = defined('VGT_OMEGA_VAULT_DIR') ? (string) constant('VGT_OMEGA_VAULT_DIR') : '';

        if ($override !== '') {
            $normalized = $this->absolutePath($override);

            if ($normalized === null) {
                $this->log('VGT_OMEGA_VAULT_DIR is not an absolute path; override ignored.');
            } else {
                $candidates['override'] = $normalized;
            }
        }

        // The host abstraction resolves ABSPATH; the global constant is only a
        // defensive fallback so CLI tooling without a host environment still
        // places the vault next to the application root.
        $hostAbspath = $this->environment->getAbspath();

        $abspath = $this->absolutePath(
            $hostAbspath !== '' ? $hostAbspath : (defined('ABSPATH') ? (string) constant('ABSPATH') : '')
        );

        if ($abspath !== null) {
            $candidates['parent'] = dirname($abspath) . '/' . '.vgt-vault';
            $candidates['grandparent'] = dirname($abspath, 2) . '/' . '.vgt-vault';
        }

        $content = $this->absolutePath($this->environment->getContentDir());

        if ($content !== null) {
            $candidates['content'] = $content . '/vgt-vault';
        }

        return $candidates;
    }

    private function prepareVaultDirectory(string $candidate, string $label): ?string
    {
        try {
            $vaultDir = SafeFilesystem::ensureDirectory($candidate, 0700);

            if ($this->rootDirectory($vaultDir) || !$this->proveWritable($vaultDir)) {
                $this->log('Vault candidate "' . $label . '" is not usable as a storage root.');

                return null;
            }

            @chmod($vaultDir, 0700);

            foreach ([VaultFormat::ARTIFACTS_DIR, VaultFormat::STAGING_DIR, VaultFormat::TRASH_DIR] as $name) {
                $child = SafeFilesystem::ensureDirectory($vaultDir . '/' . $name, 0700);

                // is_writable() is not trustworthy on every Windows/ACL host, so
                // writability is always proven by an actual random byte probe.
                if (!$this->proveWritable($child)) {
                    $this->log('Vault candidate "' . $label . '" has an unwritable subdirectory.');

                    return null;
                }

                @chmod($child, 0700);
            }

            return $vaultDir;
        } catch (SecurityException | StorageException $error) {
            $this->log('Vault candidate "' . $label . '" rejected: ' . $error->getMessage());

            return null;
        }
    }

    private function buildPaths(string $vaultDir, bool $isolated): VaultPaths
    {
        [$publicDir, $publicBaseUrl] = $this->preparePublicMirror();

        return new VaultPaths($vaultDir, $isolated, $publicDir, $publicBaseUrl);
    }

    /** @return array{0: string, 1: string} */
    private function preparePublicMirror(): array
    {
        $uploadDir = $this->absolutePath($this->environment->getUploadDir());

        if ($uploadDir === null) {
            return ['', ''];
        }

        try {
            $publicDir = SafeFilesystem::ensureDirectory($uploadDir . '/' . VaultFormat::PUBLIC_DIR_NAME, 0755);

            if (!$this->proveWritable($publicDir)) {
                $this->log('Public asset mirror is not writable; raw assets stay unavailable over HTTP.');

                return ['', ''];
            }

            @chmod($publicDir, 0755);
        } catch (SecurityException | StorageException $error) {
            $this->log('Public asset mirror could not be provisioned: ' . $error->getMessage());

            return ['', ''];
        }

        $baseUrl = rtrim(str_replace('\\', '/', $this->environment->getUploadBaseUrl()), '/');
        $publicBaseUrl = $baseUrl !== '' ? $baseUrl . '/' . VaultFormat::PUBLIC_DIR_NAME : '';

        return [$publicDir, $publicBaseUrl];
    }

    private function proveWritable(string $directory): bool
    {
        $probe = $directory . '/' . self::PROBE_PREFIX . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($probe, 'xb');

        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, 'vgt');
        @fclose($handle);
        $removed = @unlink($probe);

        return $written === 3 && $removed;
    }

    private function isInside(string $path, string $root): bool
    {
        $pathReal = realpath($path);
        $rootReal = realpath($root);

        if ($pathReal === false || $rootReal === false) {
            return false;
        }

        $pathReal = str_replace('\\', '/', $pathReal);
        $rootReal = str_replace('\\', '/', $rootReal);

        if (PHP_OS_FAMILY === 'Windows') {
            $pathReal = strtolower($pathReal);
            $rootReal = strtolower($rootReal);
        }

        $rootReal = rtrim($rootReal, '/');

        return $pathReal === $rootReal || str_starts_with($pathReal, $rootReal . '/');
    }

    private function canonicalDirectory(string $value, bool $absolute = true): ?string
    {
        $normalized = rtrim(str_replace('\\', '/', trim($value)), '/');

        if ($normalized === '' || ($absolute && preg_match('#^(/|[A-Za-z]:/)#', $normalized) !== 1)) {
            return null;
        }

        return $normalized;
    }

    private function absolutePath(string $value): ?string
    {
        $normalized = $this->canonicalDirectory($value, true);

        if ($normalized === null) {
            return null;
        }

        return $normalized;
    }

    private function rootDirectory(string $directory): bool
    {
        return preg_match('#^(/|[A-Za-z]:/)$#', $directory) === 1 || $directory === '';
    }

    private function log(string $message): void
    {
        error_log('[VGT][STORAGE] ' . ExceptionReporter::sanitizeLogMessage($message));
    }
}
