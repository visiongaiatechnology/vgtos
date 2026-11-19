<?php
/**
 * VGT Omega base exception.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Exceptions;

/**
 * Root of the VGT exception hierarchy.
 *
 * Every failure that crosses a trust boundary is represented by a subtype of
 * this class so that the HTTP adapter can apply an explicit disclosure policy:
 *
 * - ValidationException  -> message is safe for the client
 * - ConflictException    -> generic client message
 * - SecurityException    -> opaque client message, full detail to error_log
 * - StorageException     -> opaque client message, full detail to error_log
 * - AvailabilityException-> opaque client message, full detail to error_log
 */
class AppException extends \Exception
{
}
