<?php
/**
 * GeDefense WPR console — immutable view data.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard;

use VGT\Omega\Admin\ArtifactController;
use VGT\Omega\Bootstrap\Plugin;
use VGT\Omega\System\Vault\VaultManager;

/**
 * Read-only snapshot of everything the console needs for one request.
 *
 * `collect()` is the production path and degrades instead of fataling: a vault
 * that never booted, a missing admin controller or a diagnostics collector
 * that throws all produce a renderable state. `fromFixture()` is the only path
 * the visual preview harness uses, so design QA never needs WordPress or a
 * booted vault.
 *
 * No secret ever enters this object: the diagnostics payload contains no key
 * material by contract, and the artifact rows carry only display metadata.
 */
final class ConsoleData
{
    public const STATE_READY = 'ready';

    public const STATE_OFFLINE = 'offline';

    public const PRODUCT_NAME = 'GeDefense WPR';

    public const PRODUCT_SERIES = 'GeDefense';

    public const LOGO_RELATIVE_PATH = 'assets/ge-defense.png';

    public const SAFE_MODE_RECOVERY_SNIPPET = "define('VGT_OMEGA_SAFE_MODE', false);";

    public const ARTIFACT_ID_PATTERN = '/^vgt_[a-f0-9]{13,32}$/';

    private const TIMESTAMP_FORMAT = 'Y-m-d H:i';

    /**
     * @param list<array<string, mixed>>                 $artifacts
     * @param array{mounted: list<string>, locked: list<string>, failed: array<string, string>} $mountStats
     * @param array<string, array<string, mixed>>        $diagnostics
     * @param array<string, mixed>                       $envFacts
     * @param array{type: string, message: string, reference: string}|null $notice
     */
    private function __construct(
        public readonly string $state,
        public readonly string $version,
        public readonly string $codeName,
        public readonly string $phpVersion,
        public readonly string $minPhpVersion,
        public readonly bool $safeMode,
        public readonly bool $layerZero,
        public readonly bool $booted,
        public readonly string $bootReference,
        public readonly array $artifacts,
        public readonly array $mountStats,
        public readonly array $diagnostics,
        public readonly array $envFacts,
        public readonly ?array $notice,
        public readonly string $generatedAt
    ) {
    }

    /** Production snapshot straight from the vault facade. Never throws. */
    public static function collect(): self
    {
        [$version, $codeName, $minPhp] = self::identity();

        $booted = false;
        $safeMode = false;
        $layerZero = false;
        $bootReference = '';
        $diagnostics = [];
        $mountStats = ['mounted' => [], 'locked' => [], 'failed' => []];
        $artifacts = [];
        $diagnosticsError = false;

        if (class_exists(VaultManager::class)) {
            try {
                $booted = VaultManager::isBooted();
                $safeMode = VaultManager::isSafeMode();
                $layerZero = VaultManager::isLayerZero();
                $bootReference = self::normalizeReference(VaultManager::bootError());
            } catch (\Throwable) {
                $booted = false;
            }
        }

        if ($booted) {
            try {
                $diagnostics = VaultManager::diagnostics();
                $mountStats = ArtifactRow::normalizeMountStats($diagnostics);
            } catch (\Throwable) {
                $diagnosticsError = true;
            }

            try {
                foreach (VaultManager::artifacts() as $artifactId) {
                    if (!is_string($artifactId) || !self::isArtifactId($artifactId)) {
                        continue;
                    }

                    $artifacts[] = ArtifactRow::build(
                        $artifactId,
                        self::safeMeta($artifactId),
                        self::safeUnlocked($artifactId),
                        $mountStats,
                        $safeMode
                    );
                }
            } catch (\Throwable) {
                $diagnosticsError = true;
            }
        }

        return new self(
            $booted ? self::STATE_READY : self::STATE_OFFLINE,
            $version,
            $codeName,
            PHP_VERSION,
            $minPhp,
            $safeMode,
            $layerZero,
            $booted,
            $bootReference,
            $artifacts,
            $mountStats,
            $diagnostics,
            ['opcache' => self::detectOpcache(), 'diagnostics_error' => $diagnosticsError],
            self::pullNotice(),
            self::stamp()
        );
    }

    /**
     * Deterministic design-time snapshot. No WordPress, no vault, no I/O.
     *
     * @param array<string, mixed> $overrides recursive overrides by top-level key
     */
    public static function fromFixture(array $overrides = []): self
    {
        $base = ConsoleFixture::defaults();

        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $base)) {
                continue;
            }

            $base[$key] = self::mergeValue($base[$key], $value);
        }

        $rawArtifacts = is_array($base['artifacts']) ? $base['artifacts'] : [];
        $mountStats = ArtifactRow::normalizeMountStats(['runtime' => $base['mountStats']]);
        $safeMode = (bool) $base['safeMode'];
        $rows = [];

        foreach ($rawArtifacts as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $artifactId = is_string($raw['id'] ?? null) ? (string) $raw['id'] : '';
            if (!self::isArtifactId($artifactId)) {
                continue;
            }

            $rows[] = ArtifactRow::build($artifactId, $raw, (bool) ($raw['unlocked'] ?? false), $mountStats, $safeMode);
        }

        return new self(
            (string) $base['state'],
            (string) $base['version'],
            (string) $base['codeName'],
            (string) $base['phpVersion'],
            (string) $base['minPhpVersion'],
            $safeMode,
            (bool) $base['layerZero'],
            (bool) $base['booted'],
            (string) $base['bootReference'],
            $rows,
            $mountStats,
            is_array($base['diagnostics']) ? $base['diagnostics'] : [],
            is_array($base['envFacts']) ? $base['envFacts'] : [],
            is_array($base['notice'] ?? null) ? $base['notice'] : null,
            (string) $base['generatedAt']
        );
    }

    public function isOffline(): bool
    {
        return $this->state === self::STATE_OFFLINE;
    }

    public function hasDiagnostics(): bool
    {
        return $this->diagnostics !== [];
    }

    public function artifactCount(): int
    {
        return count($this->artifacts);
    }

    public function isDegraded(): bool
    {
        return (bool) ($this->envFacts['diagnostics_error'] ?? false);
    }

    /** @return array{mounted: int, locked: int, failed: int, total: int} */
    public function mountSummary(): array
    {
        $mounted = count($this->mountStats['mounted']);
        $locked = count($this->mountStats['locked']);
        $failed = count($this->mountStats['failed']);
        $total = max($mounted + $locked + $failed, $this->artifactCount());

        return ['mounted' => $mounted, 'locked' => $locked, 'failed' => $failed, 'total' => $total];
    }

    /** One diagnostics value as raw scalar, or the default. */
    public function diag(string $group, string $key, mixed $default = null): mixed
    {
        $bucket = $this->diagnostics[$group] ?? null;

        if (!is_array($bucket) || !array_key_exists($key, $bucket)) {
            return $default;
        }

        return $bucket[$key];
    }

    public function diagFlag(string $group, string $key): bool
    {
        return $this->diag($group, $key, false) === true;
    }

    public function diagText(string $group, string $key, string $default = ''): string
    {
        $value = $this->diag($group, $key, $default);

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : $default;
    }

    public function diagInt(string $group, string $key, int $default = 0): int
    {
        $value = $this->diag($group, $key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public function ramHeadroomPercent(): int
    {
        $limit = $this->diagInt('memory', 'limit');
        $available = $this->diagInt('memory', 'available');

        if ($limit <= 0 || $available < 0) {
            return 0;
        }

        return (int) max(0, min(100, (int) round(($available / $limit) * 100)));
    }

    /** Referenced kernel state for the state bar. */
    public function kernelLabel(): string
    {
        return $this->layerZero ? 'Layer-0 kernel' : 'Plugin mode';
    }

    /** @return array{0: string, 1: string, 2: string} version, code name, minimum PHP */
    private static function identity(): array
    {
        $version = '4.2.3';
        $codeName = 'WORDPRESS RUNTIME';
        $minPhp = '8.1.0';

        if (class_exists(Plugin::class)) {
            try {
                $identity = Plugin::identity();
                $version = is_string($identity['version'] ?? null) ? $identity['version'] : $version;
                $codeName = is_string($identity['code_name'] ?? null) ? $identity['code_name'] : $codeName;
                $minPhp = Plugin::MIN_PHP_VERSION;
            } catch (\Throwable) {
                // Identity is cosmetic; keep the documented defaults.
            }
        }

        return [$version, $codeName, $minPhp];
    }

    /**
     * Public URL of the official GeDefense logo shipped with the plugin.
     *
     * Returns '' outside a WordPress request without a host URL; callers render
     * an inline fallback icon in that case (never a broken image request).
     */
    public static function logoUrl(): string
    {
        if (!function_exists('plugins_url')) {
            return '';
        }

        $pluginFile = defined('VGT_OMEGA_FILE') ? (string) constant('VGT_OMEGA_FILE') : '';
        $url = plugins_url(self::LOGO_RELATIVE_PATH, $pluginFile);

        return is_string($url) && $url !== '' ? $url : '';
    }

    /** @return array{type: string, message: string, reference: string}|null */
    private static function pullNotice(): ?array
    {
        if (!class_exists(ArtifactController::class) || !method_exists(ArtifactController::class, 'pullNotice')) {
            return null;
        }

        try {
            $notice = ArtifactController::pullNotice();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($notice)) {
            return null;
        }

        $message = is_string($notice['message'] ?? null) ? trim((string) $notice['message']) : '';
        if ($message === '') {
            return null;
        }

        $type = is_string($notice['type'] ?? null) && strtolower((string) $notice['type']) === 'success'
            ? 'success'
            : 'error';

        return [
            'type' => $type,
            'message' => $message,
            'reference' => self::normalizeReference($notice['reference'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function safeMeta(string $artifactId): array
    {
        try {
            return VaultManager::artifactMeta($artifactId);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function safeUnlocked(string $artifactId): bool
    {
        try {
            return VaultManager::isUnlocked($artifactId);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isArtifactId(string $id): bool
    {
        return preg_match(self::ARTIFACT_ID_PATTERN, $id) === 1;
    }

    private static function normalizeReference(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9._:-]{1,48}$/', $value) === 1 ? $value : '';
    }

    private static function detectOpcache(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        $status = @opcache_get_status(false);

        return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
    }

    private static function stamp(): string
    {
        if (function_exists('date_i18n')) {
            return (string) date_i18n(self::TIMESTAMP_FORMAT) . ' (site time)';
        }

        return gmdate(self::TIMESTAMP_FORMAT) . ' UTC';
    }

    /** Recursively merge associative arrays; replace lists and scalars. */
    private static function mergeValue(mixed $base, mixed $override): mixed
    {
        if (is_array($base) && is_array($override) && !array_is_list($base) && !array_is_list($override)) {
            foreach ($override as $key => $value) {
                $base[$key] = array_key_exists($key, $base) ? self::mergeValue($base[$key], $value) : $value;
            }

            return $base;
        }

        return $override;
    }
}
