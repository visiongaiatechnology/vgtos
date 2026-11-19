<?php
/**
 * VGT Omega reference artifact packer.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Tooling;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Crypto\Manifest;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Reference encryptor for the documented .vgt package format (ARTIFACT_SPEC.md).
 *
 * Produces: vgt_manifest.vgt (cloud-key encrypted manifest), encrypted payload
 * containers under their original relative paths, optionally an Ed25519
 * signature over the canonical manifest. The output ZIP is the one accepted by
 * the VGT Console installer (cloud-key import, staging validation, hardware
 * re-encryption).
 */
final class ArtifactPacker
{
    private const MAX_FILES = 1000;

    private readonly CryptoEngine $crypto;

    public function __construct()
    {
        $this->crypto = new CryptoEngine();
    }

    /**
     * @param array<string, string> $classmap
     * @return array{zip: string, files: int, signed: bool, format: string, size: int, sha256: string}
     *
     * @throws ValidationException|SecurityException|StorageException
     */
    public function pack(
        string $sourceDir,
        string $outZipPath,
        string $cloudKeyHex,
        string $entry = 'plugin.php',
        string $name = '',
        ?string $ed25519SecretKeyBase64 = null,
        array $classmap = []
    ): array {
        $cloudKey = $this->cloudKey($cloudKeyHex);
        $source = realpath($sourceDir);

        if ($source === false || !is_dir($source) || !is_readable($source)) {
            throw new ValidationException('The artifact source directory is not readable.');
        }

        $source = rtrim(str_replace('\\', '/', $source), '/');
        $entry = PathGuard::normalizeRelative($entry);

        if (!str_ends_with(strtolower($entry), '.php')) {
            throw new ValidationException('The artifact entry point must be a PHP file.');
        }

        if (!is_file($source . '/' . $entry)) {
            throw new ValidationException('The artifact entry point does not exist inside the source directory.');
        }

        $displayName = trim($name) !== '' ? trim($name) : basename($source);
        if (mb_strlen($displayName) > 120) {
            throw new ValidationException('The artifact name exceeds 120 characters.');
        }

        $outReal = $this->resolveOutputPath($outZipPath);
        $manifestFiles = [];
        $zipEntries = [];
        $totalBytes = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new SecurityException('The artifact source contains a symbolic link.');
            }

            if (!$file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = substr($absolute, strlen($source) + 1);

            if ($absolute === $outReal) {
                continue;
            }

            $relative = PathGuard::normalizeRelative($relative);

            if ($relative === VaultFormat::MANIFEST_FILENAME) {
                continue;
            }

            if (count($manifestFiles) >= self::MAX_FILES) {
                throw new SecurityException('The artifact source exceeds the maximum file count.');
            }

            $cleartext = @file_get_contents($absolute);
            if (!is_string($cleartext)) {
                throw new StorageException('A source file could not be read for packaging.');
            }

            $size = strlen($cleartext);
            if ($size > VaultFormat::MAX_PLAINTEXT_FILE_BYTES) {
                throw new SecurityException('A source file exceeds the maximum payload size.');
            }

            $totalBytes += $size;
            if ($totalBytes > VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES) {
                throw new SecurityException('The artifact plaintext exceeds the total budget.');
            }

            $isPublicAsset = VaultFormat::isPublicAssetPath($relative) && !VaultFormat::isExecutablePath($relative);

            if ($isPublicAsset) {
                $manifestFiles[$relative] = [
                    'mode' => 'raw',
                    'compression' => 'none',
                    'size' => $size,
                    'sha256' => hash('sha256', $cleartext),
                ];
                $zipEntries[$relative] = $cleartext;
                continue;
            }

            $compression = $size >= VaultFormat::RAW_COMPRESSION_THRESHOLD ? 'gz' : 'none';
            $container = $this->crypto->encrypt(
                $cleartext,
                $cloudKey,
                VaultFormat::cloudAad($relative),
                $compression === 'gz'
            );

            $manifestFiles[$relative] = [
                'mode' => 'encrypted',
                'compression' => $compression,
                'size' => $size,
                'sha256' => hash('sha256', $cleartext),
            ];
            $zipEntries[$relative] = $container;
        }

        if ($zipEntries === []) {
            throw new ValidationException('The artifact source directory is empty.');
        }

        if (!isset($manifestFiles[$entry])) {
            throw new SecurityException('The entry point is not part of the packaged payload.');
        }

        $manifest = Manifest::build($entry, $displayName, $manifestFiles, $classmap);

        if ($ed25519SecretKeyBase64 !== null && $ed25519SecretKeyBase64 !== '') {
            $manifest = $manifest->withSignature($this->signManifest($manifest, $ed25519SecretKeyBase64));
        }

        $manifestJson = $manifest->toJson(true);
        $manifestContainer = $this->crypto->encrypt(
            $manifestJson,
            $cloudKey,
            VaultFormat::cloudManifestAad(),
            true
        );

        if (strlen($manifestContainer) > VaultFormat::MAX_MANIFEST_BYTES) {
            throw new SecurityException('The generated manifest exceeds the container budget.');
        }

        $this->writeZip($outReal, $manifestContainer, $zipEntries);

        $zipHash = hash_file('sha256', $outReal);
        $zipSize = filesize($outReal);

        if ($zipHash === false || $zipSize === false) {
            throw new StorageException('The generated package could not be verified.');
        }

        return [
            'zip' => $outReal,
            'files' => count($manifestFiles),
            'signed' => $manifest->signature() !== null,
            'format' => VaultFormat::SPEC_VERSION,
            'size' => $zipSize,
            'sha256' => $zipHash,
        ];
    }

    /** @return array{alg: string, public_key: string, sig: string, kid: string} */
    private function signManifest(Manifest $manifest, string $secretKeyBase64): array
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new ValidationException('libsodium is required to sign artifacts.');
        }

        $secretKey = base64_decode($secretKeyBase64, true);

        if (!is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ValidationException('The Ed25519 secret key must be base64 of exactly 64 bytes.');
        }

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        $signature = sodium_crypto_sign_detached($manifest->canonicalPayload(), $secretKey);

        return [
            'alg' => 'ed25519',
            'public_key' => base64_encode($publicKey),
            'sig' => base64_encode($signature),
            'kid' => 'vgt-pack',
        ];
    }

    /**
     * @param array<string, string> $entries
     *
     * @throws StorageException
     */
    private function writeZip(string $outPath, string $manifestContainer, array $entries): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new StorageException('ext-zip is required on the packaging machine (the runtime can fall back to PclZip).');
        }

        $temp = $outPath . '.tmp.' . bin2hex(random_bytes(6));
        $zip = new \ZipArchive();

        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new StorageException('The output package could not be created.');
        }

        if (!$zip->addFromString(VaultFormat::MANIFEST_FILENAME, $manifestContainer)) {
            $zip->close();
            @unlink($temp);
            throw new StorageException('The manifest could not be added to the package.');
        }

        foreach ($entries as $relative => $bytes) {
            if (!$zip->addFromString($relative, $bytes)) {
                $zip->close();
                @unlink($temp);
                throw new StorageException('A payload file could not be added to the package.');
            }
        }

        if (!$zip->close()) {
            @unlink($temp);
            throw new StorageException('The package could not be finalized.');
        }

        if (!@rename($temp, $outPath)) {
            @unlink($temp);
            throw new StorageException('The package could not be published atomically.');
        }
    }

    private function cloudKey(string $hex): string
    {
        $hex = trim($hex);

        if (preg_match('/^[a-fA-F0-9]{64}$/', $hex) !== 1) {
            throw new ValidationException('The cloud key must be exactly 64 hexadecimal characters.');
        }

        return (string) hex2bin($hex);
    }

    private function resolveOutputPath(string $outZipPath): string
    {
        $directory = dirname($outZipPath);
        $realDir = realpath($directory);

        if ($realDir === false || !is_dir($realDir) || !$this->isWriteProbe($realDir)) {
            throw new ValidationException('The output directory is not writable.');
        }

        if (!str_ends_with(strtolower($outZipPath), '.zip')) {
            throw new ValidationException('The output file must use the .zip extension.');
        }

        return rtrim(str_replace('\\', '/', $realDir), '/') . '/' . basename($outZipPath);
    }

    /**
     * Prove writability with a real byte write.
     *
     * is_writable() is unreliable on some Windows/ACL and sandboxed hosts: it
     * can report false while an actual create/write/delete cycle succeeds.
     * A random probe file is the only trustworthy check here.
     */
    private function isWriteProbe(string $directory): bool
    {
        $probe = rtrim(str_replace('\\', '/', $directory), '/') . '/.vgt_probe_' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'xb');

        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, 'vgt');
        fclose($handle);
        @unlink($probe);

        return $written === 3;
    }
}
