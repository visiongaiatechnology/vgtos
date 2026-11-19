<?php
/**
 * VGT Omega security exception.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Core\Exceptions;

/**
 * A security invariant was violated (authentication, authorization, path jail,
 * archive polyglot, signature mismatch, AAD mismatch, header tampering, ...).
 *
 * The message is for the internal error log only. It must never be rendered
 * into an HTTP response or admin notice.
 */
final class SecurityException extends AppException
{
}
