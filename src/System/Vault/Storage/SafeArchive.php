<?php
/**
 * GeDefense WPR safe archive extraction.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AppException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Fail-closed ZIP reader with hard expansion limits and post-extraction proof.
 *
 * Inspection and extraction share one policy: member names are validated by
 * ArchivePathValidator, every byte ceiling comes from ArchiveLimits, extraction
 * is streamed by ZipArchiveReader or the PclZip fallback, and the final tree is
 * re-audited by ArchiveTreeVerifier (no symlinks, no jail escape, every member
 * present, identical sizes and CRC32 sums).
 *
 * ZipArchive is preferred. WordPress PclZip is used only when the ZipArchive
 * extension is unavailable; when neither backend exists the archive layer fails
 * closed with an AvailabilityException.
 */
final class SafeArchive
{
    private readonly ArchiveLimits $limits;

    public function __construct(?ArchiveLimits $limits = null)
    {
        $this->limits = $limits ?? new ArchiveLimits();
    }

    /**
     * Validate an archive without writing anything.
     *
     * Validation is stateless per call: every invocation builds a fresh
     * validator (and therefore a fresh collision registry), so inspecting and
     * later extracting the same archive with the same instance is safe.
     *
     * @return array{entries: list<array{name: string, size: int, compressed: int, is_dir: bool}>, total_uncompressed: int}
     * @throws ValidationException on exceeded consumption ceilings
     * @throws SecurityException on hostile archive structure
     * @throws StorageException on IO or archive corruption
     * @throws \VGT\Omega\Core\Exceptions\AvailabilityException when no backend exists
     */
    public function inspect(string $zipPath): array
    {
        $validator = new ArchiveDirectoryValidator($this->limits);
        $validated = $validator->validate($this->readDirectory($zipPath));
        $entries = [];

        foreach ($validated['entries'] as $entry) {
            $entries[] = [
                'name' => $entry['name'],
                'size' => $entry['size'],
                'compressed' => $entry['compressed'],
                'is_dir' => $entry['is_dir'],
            ];
        }

        return ['entries' => $entries, 'total_uncompressed' => $validated['total']];
    }

    /**
     * Validate, extract into an empty target directory and prove the result.
     *
     * The target directory is created only after the archive passed validation
     * (it is never emptied before validation) and is removed again when any
     * later step fails.
     *
     * @throws ValidationException on exceeded consumption ceilings
     * @throws SecurityException on hostile archive structure
     * @throws StorageException on IO or archive corruption
     * @throws \VGT\Omega\Core\Exceptions\AvailabilityException when no backend exists
     */
    public function extract(string $zipPath, string $targetDir): void
    {
        $this->assertReadableArchive($zipPath);

        $targetPrepared = false;
        $root = '';
        $validated = ['entries' => [], 'total' => 0];
        $validator = new ArchiveDirectoryValidator($this->limits);

        try {
            if (class_exists(\ZipArchive::class)) {
                $zip = ZipArchiveReader::open($zipPath);

                try {
                    $validated = $validator->validate(ZipArchiveReader::readDirectory($zip));
                    $root = $this->prepareTarget($targetDir);
                    $targetPrepared = true;
                    $this->prepareDirectories($root, $validated['entries']);
                    ZipArchiveReader::extract($zip, $root, $validated['entries'], $this->limits);
                } finally {
                    $zip->close();
                }
            } else {
                $validated = $validator->validate(PclZipBridge::list($zipPath));
                $root = $this->prepareTarget($targetDir);
                $targetPrepared = true;
                $this->prepareDirectories($root, $validated['entries']);
                PclZipBridge::extract($zipPath, $root, $validated['entries']);
            }

            ArchiveTreeVerifier::verify($root, $validated['entries'], (int) $validated['total']);
        } catch (\Throwable $error) {
            if ($targetPrepared) {
                try {
                    SafeFilesystem::deleteTree($targetDir);
                } catch (\Throwable $cleanupError) {
                    $this->log('archive cleanup failed: ' . $cleanupError->getMessage());
                }
            }

            if ($error instanceof AppException) {
                throw $error;
            }

            $this->log('extraction failed: ' . $error->getMessage());

            throw new StorageException('The package could not be extracted safely.', 0, $error);
        }
    }

    private function assertReadableArchive(string $zipPath): void
    {
        if ($zipPath === '' || str_contains($zipPath, "\0")) {
            throw new ValidationException('The uploaded package path is invalid.');
        }

        clearstatcache(true, $zipPath);

        if (is_link($zipPath)) {
            throw new SecurityException('The uploaded package path is a symbolic link.');
        }

        if (!is_file($zipPath)) {
            throw new StorageException('The uploaded package is not readable.');
        }

        $size = @filesize($zipPath);

        if ($size === false || $size < 1) {
            throw new ValidationException('The uploaded package is empty.');
        }

        if ($size > $this->limits->maxArchiveBytes) {
            throw new ValidationException('The package exceeds the maximum archive size.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function readDirectory(string $zipPath): array
    {
        $this->assertReadableArchive($zipPath);

        if (!class_exists(\ZipArchive::class)) {
            return PclZipBridge::list($zipPath);
        }

        $zip = ZipArchiveReader::open($zipPath);

        try {
            return ZipArchiveReader::readDirectory($zip);
        } finally {
            $zip->close();
        }
    }

    private function prepareTarget(string $targetDir): string
    {
        if ($targetDir === '' || str_contains($targetDir, "\0")) {
            throw new \InvalidArgumentException('VGT archive target directory is invalid.');
        }

        if (is_link($targetDir)) {
            throw new SecurityException('Archive extraction target is a symbolic link.');
        }

        if (is_dir($targetDir)) {
            if (!SafeFilesystem::isEmptyDirectory($targetDir)) {
                throw new StorageException('Archive extraction target directory is not empty.');
            }

            $real = realpath($targetDir);

            if ($real === false) {
                throw new StorageException('Archive extraction target is unavailable.');
            }

            return str_replace('\\', '/', $real);
        }

        if (file_exists($targetDir)) {
            throw new StorageException('Archive extraction target exists and is not a directory.');
        }

        return SafeFilesystem::ensureDirectory($targetDir, 0700);
    }

    /** @param list<array<string, mixed>> $entries */
    private function prepareDirectories(string $root, array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry['is_dir']) {
                SafeFilesystem::ensureDirectory(PathGuard::joinInside($root, (string) $entry['name']), 0700);
                continue;
            }

            $segments = explode('/', (string) $entry['name']);
            array_pop($segments);
            $prefix = '';

            foreach ($segments as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                SafeFilesystem::ensureDirectory(PathGuard::joinInside($root, $prefix), 0700);
            }
        }
    }

    private function log(string $message): void
    {
        error_log('[VGT][ARCHIVE] ' . ExceptionReporter::sanitizeLogMessage($message));
    }
}
