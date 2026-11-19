<?php
/**
 * VGT Omega exception reporter.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Error;

use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\ConflictException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;

/**
 * Central disclosure policy for every exception that reaches an HTTP boundary.
 *
 * Security, storage and availability details are logged with a correlation
 * reference and replaced by a generic client message. Only ValidationException
 * messages are rendered verbatim because their constructors are only ever
 * called with curated, user-safe sentences.
 */
final class ExceptionReporter
{
    /** Client-safe fallback messages keyed by exception category. */
    private const PUBLIC_MESSAGES = [
        SecurityException::class => 'Request rejected for security reasons.',
        StorageException::class => 'A server error occurred while writing or reading the vault.',
        AvailabilityException::class => 'The requested capability is currently unavailable.',
        ConflictException::class => 'The requested operation conflicts with the current vault state.',
        ValidationException::class => '',
    ];

    /**
     * Log the full internal detail and return an opaque correlation reference.
     * The reference is safe to display in the admin UI.
     */
    public static function report(\Throwable $error, string $scope = 'vgt'): string
    {
        $reference = 'VGT-ERR-' . strtoupper(bin2hex(random_bytes(4)));

        $internal = sprintf(
            '[VGT][%s][%s] %s: %s in %s:%d',
            self::sanitizeToken($scope),
            $reference,
            $error::class,
            self::sanitizeLogMessage($error->getMessage()),
            self::sanitizeToken(basename($error->getFile())),
            $error->getLine()
        );

        // error_log is the only internal sink. Never echo this message.
        error_log($internal);

        $previous = $error->getPrevious();
        if ($previous instanceof \Throwable) {
            error_log(sprintf(
                '[VGT][%s][%s] caused by %s: %s',
                self::sanitizeToken($scope),
                $reference,
                $previous::class,
                self::sanitizeLogMessage($previous->getMessage())
            ));
        }

        return $reference;
    }

    /**
     * Map an exception to a client-safe message plus opaque reference.
     *
     * @return array{message: string, reference: string}
     */
    public static function forClient(\Throwable $error, string $scope = 'vgt'): array
    {
        $reference = self::report($error, $scope);

        if ($error instanceof ValidationException) {
            return ['message' => self::sanitizeNotice($error->getMessage()), 'reference' => $reference];
        }

        foreach (self::PUBLIC_MESSAGES as $class => $message) {
            if ($error instanceof $class && $message !== '') {
                return ['message' => $message, 'reference' => $reference];
            }
        }

        return ['message' => 'Critical system fault. The operation was aborted.', 'reference' => $reference];
    }

    /** Strip newlines and control characters from log lines (log injection). */
    public static function sanitizeLogMessage(string $message): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $message) ?? '';
        $clean = str_replace(["\r", "\n", "\t"], ' ', $clean);

        return mb_substr(trim($clean), 0, 500);
    }

    private static function sanitizeToken(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.\-]/', '', $value) ?? 'vgt';
    }

    /** Keep the admin notice to a single printable line. */
    private static function sanitizeNotice(string $message): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';

        return mb_substr(trim($clean), 0, 300);
    }
}
