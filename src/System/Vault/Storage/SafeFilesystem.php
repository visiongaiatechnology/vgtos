<?php
/**
 * VGT Omega hardened file system primitives.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;

/**
 * Small, dependency-free storage helper shared by locator, lockdown,
 * repository and installer.
 *
 * Every operation treats the file system as hostile:
 * - symbolic links are never followed and never overwritten,
 * - every canonical result is jailed against its real root,
 * - writes go to a random sibling temp file and are published by rename(),
 * - deletions walk the tree without following links.
 */
final class SafeFilesystem
{
    private const CHUNK = 1048576;

    private function __construct()
    {
    }

    /**
     * Create a directory (recursively) with a restrictive mode and return its
     * canonical slash-normalized path.
     *
     * @throws SecurityException when an existing component is a symlink
     * @throws StorageException when the directory cannot be created
     */
    public static function ensureDirectory(string $dir, int $mode = 0700): string
    {
        if ($dir === '') {
            throw new \InvalidArgumentException('VGT storage: empty directory path.');
        }

        if (is_link($dir)) {
            throw new SecurityException('Storage directory path is a symbolic link.');
        }

        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new StorageException('Storage path exists and is not a directory.');
            }

            if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
                throw new StorageException('Storage directory could not be created.');
            }

            @chmod($dir, $mode);
        }

        $real = realpath($dir);

        if ($real === false || !is_dir($real)) {
            throw new StorageException('Storage directory could not be resolved.');
        }

        return str_replace('\\', '/', $real);
    }

    /**
     * Atomic byte write: random temp file in the destination directory, then
     * rename(). A symbolic link at the destination is never touched.
     *
     * @throws SecurityException when the destination is a symlink
     * @throws StorageException when the write or the publish fails
     */
    public static function writeAtomic(string $file, string $bytes, int $mode = 0644): void
    {
        $directory = realpath(dirname($file));

        if ($directory === false || !is_dir($directory)) {
            throw new StorageException('Storage target directory is unavailable.');
        }

        if (is_link($file)) {
            throw new SecurityException('Refusing to write through a symbolic link.');
        }

        $directory = str_replace('\\', '/', $directory);
        $target = $directory . '/' . basename($file);
        $temp = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temp, 'xb');

        if ($handle === false) {
            throw new StorageException('Temporary storage file could not be created.');
        }

        try {
            $length = strlen($bytes);
            $written = 0;

            while ($written < $length) {
                $chunk = @fwrite($handle, substr($bytes, $written, self::CHUNK));

                if ($chunk === false || $chunk === 0) {
                    throw new StorageException('Storage write failed before completion.');
                }

                $written += $chunk;
            }

            if (!@fflush($handle)) {
                throw new StorageException('Storage buffer could not be flushed.');
            }

            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (\Throwable $error) {
            @fclose($handle);
            @unlink($temp);

            throw $error;
        }

        @fclose($handle);
        @chmod($temp, $mode);

        if (@rename($temp, $target)) {
            @chmod($target, $mode);

            return;
        }

        // Some Windows file systems refuse rename() over an existing file.
        if (!is_link($target) && is_file($target) && @unlink($target) && @rename($temp, $target)) {
            @chmod($target, $mode);

            return;
        }

        @unlink($temp);

        throw new StorageException('Storage file could not be published atomically.');
    }

    /**
     * Read a regular file with a hard byte cap.
     *
     * @throws SecurityException when the path is a symlink or exceeds the cap
     * @throws StorageException when the file is missing or unreadable
     */
    public static function readRegularFile(string $file, int $maxBytes): string
    {
        if (is_link($file)) {
            throw new SecurityException('Symbolic link rejected for storage read.');
        }

        if (!is_file($file)) {
            throw new StorageException('Storage file is missing.');
        }

        $size = @filesize($file);

        if ($size === false || $size < 0) {
            throw new StorageException('Storage file size is unreadable.');
        }

        if ($size > $maxBytes) {
            throw new SecurityException('Storage file exceeds the permitted size.');
        }

        $contents = @file_get_contents($file);

        if (!is_string($contents)) {
            throw new StorageException('Storage file could not be read.');
        }

        return $contents;
    }

    /**
     * Recursively delete a file tree without ever following a symbolic link.
     *
     * @throws StorageException when a node cannot be removed
     */
    public static function deleteTree(string $path): void
    {
        if (is_link($path)) {
            if (!@unlink($path)) {
                throw new StorageException('Symbolic link could not be removed.');
            }

            return;
        }

        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            if (!@unlink($path)) {
                throw new StorageException('Storage file could not be removed.');
            }

            return;
        }

        if (!is_dir($path)) {
            throw new StorageException('Unsupported storage node type.');
        }

        $entries = @scandir($path);

        if ($entries === false) {
            throw new StorageException('Storage directory could not be listed for removal.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::deleteTree($path . DIRECTORY_SEPARATOR . $entry);
        }

        if (!@rmdir($path)) {
            throw new StorageException('Storage directory could not be removed.');
        }
    }

    /** True when the directory exists and contains no entries. */
    public static function isEmptyDirectory(string $dir): bool
    {
        if (!is_dir($dir) || is_link($dir)) {
            return false;
        }

        $entries = @scandir($dir);

        return $entries !== false && count($entries) === 2;
    }

    /**
     * Canonical audit of a storage/jail tree: rejects symlinks and special
     * files, proves every entry stays inside the canonical root and returns
     * relative file paths with their measured sizes.
     *
     * @return array{files: array<string, int>, dirs: list<string>, bytes: int}
     * @throws SecurityException on symlinks, special files or jail escapes
     * @throws StorageException on unreadable directories or files
     */
    public static function scanTree(string $root): array
    {
        $rootReal = realpath($root);

        if ($rootReal === false || !is_dir($rootReal) || is_link($root)) {
            throw new StorageException('Storage jail root is unavailable.');
        }

        $rootReal = str_replace('\\', '/', $rootReal);
        $prefix = $rootReal . '/';
        $files = [];
        $dirs = [];
        $bytes = 0;
        $stack = [''];

        while ($stack !== []) {
            $relative = (string) array_pop($stack);
            $absolute = $relative === '' ? $rootReal : $rootReal . '/' . $relative;
            $entries = @scandir($absolute);

            if ($entries === false) {
                throw new StorageException('Storage directory could not be listed.');
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
                $childAbsolute = $rootReal . '/' . $childRelative;

                if (is_link($childAbsolute)) {
                    throw new SecurityException('Symbolic link detected inside the storage tree.');
                }

                $childReal = realpath($childAbsolute);

                if ($childReal === false) {
                    throw new StorageException('Storage tree node could not be resolved.');
                }

                $childReal = str_replace('\\', '/', $childReal);

                if (!str_starts_with($childReal . '/', $prefix)) {
                    throw new SecurityException('Storage tree node escaped the jail.');
                }

                if (is_dir($childReal)) {
                    $dirs[] = $childRelative;
                    $stack[] = $childRelative;
                    continue;
                }

                if (!is_file($childReal)) {
                    throw new SecurityException('Unsupported file type inside the storage tree.');
                }

                $size = @filesize($childReal);

                if ($size === false || $size < 0) {
                    throw new StorageException('Storage file size is unreadable.');
                }

                $files[$childRelative] = (int) $size;
                $bytes += (int) $size;
            }
        }

        return ['files' => $files, 'dirs' => $dirs, 'bytes' => $bytes];
    }
}
