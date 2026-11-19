<?php
/**
 * VGT Omega artifact mounter.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Crypto\KeyDerivation;
use VGT\Omega\System\Vault\Crypto\Manifest;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Fail-closed mounting pipeline for installed vault artifacts.
 *
 * For every artifact the mounter performs, in this order:
 *   1. read the encrypted manifest container from the store,
 *   2. derive the per-artifact key and authenticate/decrypt the manifest with
 *      the v4.2 AAD binding (legacy containers are rejected: allowLegacy=false),
 *   3. parse the manifest with the strict schema (Manifest::fromJson),
 *   4. prove every file references an installed blob (assertHasBlobs),
 *   5. verify the optional Ed25519 signature according to policy,
 *   6. register the authenticated manifest + key + stable mtime in the registry,
 *   7. publish the manifest classmap to the artifact autoloader,
 *   8. include_once the manifest entry point through the vgt:// stream wrapper.
 *
 * There is deliberately no "unencrypted plugin.php" fallback of any kind: an
 * artifact without an authenticated manifest is never executed. A decrypt
 * failure locks the artifact without executing anything; a validation or load
 * failure rolls the partial registration back so no half-mounted artifact can
 * serve files or classes.
 */
final class ArtifactMounter
{
    public const HOOK_MOUNTED = 'vgt_artifact_mounted';

    public const HOOK_LOCKED = 'vgt_artifact_locked';

    public const HOOK_FAILED = 'vgt_artifact_mount_failed';

    private readonly ClassmapAutoloader $classmaps;

    /**
     * Entry-load outcome per artifact for this PHP process.
     *
     * PHP's include_once remembers an opened stream path even when the included
     * file threw a ParseError. A second mount attempt in the same process could
     * therefore silently "succeed" without executing anything. This map makes
     * the decision explicit: a failed or changed entry is never reported as a
     * mount, a verified entry is not re-executed for a second vault context.
     *
     * @var array<string, array{fingerprint: string, ok: bool}>
     */
    private static array $entryState = [];

    public function __construct(
        private readonly ArtifactStoreInterface $store,
        private readonly CryptoEngine $crypto,
        private readonly KeyDerivation $keys,
        private readonly ManifestRegistry $registry,
        private readonly SignatureVerifier $signatures,
        private readonly BridgeInterface $bridge
    ) {
        $this->classmaps = new ClassmapAutoloader($registry);
    }

    /**
     * Mount every installed artifact exactly once.
     *
     * @return array{mounted: list<string>, locked: list<string>, failed: array<string, string>}
     *
     * @throws AvailabilityException when the vgt:// protocol cannot be secured
     */
    public function mountAll(): array
    {
        $stats = ['mounted' => [], 'locked' => [], 'failed' => []];

        $this->ensureStreamWrapper();

        foreach ($this->store->listArtifactIds() as $artifactId) {
            if (!is_string($artifactId)) {
                $stats['failed']['invalid-artifact-id'] = 'invalid-artifact-id';
                error_log('[VGT][MOUNT] Artifact store returned a non-string identifier.');
                continue;
            }

            if (!PathGuard::isValidArtifactId($artifactId)) {
                $stats['failed'][self::sanitizeId($artifactId)] = 'invalid-artifact-id';
                error_log('[VGT][MOUNT] Rejected an artifact with an invalid identifier.');
                continue;
            }

            if ($this->registry->has($artifactId)) {
                // Idempotent re-entry in the same request: never re-execute code.
                $stats['mounted'][] = $artifactId;
                continue;
            }

            $this->mountArtifact($artifactId, $stats);
        }

        // Boot phase finished: release decrypted entry bytes immediately. Later
        // reads are cached lazily again, so a changed blob is re-authenticated.
        StreamWrapper::clearCleartextCache();

        return $stats;
    }

    /**
     * @param array{mounted: list<string>, locked: list<string>, failed: array<string, string>} $stats
     */
    private function mountArtifact(string $artifactId, array &$stats): void
    {
        try {
            $container = $this->store->readManifestContainer($artifactId);
        } catch (StorageException $error) {
            $stats['failed'][$artifactId] = 'manifest-container-unavailable';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        }

        if (!is_string($container) || $container === '') {
            $stats['failed'][$artifactId] = 'manifest-container-unavailable';
            error_log('[VGT][MOUNT] Manifest container unavailable for artifact ' . self::sanitizeId($artifactId) . '.');
            return;
        }

        // Host-wide key derivation failures (missing salts/pepper) abort the boot
        // instead of being downgraded to a per-artifact failure.
        $key = $this->keys->artifactKey($artifactId);

        try {
            $json = $this->crypto->decrypt($container, $key, VaultFormat::manifestAad($artifactId), false);
        } catch (SecurityException $error) {
            // Contract: "locked" is a list of artifact ids (VaultManager, diagnostics
            // and the console consume list<string>); the only lock reason is
            // key-mismatch-or-corrupt and is logged internally.
            $stats['locked'][] = $artifactId;
            ExceptionReporter::report($error, 'vault-lock-' . $artifactId);
            $this->bridge->doAction(self::HOOK_LOCKED, $artifactId);
            return;
        } catch (AvailabilityException $error) {
            $stats['failed'][$artifactId] = 'crypto-unavailable';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        }

        try {
            $manifest = Manifest::fromJson($json, false);
            $manifest->assertHasBlobs();
        } catch (SecurityException $error) {
            $stats['failed'][$artifactId] = 'manifest-rejected';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        }

        try {
            $this->signatures->verify($manifest, 'artifact');
        } catch (SecurityException $error) {
            $stats['failed'][$artifactId] = 'signature-rejected';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        } catch (AvailabilityException $error) {
            // Cannot verify a present signature (libsodium missing): never mount it.
            $stats['failed'][$artifactId] = 'signature-verification-unavailable';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        }

        try {
            $root = $this->store->artifactRoot($artifactId);
            $mtime = self::manifestMtime($root);
        } catch (StorageException $error) {
            $stats['failed'][$artifactId] = 'artifact-root-unavailable';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        }

        try {
            $this->registry->register($artifactId, $root, $manifest, $key, $mtime);
        } catch (\Throwable $error) {
            $stats['failed'][$artifactId] = 'registration-rejected';
            ExceptionReporter::report($error, 'vault-mount-' . $artifactId);
            return;
        }

        $fingerprint = hash('sha256', $manifest->canonicalPayload());
        $known = self::$entryState[$artifactId] ?? null;

        if (is_array($known)) {
            if (($known['ok'] ?? false) !== true || !hash_equals((string) $known['fingerprint'], $fingerprint)) {
                // A previous entry load failed, or the artifact changed mid-request.
                // include_once would silently skip the already opened URI, so this
                // mount must fail closed instead of claiming an execution.
                $this->rollbackRegistration($artifactId);
                $stats['failed'][$artifactId] = 'entry-load-failed';
                error_log('[VGT][MOUNT] Entry already attempted for artifact ' . self::sanitizeId($artifactId) . '; refusing an unverified remount.');
                $this->bridge->doAction(self::HOOK_FAILED, $artifactId);
                return;
            }

            // Same manifest already executed and verified in this process: publish
            // the classmap for the new context and register the mount without
            // re-including (PHP would skip the file anyway).
            try {
                $this->classmaps->registerArtifact($artifactId);
            } catch (\Throwable $error) {
                $this->rollbackRegistration($artifactId);
                $stats['failed'][$artifactId] = 'entry-load-failed';
                ExceptionReporter::report($error, 'vault-entry-' . $artifactId);
                return;
            }

            $stats['mounted'][] = $artifactId;
            $this->bridge->doAction(self::HOOK_MOUNTED, $artifactId);
            return;
        }

        $entryUri = 'vgt://' . $artifactId . '/' . $manifest->entry();

        if (in_array($entryUri, get_included_files(), true)) {
            // Somebody loaded the entry outside the mounter: never claim a mount
            // whose execution this component did not verify.
            $this->rollbackRegistration($artifactId);
            $stats['failed'][$artifactId] = 'entry-load-failed';
            error_log('[VGT][MOUNT] Entry for artifact ' . self::sanitizeId($artifactId) . ' was pre-loaded outside the mounter.');
            $this->bridge->doAction(self::HOOK_FAILED, $artifactId);
            return;
        }

        try {
            $this->classmaps->registerArtifact($artifactId);
            include_once $entryUri;
        } catch (\Throwable $error) {
            // Roll the partial mount back: an artifact whose entry point failed
            // to parse or execute must not keep serving files or classes.
            self::$entryState[$artifactId] = ['fingerprint' => $fingerprint, 'ok' => false];
            $this->rollbackRegistration($artifactId);
            $stats['failed'][$artifactId] = 'entry-load-failed';
            ExceptionReporter::report($error, 'vault-entry-' . $artifactId);
            $this->bridge->doAction(self::HOOK_FAILED, $artifactId);
            return;
        }

        self::$entryState[$artifactId] = ['fingerprint' => $fingerprint, 'ok' => true];
        $stats['mounted'][] = $artifactId;
        $this->bridge->doAction(self::HOOK_MOUNTED, $artifactId);
    }

    /** Withdraw a partially registered artifact from registry and classmap. */
    private function rollbackRegistration(string $artifactId): void
    {
        $this->classmaps->unregisterArtifact($artifactId);
        $this->registry->forget($artifactId);
    }

    /**
     * Bind the vgt:// stream wrapper to this mounter's runtime and register the
     * protocol once per process.
     *
     * Configuration is deliberately re-entrant: every mountAll() rebinds the
     * static registry/crypto pointers to the instances of the current vault
     * context, so a second context in the same PHP process (test harnesses,
     * embeds) always reads through its own registry. Only the
     * stream_wrapper_register() call is guarded against duplication.
     *
     * @throws AvailabilityException when the protocol is owned by a foreign
     *                               wrapper or cannot be registered at all
     */
    private function ensureStreamWrapper(): void
    {
        $alreadyRegistered = in_array('vgt', stream_get_wrappers(), true);

        // Fail closed when an unknown component already owns the protocol before
        // this class ever configured it: including through an unverified wrapper
        // could execute untrusted code.
        if ($alreadyRegistered && !StreamWrapper::isConfigured()) {
            throw new AvailabilityException('The vgt:// protocol is already owned by a foreign stream wrapper.');
        }

        // Idempotent rebind for this context; harmless when it is the same registry.
        StreamWrapper::configure($this->registry, $this->crypto);

        if (!$alreadyRegistered && !stream_wrapper_register('vgt', StreamWrapper::class)) {
            throw new AvailabilityException('The vgt:// stream wrapper could not be registered.');
        }
    }

    /** Stable manifest modification time for url_stat (never time()). */
    private static function manifestMtime(string $artifactRoot): int
    {
        $manifestPath = rtrim(str_replace('\\', '/', $artifactRoot), '/') . '/' . VaultFormat::MANIFEST_FILENAME;
        $mtime = @filemtime($manifestPath);

        if ($mtime === false) {
            clearstatcache(true, $manifestPath);
            $mtime = @filemtime($manifestPath);
        }

        if ($mtime === false) {
            throw new StorageException('Artifact manifest timestamp is unavailable.');
        }

        return $mtime;
    }

    /** Keep only harmless identifier characters for log lines and stat keys. */
    private static function sanitizeId(string $artifactId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.\-]/', '', $artifactId) ?? '';

        return $clean === '' ? 'invalid-artifact-id' : substr($clean, 0, 64);
    }
}
