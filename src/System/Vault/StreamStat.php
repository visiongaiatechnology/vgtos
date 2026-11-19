<?php
/**
 * VGT Omega virtual file stat builder.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

/**
 * Builds deterministic stat arrays for the vgt:// virtual file system.
 *
 * PHP caching layers (realpath/stat cache, WordPress file checks) expect stable
 * stat data. Sizes come from the authenticated manifest, and mtime/atime/ctime
 * are always the stable manifest modification time of the mounted artifact -
 * never time(). Inodes are derived from the virtual path so they stay stable
 * across requests without touching the file system.
 */
final class StreamStat
{
    public const MODE_FILE = 0100644;

    public const MODE_DIRECTORY = 0040755;

    /** @return array<string, int> */
    public static function file(int $mtime, int $size, string $identity): array
    {
        return self::build($mtime, $size, self::MODE_FILE, 'f|' . $identity);
    }

    /** @return array<string, int> */
    public static function directory(int $mtime, string $identity): array
    {
        return self::build($mtime, 0, self::MODE_DIRECTORY, 'd|' . $identity);
    }

    /** @return array<string, int> */
    private static function build(int $mtime, int $size, int $mode, string $identity): array
    {
        return [
            'dev' => 0, 'ino' => (int) (hexdec(substr(hash('sha256', $identity), 0, 8)) & 0x7FFFFFFF),
            'mode' => $mode, 'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => $size, 'atime' => $mtime, 'mtime' => $mtime, 'ctime' => $mtime,
            'blksize' => 4096, 'blocks' => (int) ceil($size / 512),
        ];
    }
}
