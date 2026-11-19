<?php
/**
 * VGT Omega ciphertext cache invalidation.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Invalidates the optional APCu cache that may hold authenticated ciphertext.
 *
 * Only ciphertext is ever cached (the full key is derived from the artifact id,
 * which is deleted with the artifact), so a bulk prefix purge is sufficient and
 * safe. A global APCu flush helper is deliberately not used: it would evict
 * unrelated host entries and is an unnecessary global side effect.
 */
final class CiphertextCache
{
    private function __construct()
    {
    }

    public static function invalidate(): void
    {
        if (!function_exists('apcu_delete') || !class_exists('APCuIterator')) {
            return;
        }

        if (function_exists('apcu_enabled') && !apcu_enabled()) {
            return;
        }

        try {
            apcu_delete(new \APCuIterator('/^' . preg_quote(VaultFormat::APCU_BLOB_PREFIX, '/') . '/'));
        } catch (\Throwable $error) {
            error_log(
                '[VGT][STORAGE] APCu ciphertext cache invalidation failed: '
                . ExceptionReporter::sanitizeLogMessage($error->getMessage())
            );
        }
    }
}
