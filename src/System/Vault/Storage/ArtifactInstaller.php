<?php
/**
 * VGT Omega atomic artifact installer.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AppException;
use VGT\Omega\Core\Exceptions\ConflictException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Crypto\KeyDerivation;
use VGT\Omega\System\Vault\Crypto\Manifest;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\Support\MemoryGuard;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Cloud-package import, hardware-bound re-encryption and atomic publication.
 *
 * Pipeline (every step fails closed):
 *   validate key/name/rotation -> quarantine staging directory -> archive
 *   inspection and extraction -> decrypt + verify cloud manifest and Ed25519
 *   signature -> verify every payload against the declared size and SHA-256 ->
 *   re-encrypt every payload for the local artifact key and AAD -> encrypt the
 *   installed manifest -> atomic directory swap -> mirror raw public assets.
 *
 * A staging directory is always removed in a finally block and no artifact is
 * published before every payload has passed verification. The installer never
 * executes or trusts a raw plugin.php: only the authenticated manifest decides
 * what exists, and a payload which is not declared in that manifest aborts the
 * complete import.
 */
final class ArtifactInstaller
{
    private const CLOUD_KEY_PATTERN = '/^[a-fA-F0-9]{64}$/';

    private const MAX_NAME_LENGTH = 120;

    private const MAX_ID_ATTEMPTS = 5;

    private const MIN_IMPORT_BUDGET = 4194304;

    private const MAX_IMPORT_BUDGET = 50331648;

    private readonly PublicMirror $mirror;

    public function __construct(
        private readonly VaultPaths $paths,
        private readonly ArtifactStoreInterface $store,
        private readonly CryptoEngine $crypto,
        private readonly KeyDerivation $keys,
        private readonly SignatureVerifier $signatures,
        private readonly BridgeInterface $bridge,
        private readonly SafeArchive $archive,
        private readonly MemoryGuard $memory
    ) {
        $this->mirror = new PublicMirror($paths);
    }

    /**
     * Import a cloud-encrypted package as a locally encrypted artifact.
     *
     * @return array{id: string, status: string, signed: bool, files: int}
     * @throws ValidationException on invalid user input
     * @throws SecurityException on tampering, unsafe archives or unauthenticated manifests
     * @throws ConflictException when the rotation target disappeared or an id collides
     * @throws StorageException on IO failures
     */
    public function install(
        string $uploadedZipPath,
        string $cloudKeyHex,
        string $customName = '',
        ?string $rotateId = null
    ): array {
        $cloudKey = $this->cloudKey($cloudKeyHex);
        $displayName = $this->displayName($customName);
        $rotationId = $this->rotationTarget($rotateId);
        $uploadSize = $this->assertUpload($uploadedZipPath);
        $this->memory->assertCanAllocate($this->importBudget($uploadSize), 'artifact import');

        $stagingRoot = $this->createStagingDirectory();

        try {
            $incoming = SafeFilesystem::ensureDirectory($stagingRoot . '/incoming', 0700);
            $out = SafeFilesystem::ensureDirectory($stagingRoot . '/out', 0700);

            $inspection = $this->archive->inspect($uploadedZipPath);

            if ($inspection['total_uncompressed'] > VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES) {
                throw new SecurityException('The package exceeds the installable payload budget.');
            }

            $this->archive->extract($uploadedZipPath, $incoming);

            $import = $this->readCloudManifest($incoming, $cloudKey);
            $cloudManifest = $import['manifest'];
            $signed = $import['signed'];
            $artifactId = $rotationId ?? $this->newArtifactId();
            $artifactKey = $this->keys->artifactKey($artifactId);
            $payloads = $this->writeInstalledPayloads(
                $incoming,
                $out,
                $artifactId,
                $artifactKey,
                $cloudKey,
                $cloudManifest
            );

            $finalName = $displayName !== ''
                ? $displayName
                : ($cloudManifest->name() !== '' ? $cloudManifest->name() : 'VGT Artifact');
            $createdAt = $cloudManifest->createdAt() !== '' ? $cloudManifest->createdAt() : null;
            $installedManifest = Manifest::build(
                $cloudManifest->entry(),
                $finalName,
                $payloads['records'],
                $cloudManifest->classmap(),
                $createdAt
            );

            $manifestContainer = $this->crypto->encrypt(
                $installedManifest->canonicalPayload(),
                $artifactKey,
                VaultFormat::manifestAad($artifactId),
                true
            );

            SafeFilesystem::writeAtomic(
                $out . '/' . VaultFormat::MANIFEST_FILENAME,
                $manifestContainer,
                0600
            );

            $meta = [
                'name' => $finalName,
                'updated' => time(),
                'entry' => $installedManifest->entry(),
                'files' => count($payloads['records']),
                'signed' => $signed,
                'format' => VaultFormat::SPEC_VERSION,
            ];

            $this->store->publish($out, $artifactId, $meta);
            $this->mirror->publish($artifactId, $payloads['raw']);
            $this->fireAction('vgt_vault_artifact_installed', $artifactId, $meta);

            return [
                'id' => $artifactId,
                'status' => $rotationId !== null ? 'rotated' : 'installed',
                'signed' => $signed,
                'files' => count($payloads['records']),
            ];
        } catch (\Throwable $error) {
            if ($error instanceof AppException) {
                throw $error;
            }

            error_log('[VGT][STORAGE] Artifact import failed: ' . ExceptionReporter::sanitizeLogMessage($error->getMessage()));

            throw new StorageException('The artifact installation was aborted safely.', 0, $error);
        } finally {
            try {
                SafeFilesystem::deleteTree($stagingRoot);
            } catch (\Throwable $cleanupError) {
                error_log(
                    '[VGT][STORAGE] Staging cleanup failed: '
                    . ExceptionReporter::sanitizeLogMessage($cleanupError->getMessage())
                );
            }
        }
    }

    /**
     * Permanently remove an installed artifact and its public mirror.
     *
     * @throws ValidationException when the artifact does not exist
     * @throws SecurityException when the identifier is malformed
     */
    public function delete(string $artifactId): void
    {
        PathGuard::assertArtifactId($artifactId);

        if (!in_array($artifactId, $this->store->listArtifactIds(), true)) {
            throw new ValidationException('The selected artifact does not exist in the vault.');
        }

        $this->store->forget($artifactId);
        $this->mirror->remove($artifactId);
        $this->fireAction('vgt_vault_artifact_deleted', $artifactId);
    }

    /** @return array{manifest: Manifest, signed: bool} */
    private function readCloudManifest(string $incoming, #[\SensitiveParameter] string $cloudKey): array
    {
        $containerPath = $incoming . '/' . VaultFormat::MANIFEST_FILENAME;

        if (is_link($containerPath)) {
            throw new SecurityException('The package manifest is a symbolic link.');
        }

        if (!is_file($containerPath)) {
            throw new SecurityException('The package does not contain an artifact manifest.');
        }

        $container = SafeFilesystem::readRegularFile($containerPath, VaultFormat::MAX_MANIFEST_BYTES);
        $json = $this->crypto->decrypt($container, $cloudKey, VaultFormat::cloudManifestAad(), true);
        $manifest = Manifest::fromJson($json, true);
        $this->signatures->verify($manifest, 'cloud artifact');

        return [
            'manifest' => $manifest,
            'signed' => $manifest->signature() !== null,
        ];
    }

    /**
     * Verify and re-encrypt every declared payload into out/blobs.
     *
     * @return array{records: array<string, array{mode: string, compression: string, size: int, sha256: string, blob: string}>, raw: array<string, string>}
     */
    private function writeInstalledPayloads(
        string $incoming,
        string $out,
        string $artifactId,
        #[\SensitiveParameter] string $artifactKey,
        #[\SensitiveParameter] string $cloudKey,
        Manifest $manifest
    ): array {
        $files = $manifest->files();
        $scan = SafeFilesystem::scanTree($incoming);

        foreach (array_keys($scan['files']) as $extracted) {
            if ($extracted === VaultFormat::MANIFEST_FILENAME) {
                continue;
            }

            if (!array_key_exists($extracted, $files)) {
                throw new SecurityException('The package contains a payload that is not declared in its manifest.');
            }
        }

        $blobDir = SafeFilesystem::ensureDirectory($out . '/blobs', 0700);
        $records = [];
        $raw = [];
        $total = 0;

        foreach ($files as $path => $spec) {
            if ($path === VaultFormat::MANIFEST_FILENAME) {
                throw new SecurityException('The manifest cannot declare itself as a payload.');
            }

            $source = $incoming . '/' . $path;

            if (is_link($source)) {
                throw new SecurityException('A declared payload is a symbolic link.');
            }

            if (!is_file($source)) {
                throw new SecurityException('The package is missing a declared payload file.');
            }

            $container = SafeFilesystem::readRegularFile($source, VaultFormat::MAX_ARCHIVE_BYTES);
            $this->memory->assertCanAllocate((int) (strlen($container) * 2) + 524288, 'payload import');

            if ($spec['mode'] === 'encrypted') {
                $cleartext = $this->crypto->decrypt($container, $cloudKey, VaultFormat::cloudAad($path), true);
            } else {
                if (!VaultFormat::isPublicAssetPath($path) || VaultFormat::isExecutablePath($path)) {
                    throw new SecurityException('A non-asset payload was marked as cleartext raw data.');
                }

                $cleartext = $container;
            }

            $this->verifyPayload($path, $spec, $cleartext);
            $total += strlen($cleartext);

            if ($total > VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES) {
                throw new SecurityException('The package plaintext exceeds the total payload budget.');
            }

            $blobId = PathGuard::newBlobId();

            if ($spec['mode'] === 'encrypted') {
                $preferCompression = strlen($cleartext) >= VaultFormat::RAW_COMPRESSION_THRESHOLD;
                $blob = $this->crypto->encrypt(
                    $cleartext,
                    $artifactKey,
                    VaultFormat::aad($artifactId, $path),
                    $preferCompression
                );
                // Record what the container actually used (DEFLATE only applies
                // when it reduced the payload), not merely what was requested.
                $compression = (ord($blob[VaultFormat::MAGIC_LENGTH + 1]) & VaultFormat::FLAG_GZIP) !== 0
                    ? 'gz'
                    : 'none';
            } else {
                $blob = $cleartext;
                $compression = 'none';
                $raw[$path] = $cleartext;
            }

            $blobPath = $blobDir . '/' . $blobId . VaultFormat::BLOB_EXTENSION;

            SafeFilesystem::writeAtomic($blobPath, $blob, 0600);

            $records[$path] = [
                'mode' => $spec['mode'],
                'compression' => $compression,
                'size' => strlen($cleartext),
                'sha256' => hash('sha256', $cleartext),
                'blob' => $blobId,
            ];

            unset($cleartext, $blob, $container);
        }

        return ['records' => $records, 'raw' => $raw];
    }

    /**
     * @param array{mode: string, compression: string, size: int|null, sha256: string|null, blob?: string} $spec
     * @throws SecurityException when the payload differs from its declared identity
     */
    private function verifyPayload(string $path, array $spec, string $cleartext): void
    {
        if ($spec['size'] !== null && $spec['size'] !== strlen($cleartext)) {
            throw new SecurityException('A package payload does not match its declared size.');
        }

        if ($spec['sha256'] !== null && !hash_equals($spec['sha256'], hash('sha256', $cleartext))) {
            throw new SecurityException('A package payload failed its declared hash verification.');
        }
    }

    /** @throws ValidationException */
    private function cloudKey(#[\SensitiveParameter] string $cloudKeyHex): string
    {
        $hex = trim($cloudKeyHex);

        if (preg_match(self::CLOUD_KEY_PATTERN, $hex) !== 1) {
            throw new ValidationException('The artifact decryption key must be exactly 64 hexadecimal characters.');
        }

        $key = hex2bin($hex);

        if (!is_string($key) || strlen($key) !== 32) {
            throw new ValidationException('The artifact decryption key is malformed.');
        }

        return $key;
    }

    /** @throws ValidationException */
    private function displayName(string $customName): string
    {
        $name = trim($customName);

        if ($name === '') {
            return '';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new ValidationException('The artifact name contains unsupported control characters.');
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if ($length > self::MAX_NAME_LENGTH) {
            throw new ValidationException('The artifact name exceeds 120 characters.');
        }

        return $name;
    }

    /** @throws SecurityException|ConflictException */
    private function rotationTarget(?string $rotateId): ?string
    {
        if ($rotateId === null || $rotateId === '') {
            return null;
        }

        PathGuard::assertArtifactId($rotateId);

        if (!in_array($rotateId, $this->store->listArtifactIds(), true)) {
            throw new ConflictException('The artifact selected for rotation no longer exists.');
        }

        return $rotateId;
    }

    /** @throws ValidationException|SecurityException|StorageException */
    private function assertUpload(string $uploadedZipPath): int
    {
        if ($uploadedZipPath === '' || str_contains($uploadedZipPath, "\0")) {
            throw new ValidationException('No artifact package was provided.');
        }

        clearstatcache(true, $uploadedZipPath);

        if (is_link($uploadedZipPath)) {
            throw new SecurityException('The uploaded package path is a symbolic link.');
        }

        if (!is_file($uploadedZipPath)) {
            throw new StorageException('The uploaded package is not readable.');
        }

        $size = @filesize($uploadedZipPath);

        if ($size === false || $size < 1) {
            throw new ValidationException('The uploaded package is empty.');
        }

        if ($size > VaultFormat::MAX_ARCHIVE_BYTES) {
            throw new ValidationException('The package exceeds the maximum archive size.');
        }

        return $size;
    }

    private function importBudget(int $uploadSize): int
    {
        $budget = $uploadSize * 2 + VaultFormat::MAX_PLAINTEXT_FILE_BYTES;

        return max(self::MIN_IMPORT_BUDGET, min(self::MAX_IMPORT_BUDGET, $budget));
    }

    /** @throws StorageException */
    private function createStagingDirectory(): string
    {
        $base = SafeFilesystem::ensureDirectory($this->paths->stagingDir(), 0700);
        $stagingRoot = $base . '/install_' . bin2hex(random_bytes(16));

        SafeFilesystem::ensureDirectory($stagingRoot, 0700);

        return $stagingRoot;
    }

    /** @throws ConflictException */
    private function newArtifactId(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ID_ATTEMPTS; $attempt++) {
            $candidate = PathGuard::newArtifactId();

            if (!$this->store->exists($candidate)) {
                return $candidate;
            }
        }

        throw new ConflictException('A unique artifact identifier could not be reserved.');
    }

    private function fireAction(string $hook, string $artifactId, array $meta = []): void
    {
        try {
            $this->bridge->doAction($hook, $artifactId, $meta);
        } catch (\Throwable $error) {
            error_log('[VGT][STORAGE] Host hook failed: ' . ExceptionReporter::sanitizeLogMessage($error->getMessage()));
        }
    }
}
