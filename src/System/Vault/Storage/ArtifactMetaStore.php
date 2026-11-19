<?php
/**
 * VGT Omega artifact metadata store.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Non-secret artifact presentation metadata persisted through the host bridge.
 *
 * The metadata map is deliberately kept outside the artifact directory so a
 * storage corruption can never make a mounted artifact unreadable, and it only
 * ever contains name, update timestamp, entry path, file count, a signature
 * flag and the format identifier. All values are re-validated on read because
 * the option table is not a trust boundary.
 */
final class ArtifactMetaStore
{
    private const OPTION = 'vgt_vault_meta';

    private const MAX_FILES = 1000;

    private const MAX_NAME_LENGTH = 120;

    /** @var array<string, array{name: string, updated: int, entry: string, files: int, signed: bool, format: string}>|null */
    private ?array $cache = null;

    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** @return array{name: string, updated: int, entry: string, files: int, signed: bool, format: string} */
    public function get(string $artifactId): array
    {
        PathGuard::assertArtifactId($artifactId);

        return $this->map()[$artifactId] ?? self::defaults($artifactId);
    }

    /** @param array<string, mixed> $meta */
    public function set(string $artifactId, array $meta): void
    {
        PathGuard::assertArtifactId($artifactId);

        $map = $this->map();
        $map[$artifactId] = self::normalize($artifactId, $meta);
        $this->persist($map, $artifactId);
        $this->cache = $map;
    }

    public function remove(string $artifactId): void
    {
        PathGuard::assertArtifactId($artifactId);

        $map = $this->map();

        if (!array_key_exists($artifactId, $map)) {
            return;
        }

        unset($map[$artifactId]);

        try {
            $this->persist($map, $artifactId);
            $this->cache = $map;
        } catch (\Throwable $error) {
            // The artifact itself is already gone; stale presentation data must
            // never abort the security-critical deletion path.
            error_log('[VGT][STORAGE] Artifact metadata removal failed: ' . self::logSafe($error->getMessage()));
        }
    }

    /** @return array<string, array{name: string, updated: int, entry: string, files: int, signed: bool, format: string}> */
    private function map(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $raw = $this->bridge->getState(self::OPTION, []);
        $map = [];

        if (is_array($raw)) {
            foreach ($raw as $artifactId => $meta) {
                if (!is_string($artifactId) || !is_array($meta) || !PathGuard::isValidArtifactId($artifactId)) {
                    continue;
                }

                $map[$artifactId] = self::normalize($artifactId, $meta);
            }
        }

        return $this->cache = $map;
    }

    /**
     * @param array<string, array{name: string, updated: int, entry: string, files: int, signed: bool, format: string}> $map
     * @throws StorageException
     */
    private function persist(array $map, string $artifactId): void
    {
        if ($this->bridge->setState(self::OPTION, $map, false)) {
            return;
        }

        // Some hosts report false for an unchanged option value; only a read
        // back that still misses the artifact proves a persistence failure.
        $verify = $this->bridge->getState(self::OPTION, []);

        if (!is_array($verify) || !array_key_exists($artifactId, $verify)) {
            throw new StorageException('Vault artifact metadata could not be persisted.');
        }
    }

    /** @return array{name: string, updated: int, entry: string, files: int, signed: bool, format: string} */
    private static function defaults(string $artifactId): array
    {
        return [
            'name' => $artifactId,
            'updated' => 0,
            'entry' => '',
            'files' => 0,
            'signed' => false,
            'format' => VaultFormat::SPEC_VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{name: string, updated: int, entry: string, files: int, signed: bool, format: string}
     */
    private static function normalize(string $artifactId, array $meta): array
    {
        $name = $meta['name'] ?? '';
        $name = is_string($name) ? self::sanitizeName($name) : '';

        $updated = $meta['updated'] ?? 0;
        $updated = is_int($updated) && $updated >= 0 ? $updated : 0;

        $entry = $meta['entry'] ?? '';
        $entry = is_string($entry) && $entry !== '' && PathGuard::isSafeRelative($entry) ? $entry : '';

        $files = $meta['files'] ?? 0;
        $files = is_int($files) && $files >= 0 && $files <= self::MAX_FILES ? $files : 0;

        $signed = $meta['signed'] ?? false;

        $format = $meta['format'] ?? VaultFormat::SPEC_VERSION;
        $format = is_string($format)
            && in_array($format, array_merge([VaultFormat::SPEC_VERSION], VaultFormat::LEGACY_SPEC_VERSIONS), true)
            ? $format
            : VaultFormat::SPEC_VERSION;

        return [
            'name' => $name !== '' ? $name : $artifactId,
            'updated' => $updated,
            'entry' => $entry,
            'files' => $files,
            'signed' => $signed === true,
            'format' => $format,
        ];
    }

    private static function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
        $clean = trim($clean);

        return function_exists('mb_substr')
            ? trim(mb_substr($clean, 0, self::MAX_NAME_LENGTH))
            : trim(substr($clean, 0, self::MAX_NAME_LENGTH));
    }

    private static function logSafe(string $message): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '', 0, 300);
    }
}
