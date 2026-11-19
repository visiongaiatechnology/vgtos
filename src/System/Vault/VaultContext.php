<?php
/**
 * VGT Omega vault service context.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Crypto\KeyDerivation;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\Storage\ArtifactInstaller;
use VGT\Omega\System\Vault\Storage\VaultPaths;
use VGT\Omega\System\Vault\Support\MemoryGuard;

/**
 * Immutable composition root of the vault subsystem.
 *
 * All services are constructed once per request by VaultManager::boot() and
 * injected explicitly; no component resolves collaborators globally.
 */
final class VaultContext
{
    public function __construct(
        public readonly VaultPaths $paths,
        public readonly ArtifactStoreInterface $store,
        public readonly CryptoEngine $crypto,
        public readonly KeyDerivation $keys,
        public readonly SignatureVerifier $signatures,
        public readonly MemoryGuard $memory,
        public readonly ArtifactInstaller $installer,
        public readonly ManifestRegistry $registry,
        public readonly ArtifactMounter $mounter,
        public readonly SystemDiagnostics $diagnostics,
        public readonly AssetResolver $assets,
        public readonly BridgeInterface $bridge,
        public readonly EnvironmentInterface $environment
    ) {
    }
}
