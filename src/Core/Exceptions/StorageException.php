<?php
/**
 * VGT Omega storage exception.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Exceptions;

/**
 * A file system, archive or persistence operation failed. Internal only:
 * the client sees a generic "server error" message plus an opaque reference.
 */
final class StorageException extends AppException
{
}
