<?php
/**
 * GeDefense WPR artifact manifest.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Crypto;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;

/**
 * Immutable, validated vgt_manifest.vgt document.
 *
 * The manifest is untrusted input. Every path, size, hash, classmap target and
 * signature field is validated before the artifact may be mounted. Unknown
 * top-level keys are rejected (fail-closed schema).
 *
 * Canonical signature payload: deterministic JSON of the manifest without the
 * "signature" member (object keys sorted recursively, lists preserved).
 */
final class Manifest
{
    private const MAX_FILES = 1000;

    private const MAX_CLASSMAP = 2000;

    private const MAX_NAME_LENGTH = 120;

    private const ALLOWED_KEYS = ['format', 'entry', 'name', 'created_at', 'files', 'classmap', 'signature'];

    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * @param array<string, array{mode: string, compression: string, size: int|null, sha256: string|null, blob?: string}> $files
     * @param array<string, string> $classmap
     * @param array{alg: string, public_key: string, sig: string, kid?: string}|null $signature
     */
    private function __construct(
        private readonly string $format,
        private readonly string $entry,
        private readonly string $name,
        private readonly string $createdAt,
        private readonly array $files,
        private readonly array $classmap,
        private readonly ?array $signature
    ) {
    }

    /** @throws SecurityException */
    public static function fromJson(string $json, bool $allowLegacy = false): self
    {
        if (strlen($json) > VaultFormat::MAX_MANIFEST_BYTES) {
            throw new SecurityException('Manifest exceeds the maximum size.');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SecurityException('Artifact manifest is not valid JSON.');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new SecurityException('Artifact manifest must be a JSON object.');
        }

        return self::fromArray($data, $allowLegacy);
    }

    /** @throws SecurityException */
    public static function fromArray(array $data, bool $allowLegacy = false): self
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_KEYS, true)) {
                throw new SecurityException('Manifest contains an unsupported field.');
            }
        }

        $format = $data['format'] ?? null;

        if (!is_string($format)) {
            throw new SecurityException('Manifest format identifier is missing.');
        }

        $isLegacy = in_array($format, VaultFormat::LEGACY_SPEC_VERSIONS, true);

        if ($format !== VaultFormat::SPEC_VERSION && !($allowLegacy && $isLegacy)) {
            throw new SecurityException('Unsupported artifact manifest format.');
        }

        $entryRaw = $data['entry'] ?? null;
        if (!is_string($entryRaw)) {
            throw new SecurityException('Manifest entry point is missing.');
        }

        $entry = self::safePath($entryRaw, 'entry point');

        $name = '';
        if (array_key_exists('name', $data)) {
            if (!is_string($data['name'])) {
                throw new SecurityException('Manifest name must be a string.');
            }
            $name = self::validateName($data['name']);
        }

        $createdAt = '';
        if (array_key_exists('created_at', $data)) {
            if (!is_string($data['created_at']) || strlen($data['created_at']) > 40
                || preg_match('/[\x00-\x1F\x7F]/', $data['created_at']) === 1) {
                throw new SecurityException('Manifest creation timestamp is malformed.');
            }
            $createdAt = $data['created_at'];
        }

        $filesRaw = $data['files'] ?? null;
        if (!is_array($filesRaw) || $filesRaw === [] || array_is_list($filesRaw)) {
            throw new SecurityException('Manifest file table is missing or malformed.');
        }

        if (count($filesRaw) > self::MAX_FILES) {
            throw new SecurityException('Manifest declares too many files.');
        }

        $files = [];
        $totalBytes = 0;

        foreach ($filesRaw as $rawPath => $rawSpec) {
            if (!is_string($rawPath)) {
                throw new SecurityException('Manifest file path must be a string.');
            }

            $path = self::safePath($rawPath, 'payload path');

            if (isset($files[$path])) {
                throw new SecurityException('Manifest contains colliding normalized file paths.');
            }

            if (is_string($rawSpec)) {
                if (!$allowLegacy) {
                    throw new SecurityException('Legacy manifest file shorthand is not accepted.');
                }
                $rawSpec = ['mode' => $rawSpec];
            }

            if (!is_array($rawSpec) || array_is_list($rawSpec)) {
                throw new SecurityException('Manifest file entry must be an object.');
            }

            $mode = $rawSpec['mode'] ?? 'encrypted';
            if (!is_string($mode) || !in_array($mode, ['encrypted', 'raw'], true)) {
                throw new SecurityException('Manifest file mode is invalid.');
            }

            $compression = $rawSpec['compression'] ?? 'gz';
            if (!is_string($compression) || !in_array($compression, ['gz', 'none'], true)) {
                throw new SecurityException('Manifest compression mode is invalid.');
            }

            $size = null;
            if (array_key_exists('size', $rawSpec) && $rawSpec['size'] !== null) {
                if (!is_int($rawSpec['size']) || $rawSpec['size'] < 0
                    || $rawSpec['size'] > VaultFormat::MAX_PLAINTEXT_FILE_BYTES) {
                    throw new SecurityException('Manifest file size is invalid.');
                }
                $size = $rawSpec['size'];
                $totalBytes += $size;
            } elseif (!$isLegacy) {
                throw new SecurityException('Manifest file size is required.');
            }

            $sha256 = null;
            if (array_key_exists('sha256', $rawSpec) && $rawSpec['sha256'] !== null) {
                if (!is_string($rawSpec['sha256']) || preg_match('/^[a-f0-9]{64}$/i', $rawSpec['sha256']) !== 1) {
                    throw new SecurityException('Manifest file hash is invalid.');
                }
                $sha256 = strtolower($rawSpec['sha256']);
            } elseif (!$isLegacy) {
                throw new SecurityException('Manifest file hash is required.');
            }

            $blob = null;
            if (array_key_exists('blob', $rawSpec) && $rawSpec['blob'] !== null) {
                if (!is_string($rawSpec['blob']) || preg_match('/^[a-f0-9]{32}$/', $rawSpec['blob']) !== 1) {
                    throw new SecurityException('Manifest blob identifier is invalid.');
                }
                $blob = $rawSpec['blob'];
            }

            if ($mode === 'raw') {
                if (VaultFormat::isExecutablePath($path)) {
                    throw new SecurityException('Executable payload marked as raw cleartext.');
                }
                if (!VaultFormat::isPublicAssetPath($path)) {
                    throw new SecurityException('Non-asset payload marked as raw cleartext.');
                }
            }

            $entrySpec = [
                'mode' => $mode,
                'compression' => $compression,
                'size' => $size,
                'sha256' => $sha256,
            ];

            if ($blob !== null) {
                $entrySpec['blob'] = $blob;
            }

            $files[$path] = $entrySpec;
        }

        if ($totalBytes > VaultFormat::MAX_TOTAL_PLAINTEXT_BYTES) {
            throw new SecurityException('Declared artifact plaintext exceeds the total budget.');
        }

        if (!isset($files[$entry])) {
            throw new SecurityException('Manifest entry point is not part of the file table.');
        }

        if ($files[$entry]['mode'] !== 'encrypted') {
            throw new SecurityException('Manifest entry point must be encrypted.');
        }

        $classmap = self::validateClassmap($data['classmap'] ?? null, $files);
        $signature = self::validateSignature($data['signature'] ?? null);

        return new self($format, $entry, $name, $createdAt, $files, $classmap, $signature);
    }

    /**
     * Build a manifest for the reference encryptor (validated through fromArray).
     *
     * @param array<string, array{mode?: string, compression?: string, size: int, sha256: string}> $files
     * @param array<string, string> $classmap
     */
    public static function build(string $entry, string $name, array $files, array $classmap = [], ?string $createdAt = null): self
    {
        $data = [
            'format' => VaultFormat::SPEC_VERSION,
            'entry' => $entry,
            'name' => $name,
            'created_at' => $createdAt ?? gmdate('c'),
            'files' => $files,
        ];

        if ($classmap !== []) {
            $data['classmap'] = $classmap;
        }

        return self::fromArray($data);
    }

    public function format(): string
    {
        return $this->format;
    }

    public function entry(): string
    {
        return $this->entry;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /** @return array<string, array{mode: string, compression: string, size: int|null, sha256: string|null, blob?: string}> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, string> */
    public function classmap(): array
    {
        return $this->classmap;
    }

    /** @return array{alg: string, public_key: string, sig: string, kid?: string}|null */
    public function signature(): ?array
    {
        return $this->signature;
    }

    public function isLegacy(): bool
    {
        return $this->format !== VaultFormat::SPEC_VERSION;
    }

    /** @return array{mode: string, compression: string, size: int|null, sha256: string|null, blob?: string}|null */
    public function file(string $relativePath): ?array
    {
        return $this->files[$relativePath] ?? null;
    }

    /** Runtime manifests must reference every payload as an installed blob. */
    public function assertHasBlobs(): void
    {
        foreach ($this->files as $path => $spec) {
            if (!isset($spec['blob']) || !is_string($spec['blob']) || $spec['blob'] === '') {
                throw new SecurityException('Installed manifest is missing a blob reference for: ' . $path);
            }
        }
    }

    public function withSignature(array $signature): self
    {
        $validated = self::validateSignature($signature);
        if ($validated === null) {
            throw new SecurityException('Signature payload is empty.');
        }

        return new self(
            $this->format,
            $this->entry,
            $this->name,
            $this->createdAt,
            $this->files,
            $this->classmap,
            $validated
        );
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeSignature = true): array
    {
        $data = [
            'format' => $this->format,
            'entry' => $this->entry,
            'files' => $this->files,
        ];

        if ($this->name !== '') {
            $data['name'] = $this->name;
        }

        if ($this->createdAt !== '') {
            $data['created_at'] = $this->createdAt;
        }

        if ($this->classmap !== []) {
            $data['classmap'] = $this->classmap;
        }

        if ($includeSignature && $this->signature !== null) {
            $data['signature'] = $this->signature;
        }

        return $data;
    }

    public function toJson(bool $includeSignature = true): string
    {
        return CanonicalJson::encode($this->toArray($includeSignature));
    }

    /** Deterministic bytes covered by the Ed25519 signature. */
    public function canonicalPayload(): string
    {
        return CanonicalJson::encode($this->toArray(false));
    }

    /** @throws SecurityException */
    private static function safePath(string $path, string $context): string
    {
        try {
            return PathGuard::normalizeRelative($path);
        } catch (SecurityException) {
            throw new SecurityException('Unsafe path in manifest (' . $context . ').');
        }
    }

    /** @throws SecurityException */
    private static function validateName(string $name): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new SecurityException('Manifest name contains control characters.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new SecurityException('Manifest name exceeds the maximum length.');
        }

        return trim($name);
    }

    /**
     * @param array<string, array<string, mixed>> $files
     * @return array<string, string>
     */
    private static function validateClassmap(mixed $raw, array $files): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new SecurityException('Manifest classmap is malformed.');
        }

        if (count($raw) > self::MAX_CLASSMAP) {
            throw new SecurityException('Manifest classmap is too large.');
        }

        $classmap = [];
        foreach ($raw as $class => $target) {
            if (!is_string($class) || preg_match(self::CLASS_PATTERN, $class) !== 1 || strlen($class) > 255) {
                throw new SecurityException('Manifest classmap contains an invalid class name.');
            }

            if (!is_string($target)) {
                throw new SecurityException('Manifest classmap target must be a string.');
            }

            $path = self::safePath($target, 'classmap target');

            if (!isset($files[$path]) || $files[$path]['mode'] !== 'encrypted') {
                throw new SecurityException('Manifest classmap target is not an encrypted payload.');
            }

            $classmap[$class] = $path;
        }

        return $classmap;
    }

    /** @return array{alg: string, public_key: string, sig: string, kid?: string}|null */
    private static function validateSignature(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!is_array($raw) || array_is_list($raw)) {
            throw new SecurityException('Manifest signature block is malformed.');
        }

        $algorithm = $raw['alg'] ?? null;
        if (!is_string($algorithm) || $algorithm !== 'ed25519') {
            throw new SecurityException('Unsupported signature algorithm.');
        }

        $publicKey = $raw['public_key'] ?? null;
        $signature = $raw['sig'] ?? null;

        if (!is_string($publicKey) || !is_string($signature)) {
            throw new SecurityException('Manifest signature fields are missing.');
        }

        $decodedKey = base64_decode($publicKey, true);
        $decodedSig = base64_decode($signature, true);

        if ($decodedKey === false || strlen($decodedKey) !== 32
            || $decodedSig === false || strlen($decodedSig) !== 64) {
            throw new SecurityException('Manifest signature material is malformed.');
        }

        $result = ['alg' => 'ed25519', 'public_key' => $publicKey, 'sig' => $signature];

        if (array_key_exists('kid', $raw)) {
            if (!is_string($raw['kid']) || strlen($raw['kid']) > 64
                || preg_match('/[\x00-\x1F\x7F]/', $raw['kid']) === 1) {
                throw new SecurityException('Manifest signature key id is malformed.');
            }
            $result['kid'] = $raw['kid'];
        }

        return $result;
    }
}
