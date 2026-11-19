<?php
/**
 * GeDefense WPR archive consumption limits.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\System\Vault\VaultFormat;

/**
 * Immutable hard ceilings applied to every untrusted ZIP archive.
 *
 * The defaults mirror the artifact format budgets: one uploaded package is
 * capped at MAX_ARCHIVE_BYTES, one expanded payload at MAX_PLAINTEXT_FILE_BYTES
 * and the complete expansion at MAX_TOTAL_PLAINTEXT_BYTES. The additional
 * compression-ratio, entry-count, depth and path-length caps close the classic
 * ZIP-bomb and path-abuse vectors before a single byte is extracted.
 *
 * Every limit is a public readonly value so a test harness or a hardened host
 * can install a stricter policy without touching the extraction code.
 */
final class ArchiveLimits
{
    public function __construct(
        public readonly int $maxArchiveBytes = VaultFormat::MAX_ARCHIVE_BYTES,
        public readonly int $maxEntries = 1000,
        public readonly int $maxTotalUncompressed = VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES,
        public readonly int $maxFileBytes = VaultFormat::MAX_PLAINTEXT_FILE_BYTES,
        public readonly int $maxCompressionRatio = 50,
        public readonly int $maxDepth = 16,
        public readonly int $maxPathLength = 255
    ) {
        if ($this->maxArchiveBytes < 1
            || $this->maxEntries < 1
            || $this->maxTotalUncompressed < 1
            || $this->maxFileBytes < 1
            || $this->maxCompressionRatio < 1
            || $this->maxDepth < 1
            || $this->maxPathLength < 1
        ) {
            throw new \InvalidArgumentException('VGT archive limits must be positive integers.');
        }

        if ($this->maxArchiveBytes > VaultFormat::MAX_ARCHIVE_BYTES) {
            throw new \InvalidArgumentException('VGT archive limit exceeds the format ceiling.');
        }

        if ($this->maxTotalUncompressed > VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES) {
            throw new \InvalidArgumentException('VGT expansion limit exceeds the format ceiling.');
        }

        if ($this->maxFileBytes > $this->maxTotalUncompressed) {
            throw new \InvalidArgumentException('VGT file limit cannot exceed the total expansion limit.');
        }

        if ($this->maxPathLength > 4096 || $this->maxDepth > 64) {
            throw new \InvalidArgumentException('VGT archive path limits are irreconcilably large.');
        }
    }
}
