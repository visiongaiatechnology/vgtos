<?php
/**
 * VGT Omega validation exception.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Exceptions;

/**
 * Input failed validation. The message is user-facing and MUST be a safe,
 * human-readable sentence that never contains file system paths, secrets or
 * internal control-flow details.
 */
final class ValidationException extends AppException
{
}
