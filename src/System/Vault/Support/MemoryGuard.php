<?php
/**
 * GeDefense WPR memory pre-flight guard.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Support;

use VGT\Omega\Core\Exceptions\AvailabilityException;

/**
 * Prevents white-screen OOM fatals on 64/128 MB shared-hosting workers.
 *
 * Every operation that decrypts, inflates or re-encrypts a payload asks the
 * guard for headroom first. When the budget is insufficient the operation is
 * aborted with a typed AvailabilityException instead of crashing WordPress.
 */
final class MemoryGuard
{
    private const MIN_RESERVE_BYTES = 1048576;

    private readonly int $reserveBytes;

    public function __construct(int $reserveBytes = 8388608)
    {
        if ($reserveBytes < self::MIN_RESERVE_BYTES) {
            throw new \InvalidArgumentException('VGT MemoryGuard: reserve must be at least 1 MiB.');
        }

        $this->reserveBytes = $reserveBytes;
    }

    /** Effective memory limit in bytes (PHP_INT_MAX for -1 / unlimited). */
    public function limitBytes(): int
    {
        return self::parseIniBytes((string) ini_get('memory_limit'));
    }

    public function usageBytes(): int
    {
        return memory_get_usage(true);
    }

    public function availableBytes(): int
    {
        $limit = $this->limitBytes();

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->usageBytes());
    }

    /**
     * @throws AvailabilityException when allocating $bytes would violate the reserve
     */
    public function assertCanAllocate(int $bytes, string $operation = 'operation'): void
    {
        if ($bytes < 0) {
            throw new AvailabilityException('Memory pre-flight received a negative budget.');
        }

        $required = $bytes + $this->reserveBytes;

        if ($this->limitBytes() === PHP_INT_MAX) {
            return;
        }

        if ($this->availableBytes() < $required) {
            throw new AvailabilityException(sprintf(
                'Insufficient memory headroom for %s (need %s, available %s).',
                preg_replace('/[^A-Za-z0-9 _\-.]/', '', $operation) ?? 'operation',
                self::formatBytes($required),
                self::formatBytes($this->availableBytes())
            ));
        }
    }

    public static function parseIniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes === PHP_INT_MAX) {
            return 'unlimited';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MiB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }

        return $bytes . ' B';
    }
}
