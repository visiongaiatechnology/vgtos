<?php
/**
 * GeDefense WPR availability exception.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Exceptions;

/**
 * A required capability is missing or a resource budget would be exceeded
 * (memory pre-flight, missing OpenSSL/Sodium, unwritable vault, rate limit).
 * The system fails secure but recoverable instead of crashing WordPress.
 */
final class AvailabilityException extends AppException
{
}
