<?php
/**
 * VGT Omega archive path validator.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Stateful validator for the canonical path namespace of one ZIP archive.
 *
 * A single instance validates one archive, so it can reject duplicate and
 * case-colliding entries, file-versus-directory conflicts and Windows-reserved
 * device names across the whole member list. Names must be canonical: the
 * validator rejects anything that PathGuard would rewrite ("a//b", "./a",
 * "a/./b"), which keeps the archive namespace and the extracted tree byte-for-
 * byte identical and therefore verifiable.
 */
final class ArchivePathValidator
{
    /** @var array<string, string> lowercase canonical path => file|dir|implicit-dir */
    private array $registry = [];

    public function __construct(private readonly ArchiveLimits $limits)
    {
    }

    /**
     * @return array{name: string, is_dir: bool} canonical name (no trailing slash)
     * @throws SecurityException on traversal, absolute paths, control bytes,
     *                           collisions and other hostile structure
     * @throws ValidationException on exceeded depth/path ceilings
     */
    public function validate(string $rawName): array
    {
        $isDirectory = str_ends_with($rawName, '/');
        $candidate = $isDirectory ? substr($rawName, 0, -1) : $rawName;

        if ($candidate === '') {
            throw new SecurityException('Archive contains an empty entry path.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $rawName) === 1) {
            throw new SecurityException('Archive entry path contains control characters.');
        }

        if (str_contains($rawName, '\\')) {
            throw new SecurityException('Archive entry path contains a backslash.');
        }

        if (str_starts_with($rawName, '/')) {
            throw new SecurityException('Archive entry path is absolute.');
        }

        if (preg_match('/^[A-Za-z]:/', $candidate) === 1) {
            throw new SecurityException('Archive entry path contains a drive letter.');
        }

        if (str_contains($candidate, ':')) {
            throw new SecurityException('Archive entry path contains a stream separator.');
        }

        try {
            $normalized = PathGuard::normalizeRelative($candidate);
        } catch (SecurityException) {
            throw new SecurityException('Archive entry path violates the path jail policy.');
        }

        if ($normalized !== $candidate) {
            throw new SecurityException('Archive entry path is not in canonical form.');
        }

        if (strlen($normalized) > $this->limits->maxPathLength) {
            throw new ValidationException('The package contains a file path that is too long.');
        }

        $segments = explode('/', $normalized);

        if (count($segments) > $this->limits->maxDepth) {
            throw new ValidationException('The package contains a directory hierarchy that is too deep.');
        }

        foreach ($segments as $segment) {
            if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $segment) === 1) {
                throw new SecurityException('Archive entry uses a reserved device name.');
            }
        }

        $this->register($normalized, $isDirectory);

        return ['name' => $normalized, 'is_dir' => $isDirectory];
    }

    /**
     * Register one canonical member in the collision registry and reject
     * duplicates, case-only aliases and file/directory shadowing.
     *
     * @throws SecurityException
     */
    private function register(string $normalized, bool $isDirectory): void
    {
        $key = strtolower($normalized);
        $existing = $this->registry[$key] ?? null;

        if ($isDirectory) {
            if ($existing === 'file') {
                throw new SecurityException('Archive path is declared as both file and directory.');
            }

            if ($existing === 'dir') {
                throw new SecurityException('Archive contains duplicate directory entries.');
            }
        } elseif ($existing !== null) {
            throw new SecurityException('Archive contains duplicate or case-colliding entries.');
        }

        $segments = explode('/', $normalized);
        $ancestor = '';

        for ($index = 0, $last = count($segments) - 1; $index < $last; $index++) {
            $ancestor = $ancestor === '' ? $segments[$index] : $ancestor . '/' . $segments[$index];
            $ancestorKey = strtolower($ancestor);
            $state = $this->registry[$ancestorKey] ?? null;

            if ($state === 'file') {
                throw new SecurityException('Archive entry traverses through a declared file.');
            }

            if ($state === null) {
                $this->registry[$ancestorKey] = 'implicit-dir';
            }
        }

        $this->registry[$key] = $isDirectory ? 'dir' : 'file';
    }
}
