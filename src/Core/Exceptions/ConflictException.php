<?php
/**
 * GeDefense WPR conflict exception.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Exceptions;

/**
 * The requested operation conflicts with the current persisted state
 * (duplicate artifact id, rotation target missing, concurrent install).
 */
final class ConflictException extends AppException
{
}
