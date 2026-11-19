<?php
/**
 * VGT Omega vault manager facade.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Admin\ArtifactController;
use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Crypto\KeyDerivation;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\Storage\ArtifactInstaller;
use VGT\Omega\System\Vault\Storage\ArtifactRepository;
use VGT\Omega\System\Vault\Storage\SafeArchive;
use VGT\Omega\System\Vault\Storage\VaultLocator;
use VGT\Omega\System\Vault\Storage\VaultLockdown;
use VGT\Omega\System\Vault\Storage\VaultPaths;
use VGT\Omega\System\Vault\Support\MemoryGuard;

/**
 * Composition root and stable public API of the encrypted artifact vault.
 *
 * Boot sequence (fail-closed):
 *   locate out-of-webroot vault -> write lockdown files -> compose services ->
 *   mount only artifacts with an authenticated manifest + valid signature.
 *
 * There is no unencrypted plugin.php fallback. If any precondition fails the
 * vault stays closed, the failure is logged with an opaque reference and the
 * dashboard remains usable for diagnostics, rotation and deletion.
 */
final class VaultManager
{
    private static bool $booted = false;

    private static ?VaultContext $context = null;

    private static ?string $bootError = null;

    /** @var array{mounted: list<string>, locked: list<string>, failed: array<string, string>} */
    private static array $mountStats = ['mounted' => [], 'locked' => [], 'failed' => []];

    public static function boot(BridgeInterface $bridge, EnvironmentInterface $environment): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        self::$bootError = null;

        try {
            $paths = (new VaultLocator($environment))->locate();
            (new VaultLockdown())->apply($paths);

            $store = new ArtifactRepository($paths, $bridge, $environment);
            $memory = new MemoryGuard();
            $crypto = new CryptoEngine(
                VaultFormat::MAX_PLAINTEXT_FILE_BYTES,
                VaultFormat::RAW_COMPRESSION_THRESHOLD,
                $memory
            );
            $keys = new KeyDerivation($environment, $bridge);
            $signatures = SignatureVerifier::fromPolicy();
            $installer = new ArtifactInstaller(
                $paths,
                $store,
                $crypto,
                $keys,
                $signatures,
                $bridge,
                new SafeArchive(),
                $memory
            );
            $registry = new ManifestRegistry();
            $mounter = new ArtifactMounter($store, $crypto, $keys, $registry, $signatures, $bridge);
            $diagnostics = new SystemDiagnostics($paths, $keys, $store, $signatures);
            $assets = new AssetResolver($registry, $store);

            self::$context = new VaultContext(
                $paths,
                $store,
                $crypto,
                $keys,
                $signatures,
                $memory,
                $installer,
                $registry,
                $mounter,
                $diagnostics,
                $assets,
                $bridge,
                $environment
            );

            self::registerHostFilters($bridge, $assets);
            ArtifactController::register($bridge);

            if (self::isSafeMode()) {
                self::$mountStats = [
                    'mounted' => [],
                    'locked' => $store->listArtifactIds(),
                    'failed' => [],
                ];
                error_log('[VGT][SECURITY] VGT_OMEGA_SAFE_MODE active: artifact mounting suspended.');
            } else {
                self::$mountStats = $mounter->mountAll();
            }
        } catch (\Throwable $error) {
            self::$context = null;
            self::$bootError = ExceptionReporter::report($error, 'vault-boot');
        }
    }

    public static function isBooted(): bool
    {
        return self::$context !== null;
    }

    public static function bootError(): ?string
    {
        return self::$bootError;
    }

    public static function isSafeMode(): bool
    {
        if (!defined('VGT_OMEGA_SAFE_MODE')) {
            return false;
        }

        return filter_var(constant('VGT_OMEGA_SAFE_MODE'), FILTER_VALIDATE_BOOL);
    }

    public static function isLayerZero(): bool
    {
        return defined('VGT_OMEGA_MU_KERNEL') || defined('VGT_OMEGA_LAYER_ZERO');
    }

    public static function version(): string
    {
        return defined('VGT_OMEGA_VERSION') ? (string) constant('VGT_OMEGA_VERSION') : '4.2.0';
    }

    /** @return list<string> */
    public static function artifacts(): array
    {
        return self::store()->listArtifactIds();
    }

    /** @return array{name: string, updated: int, entry: string, files: int, signed: bool, format: string} */
    public static function artifactMeta(string $artifactId): array
    {
        return self::store()->getMeta($artifactId);
    }

    public static function isUnlocked(string $artifactId): bool
    {
        return self::$context !== null && self::$context->registry->has($artifactId);
    }

    /** @return list<string> */
    public static function lockedArtifacts(): array
    {
        return self::$mountStats['locked'];
    }

    /** @return array{mounted: list<string>, locked: list<string>, failed: array<string, string>} */
    public static function mountStats(): array
    {
        return self::$mountStats;
    }

    /** @return array<string, array<string, mixed>> */
    public static function diagnostics(): array
    {
        $context = self::contextOrThrow();

        return $context->diagnostics->collect(
            self::isSafeMode(),
            self::isLayerZero(),
            self::$bootError,
            self::$mountStats
        );
    }

    public static function store(): ArtifactStoreInterface
    {
        return self::contextOrThrow()->store;
    }

    public static function installer(): ArtifactInstaller
    {
        return self::contextOrThrow()->installer;
    }

    public static function paths(): VaultPaths
    {
        return self::contextOrThrow()->paths;
    }

    public static function registry(): ManifestRegistry
    {
        return self::contextOrThrow()->registry;
    }

    public static function context(): VaultContext
    {
        return self::contextOrThrow();
    }

    /** Guarded reset used exclusively by the test harness. */
    public static function resetForTests(): void
    {
        if (!defined('VGT_OMEGA_TESTING') || constant('VGT_OMEGA_TESTING') !== true) {
            throw new \LogicException('VGT: resetForTests is restricted to the test harness.');
        }

        self::$booted = false;
        self::$context = null;
        self::$bootError = null;
        self::$mountStats = ['mounted' => [], 'locked' => [], 'failed' => []];
    }

    private static function contextOrThrow(): VaultContext
    {
        if (self::$context instanceof VaultContext) {
            return self::$context;
        }

        throw new AvailabilityException(
            self::$bootError !== null
                ? 'Vault subsystem unavailable (reference ' . self::$bootError . ').'
                : 'Vault subsystem has not been booted.'
        );
    }

    private static function registerHostFilters(BridgeInterface $bridge, AssetResolver $assets): void
    {
        $assets->registerFilters($bridge);

        // Keep the virtual protocol usable inside wp_kses protocol allowlists.
        $bridge->addFilter('kses_allowed_protocols', static function (array $protocols): array {
            if (!in_array('vgt', $protocols, true)) {
                $protocols[] = 'vgt';
            }

            return $protocols;
        });
    }
}
