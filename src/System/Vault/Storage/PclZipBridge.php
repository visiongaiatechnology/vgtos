<?php
/**
 * VGT Omega PclZip bridge.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;

/**
 * Isolates the optional WordPress PclZip backend from the extraction policy.
 *
 * PclZip is a pure-PHP reader that ships with WordPress. It is only used when
 * the ZipArchive extension is absent. The bridge performs no policy decision:
 * it returns the raw member directory to SafeArchive for uniform validation
 * and extracts exactly the members SafeArchive approved.
 */
final class PclZipBridge
{
    /** @return list<array{name: string, stored: string, size: int, compressed: int, is_dir: bool, index: int, crc: int|null, external: int|null}> */
    public static function list(string $zipPath): array
    {
        if (!self::available()) {
            throw new AvailabilityException('No ZIP extraction backend is available on this host.');
        }

        $archive = new \PclZip($zipPath);
        $listing = $archive->listContent();

        if (!is_array($listing)) {
            self::log($archive, 'archive listing failed');

            throw new StorageException('The uploaded package is not a readable ZIP archive.');
        }

        $entries = [];
        $index = 0;

        foreach ($listing as $item) {
            if (!is_array($item)) {
                throw new StorageException('The package member directory is malformed.');
            }

            $filename = $item['filename'] ?? null;
            $stored = $item['stored_filename'] ?? null;

            if (!is_string($filename) || $filename === '') {
                throw new SecurityException('Archive entry name is malformed.');
            }

            if (is_string($stored) && $stored !== '' && $stored !== $filename) {
                throw new SecurityException('Archive entry headers disagree about the member name.');
            }

            $size = self::toInt($item['size'] ?? null);
            $compressed = self::toInt($item['compressed_size'] ?? null);

            if ($size === null || $compressed === null) {
                throw new StorageException('The package member directory is malformed.');
            }

            $isDirectory = str_ends_with($filename, '/');

            if (array_key_exists('folder', $item) && (bool) $item['folder'] !== $isDirectory) {
                throw new SecurityException('Archive entry type conflicts with its path.');
            }

            $crc = array_key_exists('crc', $item) ? self::toInt($item['crc']) : null;
            $external = null;

            foreach (['external', 'external_attributes', 'attributes'] as $key) {
                if (!array_key_exists($key, $item)) {
                    continue;
                }

                $candidate = self::toInt($item[$key]);

                if ($candidate !== null) {
                    $external = $candidate;
                    break;
                }
            }

            $entries[] = [
                'name' => $filename,
                'stored' => is_string($stored) && $stored !== '' ? $stored : $filename,
                'size' => $size,
                'compressed' => $compressed,
                'is_dir' => $isDirectory,
                'index' => $index,
                'crc' => $crc,
                'external' => $external,
            ];
            $index++;
        }

        return $entries;
    }

    /**
     * Extract exactly the validated regular members into the prepared target.
     *
     * @param list<array{name: string, stored: string, size: int, compressed: int, is_dir: bool, index: int, crc: int|null, external: int|null}> $entries
     * @throws AvailabilityException when PclZip is missing
     * @throws StorageException when an extraction fails
     */
    public static function extract(string $zipPath, string $rootDir, array $entries): void
    {
        if (!self::available()) {
            throw new AvailabilityException('No ZIP extraction backend is available on this host.');
        }

        $root = realpath($rootDir);

        if ($root === false || !is_dir($root)) {
            throw new StorageException('Archive extraction target is unavailable.');
        }

        $byIndex = defined('PCLZIP_OPT_BY_INDEX');
        $byName = defined('PCLZIP_OPT_BY_NAME');

        if (!$byIndex && !$byName) {
            throw new AvailabilityException('The host PclZip build does not expose a member selector.');
        }

        $archive = new \PclZip($zipPath);
        $base = [\PCLZIP_OPT_PATH, str_replace('\\', '/', $root)];

        if (defined('PCLZIP_OPT_SET_CHMOD')) {
            $base[] = \PCLZIP_OPT_SET_CHMOD;
            $base[] = 0600;
        }

        foreach ($entries as $entry) {
            if ($entry['is_dir']) {
                continue;
            }

            $arguments = $base;

            if ($byIndex) {
                $arguments[] = \PCLZIP_OPT_BY_INDEX;
                $arguments[] = [$entry['index']];
            } else {
                $arguments[] = \PCLZIP_OPT_BY_NAME;
                $arguments[] = $entry['stored'];
            }

            $result = $archive->extract(...$arguments);

            if (!is_array($result) || $result === []) {
                self::log($archive, 'per-entry extraction returned no result');

                throw new StorageException('The package entry could not be extracted.');
            }

            foreach ($result as $extracted) {
                if (!is_array($extracted) || ($extracted['status'] ?? '') !== 'ok') {
                    self::log($archive, 'per-entry extraction was not confirmed');

                    throw new StorageException('The package entry could not be extracted cleanly.');
                }
            }
        }
    }

    /** True when PclZip is already loaded or can be loaded from WordPress. */
    public static function available(): bool
    {
        if (class_exists('PclZip', false)) {
            return true;
        }

        $file = self::classFile();

        if ($file === '') {
            return false;
        }

        try {
            require_once $file;
        } catch (\Throwable $error) {
            self::logMessage('PclZip include failed: ' . $error->getMessage());

            return false;
        }

        return class_exists('PclZip', false);
    }

    private static function classFile(): string
    {
        if (!defined('ABSPATH')) {
            return '';
        }

        $abspath = rtrim(str_replace('\\', '/', (string) constant('ABSPATH')), '/');
        $file = $abspath . '/wp-admin/includes/class-pclzip.php';

        return is_file($file) ? $file : '';
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value;
        }

        return null;
    }

    private static function log(\PclZip $archive, string $context): void
    {
        try {
            $detail = $archive->errorInfo(true);
        } catch (\Throwable) {
            $detail = '';
        }

        self::logMessage($context . ': ' . (is_string($detail) ? $detail : ''));
    }

    private static function logMessage(string $message): void
    {
        error_log('[VGT][ARCHIVE][PCLZIP] ' . ExceptionReporter::sanitizeLogMessage($message));
    }
}
