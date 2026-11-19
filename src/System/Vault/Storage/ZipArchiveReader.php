<?php
/**
 * GeDefense WPR ZipArchive reader.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Thin, policy-free access layer around the ZipArchive extension.
 *
 * The reader only reports the raw member directory and streams already
 * validated members to disk. Every security decision is owned by
 * ArchiveDirectoryValidator and SafeArchive; the extraction loop itself never
 * follows links, creates files exclusively and re-checks each byte budget while
 * streaming so a lying local/central header cannot cause unbounded allocation.
 */
final class ZipArchiveReader
{
    private const CHUNK = 1048576;

    private function __construct()
    {
    }

    public static function open(string $zipPath): \ZipArchive
    {
        $zip = new \ZipArchive();
        $flags = defined('ZipArchive::RDONLY') ? \ZipArchive::RDONLY : 0;
        $opened = $zip->open($zipPath, $flags);

        if ($opened !== true) {
            self::log('ZipArchive open failed with code ' . (is_int($opened) ? (string) $opened : 'unknown'));

            throw new StorageException('The uploaded package is not a readable ZIP archive.');
        }

        return $zip;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws ValidationException when the archive is empty
     * @throws SecurityException on encrypted members or unusable names
     * @throws StorageException on corrupt directory records
     */
    public static function readDirectory(\ZipArchive $zip): array
    {
        $count = $zip->numFiles;

        if (!is_int($count) || $count < 1) {
            throw new ValidationException('The package does not contain any files.');
        }

        $entries = [];

        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);

            if (!is_array($stat)) {
                throw new StorageException('The package member directory could not be read.');
            }

            $name = $stat['name'] ?? null;

            if (!is_string($name) || $name === '') {
                throw new SecurityException('Archive entry name is malformed.');
            }

            $size = self::intValue($stat['size'] ?? null);
            $compressed = self::intValue($stat['comp_size'] ?? null);

            if ($size === null || $compressed === null) {
                throw new StorageException('The package member directory is malformed.');
            }

            $encryption = self::intValue($stat['encryption_method'] ?? 0);

            if ($encryption !== null && $encryption !== 0) {
                throw new SecurityException('Encrypted package entries are not supported.');
            }

            $external = self::intValue($stat['external_attributes'] ?? null);
            $opsys = 0;
            $attributes = 0;

            if (method_exists($zip, 'getExternalAttributesIndex')
                && $zip->getExternalAttributesIndex($index, $opsys, $attributes)
            ) {
                $external = (int) $attributes;
            }

            $entries[] = [
                'name' => $name,
                'stored' => $name,
                'size' => $size,
                'compressed' => $compressed,
                'is_dir' => str_ends_with($name, '/'),
                'index' => $index,
                'crc' => self::intValue($stat['crc'] ?? null),
                'external' => $external,
                'opsys' => $opsys,
            ];
        }

        return $entries;
    }

    /**
     * Stream validated regular members into the prepared target directory.
     *
     * @param list<array<string, mixed>> $entries
     * @throws SecurityException when a member expands past its declared budget
     * @throws StorageException on unreadable or unwritable members
     */
    public static function extract(\ZipArchive $zip, string $root, array $entries, ArchiveLimits $limits): void
    {
        foreach ($entries as $entry) {
            if ($entry['is_dir']) {
                continue;
            }

            $target = PathGuard::joinInside($root, (string) $entry['name']);
            self::writeEntry($zip, $entry, $target, $limits);
        }
    }

    /** @param array<string, mixed> $entry */
    private static function writeEntry(\ZipArchive $zip, array $entry, string $targetPath, ArchiveLimits $limits): void
    {
        $stream = $zip->getStream((string) $entry['raw']);

        if (!is_resource($stream)) {
            throw new StorageException('A package entry uses an unsupported compression method.');
        }

        $handle = @fopen($targetPath, 'xb');

        if ($handle === false) {
            @fclose($stream);

            throw new StorageException('A package entry could not be written.');
        }

        $hash = hash_init('crc32b');
        $bytes = 0;
        $declared = (int) $entry['size'];

        try {
            while (!feof($stream)) {
                $chunk = @fread($stream, self::CHUNK);

                if ($chunk === false) {
                    throw new StorageException('A package entry could not be read.');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                if ($bytes > $declared || $bytes > $limits->maxFileBytes) {
                    throw new SecurityException('A package entry expands beyond its declared size.');
                }

                $written = 0;
                $length = strlen($chunk);

                while ($written < $length) {
                    $result = @fwrite($handle, substr($chunk, $written));

                    if ($result === false || $result === 0) {
                        throw new StorageException('A package entry could not be written completely.');
                    }

                    $written += $result;
                }

                hash_update($hash, $chunk);
            }

            if (!@fflush($handle)) {
                throw new StorageException('A package entry could not be flushed.');
            }
        } finally {
            @fclose($handle);
            @fclose($stream);
        }

        @chmod($targetPath, 0600);

        if ($bytes !== $declared) {
            throw new StorageException('A package entry is shorter than its directory header declares.');
        }

        $expectedCrc = ArchiveTreeVerifier::crcHex(is_int($entry['crc'] ?? null) ? $entry['crc'] : null);
        $actualCrc = hash_final($hash);

        if ($expectedCrc !== null && !hash_equals($expectedCrc, $actualCrc)) {
            throw new StorageException('A package entry failed its CRC32 integrity check.');
        }
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

    private static function log(string $message): void
    {
        error_log('[VGT][ARCHIVE] ' . ExceptionReporter::sanitizeLogMessage($message));
    }
}
