<?php
/**
 * VGT Omega archive directory validator.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;

/**
 * Backend-neutral policy evaluation of one raw ZIP member directory.
 *
 * ZipArchive and PclZip both return the same normalized member record shape, so
 * every consumption ceiling, entry type check and compression-ratio decision is
 * made exactly once in this class. No bytes are written here.
 */
final class ArchiveDirectoryValidator
{
    private const TYPE_REGULAR = 0x8000;

    private const TYPE_DIRECTORY = 0x4000;

    private const TYPE_SYMLINK = 0xA000;

    public function __construct(private readonly ArchiveLimits $limits)
    {
    }

    /**
     * @param list<array<string, mixed>> $directory
     * @return array{entries: list<array<string, mixed>>, total: int}
     * @throws ValidationException on exceeded consumption ceilings
     * @throws SecurityException on hostile archive structure
     * @throws StorageException on malformed member records
     */
    public function validate(array $directory): array
    {
        if ($directory === []) {
            throw new ValidationException('The package does not contain any files.');
        }

        if (count($directory) > $this->limits->maxEntries) {
            throw new ValidationException('The package contains too many entries.');
        }

        $entries = [];
        $paths = new ArchivePathValidator($this->limits);
        $total = 0;
        $files = 0;

        foreach ($directory as $member) {
            $name = $member['name'] ?? null;

            if (!is_string($name) || $name === '') {
                throw new SecurityException('Archive entry name is malformed.');
            }

            $info = $paths->validate($name);
            $size = self::intValue($member['size'] ?? null);
            $compressed = self::intValue($member['compressed'] ?? null);

            if ($size === null || $compressed === null || $size < 0 || $compressed < 0) {
                throw new StorageException('The package member directory is malformed.');
            }

            $external = $member['external'] ?? null;
            $this->assertEntryAttributes(is_int($external) ? $external : null, $info['is_dir']);

            if ($info['is_dir']) {
                if ($size !== 0 || $compressed !== 0) {
                    throw new SecurityException('A directory entry carries payload data.');
                }
            } else {
                if ($size > $this->limits->maxFileBytes) {
                    throw new ValidationException('The package contains a file that is too large.');
                }

                $this->assertCompressionRatio($size, $compressed);
                $files++;
            }

            $total += $size;

            if ($total > $this->limits->maxTotalUncompressed) {
                throw new ValidationException('The expanded package would exceed the maximum total size.');
            }

            $entries[] = [
                'name' => $info['name'],
                'raw' => $name,
                'stored' => is_string($member['stored'] ?? null) ? $member['stored'] : $name,
                'size' => $size,
                'compressed' => $compressed,
                'is_dir' => $info['is_dir'],
                'index' => is_int($member['index'] ?? null) ? $member['index'] : count($entries),
                'crc' => is_int($member['crc'] ?? null) ? $member['crc'] : null,
            ];
        }

        if ($files === 0) {
            throw new ValidationException('The package does not contain any files.');
        }

        return ['entries' => $entries, 'total' => $total];
    }

    private function assertCompressionRatio(int $size, int $compressed): void
    {
        if ($size === 0) {
            return;
        }

        if ($compressed <= 0 || $size > $this->limits->maxCompressionRatio * $compressed) {
            throw new SecurityException('A package entry exceeds the permitted compression ratio.');
        }
    }

    private function assertEntryAttributes(?int $external, bool $isDirectory): void
    {
        if ($external === null) {
            return;
        }

        $type = ($external >> 16) & 0xF000;

        if ($type === 0) {
            return;
        }

        if ($type === self::TYPE_SYMLINK) {
            throw new SecurityException('Symbolic link entries are not permitted in packages.');
        }

        if ($type === self::TYPE_DIRECTORY) {
            if (!$isDirectory) {
                throw new SecurityException('Archive entry type conflicts with its path.');
            }

            return;
        }

        if ($type === self::TYPE_REGULAR) {
            if ($isDirectory) {
                throw new SecurityException('Archive entry type conflicts with its path.');
            }

            return;
        }

        throw new SecurityException('Unsupported special file entry in package.');
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value;
        }

        return null;
    }
}
