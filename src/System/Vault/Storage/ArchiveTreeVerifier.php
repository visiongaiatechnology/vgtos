<?php
/**
 * GeDefense WPR extracted tree verifier.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Post-extraction proof for an untrusted archive.
 *
 * The verifier compares the real file tree with the approved member directory:
 * no symlinks, no special files, no jail escape, no undeclared file, no missing
 * member, identical sizes and identical CRC32 sums. SafeFilesystem::scanTree()
 * performs the symlink/jail audit; this class owns the archive-specific
 * reconciliation.
 */
final class ArchiveTreeVerifier
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $entries validated member records
     * @throws SecurityException when the tree differs from the approved directory
     * @throws StorageException on unreadable or corrupt extracted files
     */
    public static function verify(string $root, array $entries, int $declaredTotal): void
    {
        $rootReal = realpath($root);

        if ($rootReal === false || !is_dir($rootReal)) {
            throw new StorageException('Archive extraction target disappeared during extraction.');
        }

        $rootReal = str_replace('\\', '/', $rootReal);
        $scan = SafeFilesystem::scanTree($rootReal);
        $expected = [];

        foreach ($entries as $entry) {
            if (!$entry['is_dir']) {
                $expected[(string) $entry['name']] = $entry;
            }
        }

        foreach ($expected as $name => $entry) {
            if (!array_key_exists($name, $scan['files'])) {
                throw new StorageException('Archive extraction is incomplete.');
            }

            if ($scan['files'][$name] !== (int) $entry['size']) {
                throw new SecurityException('An extracted file differs in size from the archive directory.');
            }

            $filePath = $rootReal . '/' . $name;

            if (!PathGuard::isInside($filePath, $rootReal)) {
                throw new SecurityException('An extracted file escaped the extraction jail.');
            }

            $expectedCrc = self::crcHex(is_int($entry['crc'] ?? null) ? $entry['crc'] : null);

            if ($expectedCrc !== null) {
                $actualCrc = @hash_file('crc32b', $filePath);

                if (!is_string($actualCrc) || !hash_equals($expectedCrc, $actualCrc)) {
                    throw new StorageException('An extracted file failed its CRC32 integrity check.');
                }
            }
        }

        foreach (array_keys($scan['files']) as $name) {
            if (!array_key_exists($name, $expected)) {
                throw new SecurityException('Archive extraction produced an undeclared file.');
            }
        }

        if ($scan['bytes'] !== $declaredTotal) {
            throw new SecurityException('Archive extraction total size differs from the archive directory.');
        }
    }

    public static function crcHex(?int $crc): ?string
    {
        if ($crc === null || $crc < 0) {
            return null;
        }

        return sprintf('%08x', $crc & 0xFFFFFFFF);
    }
}
