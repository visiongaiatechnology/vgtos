<?php
/**
 * GeDefense WPR sovereign key derivation.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Crypto;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * HKDF-SHA256 dual-factor key derivation (FIND-003).
 *
 * master = HKDF-SHA256(
 *   pepper (32 random bytes, provisioned once, stored as option)
 *   || AUTH_KEY || SECURE_AUTH_KEY || LOGGED_IN_KEY || NONCE_KEY
 *   || binding mode || node fingerprint,
 *   info = "vgt-omega-4.2-master"
 * )
 *
 * Per-artifact keys are domain-separated with the artifact id:
 *   artifactKey = HKDF-SHA256(master, info = "vgt-artifact|<id>")
 *
 * SOFT_BIND (default) deliberately carries no host-specific fingerprint so a
 * hoster storage migration, inode change or domain move can never brick the
 * vault. STRICT_HW_BIND optionally re-binds the master key to OS, CPU, ABSPATH
 * inode and database identity for dedicated servers.
 *
 * No key is ever persisted. Only the random pepper is stored (as hex in an
 * option); the WordPress salts stay in wp-config.php.
 */
final class KeyDerivation
{
    public const OPTION_PEPPER = 'vgt_vault_pepper';

    public const MODE_SOFT = 'soft';

    public const MODE_STRICT = 'strict';

    private ?string $masterCache = null;

    public function __construct(
        private readonly EnvironmentInterface $environment,
        private readonly BridgeInterface $bridge
    ) {
    }

    /** Effective binding mode: soft (default) or strict. */
    public function bindingMode(): string
    {
        $configured = defined('VGT_OMEGA_BINDING_MODE')
            ? strtolower(trim((string) constant('VGT_OMEGA_BINDING_MODE')))
            : self::MODE_SOFT;

        return $configured === self::MODE_STRICT ? self::MODE_STRICT : self::MODE_SOFT;
    }

    public function fingerprint(): string
    {
        if ($this->bindingMode() === self::MODE_SOFT) {
            return 'soft';
        }

        $components = [
            php_uname('s'),
            php_uname('m'),
            (string) (@fileinode($this->environment->getAbspath()) ?: 'inode-unavailable'),
            defined('DB_NAME') ? (string) constant('DB_NAME') : 'db-name-unavailable',
            defined('DB_HOST') ? (string) constant('DB_HOST') : 'db-host-unavailable',
        ];

        return hash('sha256', 'vgt-strict|' . implode('|', $components));
    }

    /** @throws AvailabilityException when WordPress salts are not configured */
    public function masterKey(): string
    {
        if ($this->masterCache !== null) {
            return $this->masterCache;
        }

        $secret = $this->pepper();

        $salts = $this->environment->getSalts();
        $material = [];

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $name) {
            $value = $salts[$name] ?? '';
            if (is_string($value) && $value !== '') {
                $material[] = $value;
            }
        }

        if ($material === []) {
            if ($this->environment->getPlatformName() === 'wordpress') {
                throw new AvailabilityException(
                    'WordPress security salts are not configured; refusing to derive a weak vault key.'
                );
            }

            // Non-WordPress runtimes derive from their stable installation identity.
            $material[] = hash(
                'sha256',
                'vgt-standalone|' . $this->environment->getSiteUrl() . '|' . $this->environment->getAbspath()
            );
        }

        $material[] = $this->bindingMode();
        $material[] = $this->fingerprint();

        $master = hash_hkdf(
            'sha256',
            $secret . "\0" . implode("\0", $material),
            32,
            'vgt-omega-4.2-master'
        );

        $this->masterCache = $master;

        return $master;
    }

    /** @throws AvailabilityException */
    public function artifactKey(string $artifactId): string
    {
        PathGuard::assertArtifactId($artifactId);

        return hash_hkdf('sha256', $this->masterKey(), 32, 'vgt-artifact|' . $artifactId);
    }

    /**
     * Provision (once) and return the 32-byte pepper.
     *
     * The read-back after the write makes the provisioning race-safe: if two
     * concurrent requests generate different peppers, both continue with the
     * value that is actually persisted.
     *
     * @throws StorageException when the option layer cannot persist the pepper
     */
    public function pepper(): string
    {
        $stored = $this->bridge->getState(self::OPTION_PEPPER);

        if (is_string($stored) && preg_match('/^[a-f0-9]{64}$/', $stored) === 1) {
            return (string) hex2bin($stored);
        }

        $fresh = bin2hex(random_bytes(32));
        $this->bridge->setState(self::OPTION_PEPPER, $fresh, false);

        $readBack = $this->bridge->getState(self::OPTION_PEPPER);

        if (!is_string($readBack) || preg_match('/^[a-f0-9]{64}$/', $readBack) !== 1) {
            throw new StorageException('Vault pepper could not be persisted. Refusing to encrypt.');
        }

        return (string) hex2bin($readBack);
    }

    /**
     * Non-secret diagnostics view.
     *
     * @return array{mode: string, fingerprint: string, pepper_present: bool, salts_present: int}
     */
    public function describe(): array
    {
        $salts = $this->environment->getSalts();
        $present = 0;
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $name) {
            if (is_string($salts[$name] ?? '') && ($salts[$name] ?? '') !== '') {
                $present++;
            }
        }

        return [
            'mode' => $this->bindingMode(),
            'fingerprint' => substr(hash('sha256', $this->fingerprint()), 0, 16),
            'pepper_present' => $this->bridge->getState(self::OPTION_PEPPER) !== false,
            'salts_present' => $present,
        ];
    }
}
