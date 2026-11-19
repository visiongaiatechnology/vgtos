<?php
/**
 * VGT Omega reference packer CLI command.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Tooling;

use VGT\Omega\Core\Exceptions\AppException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;

/**
 * Minimal, dependency-free command line front-end for ArtifactPacker.
 */
final class PackCommand
{
    private const VERSION = '4.2.1';

    public function run(array $argv): int
    {
        $args = $this->parseArguments(array_slice($argv, 1));

        if (isset($args['help'])) {
            $this->printHelp();

            return 0;
        }

        if (isset($args['version'])) {
            fwrite(STDOUT, 'vgt-pack ' . self::VERSION . ' (' . \VGT\Omega\System\Vault\VaultFormat::SPEC_VERSION . ")\n");

            return 0;
        }

        if (isset($args['keygen'])) {
            return $this->keygen();
        }

        try {
            $source = $this->required($args, 'source');
            $out = $this->required($args, 'out');
            $key = $this->required($args, 'key');
            $entry = $args['entry'] ?? 'plugin.php';
            $name = $args['name'] ?? '';
            $signKey = $args['sign-key'] ?? null;
            $classmap = $this->loadClassmap($args['classmap'] ?? null);

            $packer = new ArtifactPacker();
            $result = $packer->pack($source, $out, $key, $entry, $name, $signKey, $classmap);

            fwrite(STDOUT, "VGT artifact packed\n");
            fwrite(STDOUT, '  format  : ' . $result['format'] . "\n");
            fwrite(STDOUT, '  files   : ' . $result['files'] . "\n");
            fwrite(STDOUT, '  signed  : ' . ($result['signed'] ? 'yes (Ed25519)' : 'no') . "\n");
            fwrite(STDOUT, '  package : ' . $result['zip'] . "\n");
            fwrite(STDOUT, '  size    : ' . $result['size'] . " bytes\n");
            fwrite(STDOUT, '  sha256  : ' . $result['sha256'] . "\n");
            fwrite(STDOUT, "\nUpload this .zip together with the cloud key in the VGT Console.\n");

            return 0;
        } catch (ValidationException $error) {
            fwrite(STDERR, 'VGT-PACK INPUT ERROR: ' . $error->getMessage() . "\n");

            return 2;
        } catch (SecurityException $error) {
            fwrite(STDERR, 'VGT-PACK SECURITY ERROR: package rejected (' . $error->getMessage() . ")\n");

            return 3;
        } catch (StorageException $error) {
            fwrite(STDERR, 'VGT-PACK STORAGE ERROR: ' . $error->getMessage() . "\n");

            return 4;
        } catch (AppException $error) {
            fwrite(STDERR, 'VGT-PACK ERROR: ' . $error->getMessage() . "\n");

            return 5;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'VGT-PACK FATAL: ' . $error->getMessage() . "\n");

            return 1;
        }
    }

    /** @return array<string, string|bool> */
    private function parseArguments(array $arguments): array
    {
        $parsed = [];

        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '') {
                continue;
            }

            if ($argument === '--help' || $argument === '-h') {
                $parsed['help'] = true;
                continue;
            }

            if ($argument === '--version' || $argument === '-V') {
                $parsed['version'] = true;
                continue;
            }

            if ($argument === '--keygen') {
                $parsed['keygen'] = true;
                continue;
            }

            if (!str_starts_with($argument, '--')) {
                continue;
            }

            $pair = explode('=', substr($argument, 2), 2);
            $name = $pair[0];
            $value = $pair[1] ?? true;

            if ($value === true) {
                continue;
            }

            $parsed[$name] = $value;
        }

        return $parsed;
    }

    /** @param array<string, string|bool> $args */
    private function required(array $args, string $name): string
    {
        $value = $args[$name] ?? null;

        if (!is_string($value) || $value === '') {
            throw new ValidationException('Missing required option --' . $name . '=<value>.');
        }

        return $value;
    }

    /** @return array<string, string> */
    private function loadClassmap(?string $path): array
    {
        if ($path === null || $path === '') {
            return [];
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new ValidationException('The classmap file is not readable.');
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new ValidationException('The classmap file could not be read.');
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('The classmap file is not valid JSON.');
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ValidationException('The classmap must be a JSON object of class => relative path.');
        }

        $classmap = [];
        foreach ($decoded as $class => $target) {
            if (!is_string($class) || !is_string($target)) {
                throw new ValidationException('The classmap contains non-string entries.');
            }
            $classmap[$class] = $target;
        }

        return $classmap;
    }

    private function keygen(): int
    {
        $cloudKey = bin2hex(random_bytes(32));
        fwrite(STDOUT, "VGT cloud key (AES-256, keep secret):\n" . $cloudKey . "\n\n");

        if (function_exists('sodium_crypto_sign_keypair')) {
            $keypair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($keypair);
            $public = sodium_crypto_sign_publickey($keypair);
            fwrite(STDOUT, "Ed25519 signing key (base64, keep the secret offline):\n");
            fwrite(STDOUT, '  secret: ' . base64_encode($secret) . "\n");
            fwrite(STDOUT, '  public: ' . base64_encode($public) . "\n");
        } else {
            fwrite(STDERR, "libsodium unavailable: no Ed25519 keypair generated.\n");
        }

        return 0;
    }

    private function printHelp(): void
    {
        $help = <<<'TXT'
VGT Omega reference encryptor (vgt-pack 4.2.1)
Format specification: ARTIFACT_SPEC.md

USAGE
  php bin/vgt-pack.php --source=<dir> --out=<package.zip> --key=<64-hex>
                       [--entry=plugin.php] [--name="Artifact Name"]
                       [--sign-key=<base64-ed25519-secret>] [--classmap=<map.json>]
  php bin/vgt-pack.php --keygen
  php bin/vgt-pack.php --help

OPTIONS
  --source     Directory that contains the plugin/artifact source (required)
  --out        Output .zip path (required)
  --key        32-byte cloud key as exactly 64 hex characters (required)
  --entry      Relative PHP entry point inside --source (default: plugin.php)
  --name       Display name inside the manifest (default: source directory name)
  --sign-key   Ed25519 secret key (base64, 64 bytes) to sign the manifest
  --classmap   JSON object {"Vendor\\Class":"src/File.php"} for lazy autoloading

EXIT CODES
  0 success | 1 fatal | 2 input error | 3 security rejection | 4 storage error

The resulting package is imported in the VGT Console. The cloud key only
protects the transport; after import the artifact is re-encrypted with the
server-bound HKDF key and the cloud key is discarded.
TXT;

        fwrite(STDOUT, $help . "\n");
    }
}
