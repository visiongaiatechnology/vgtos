<?php
/**
 * GeDefense WPR standalone environment.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Environments;

use VGT\Omega\Bridge\Contracts\EnvironmentInterface;

/**
 * Deterministic, dependency-free environment for CLI tooling and unit tests.
 * Never used in production requests.
 */
final class StandaloneEnvironment implements EnvironmentInterface
{
    /**
     * @param array<string, string>|null $salts
     */
    public function __construct(
        private readonly string $baseDir,
        private readonly string $siteUrl = 'http://localhost',
        private readonly ?array $salts = null,
        private readonly string $documentRoot = '',
        private readonly bool $ssl = false
    ) {
    }

    public function getPlatformName(): string
    {
        return 'standalone';
    }

    public function getDbPrefix(): string
    {
        return 'wp_';
    }

    public function getAbspath(): string
    {
        return rtrim(str_replace('\\', '/', $this->baseDir), '/') . '/';
    }

    public function getContentDir(): string
    {
        return $this->getAbspath() . 'wp-content/';
    }

    public function getUploadDir(): string
    {
        return $this->getContentDir() . 'uploads/';
    }

    public function getUploadBaseUrl(): string
    {
        return rtrim($this->siteUrl, '/') . '/wp-content/uploads';
    }

    public function getSiteUrl(): string
    {
        return rtrim($this->siteUrl, '/');
    }

    public function getDocumentRoot(): string
    {
        return rtrim(str_replace('\\', '/', $this->documentRoot), '/');
    }

    /** @return array<string, string> */
    public function getSalts(): array
    {
        return $this->salts ?? [
            'AUTH_KEY' => 'vgt-test-auth-key-0123456789abcdef',
            'SECURE_AUTH_KEY' => 'vgt-test-secure-auth-key-0123456789',
            'LOGGED_IN_KEY' => 'vgt-test-logged-in-key-0123456789',
            'NONCE_KEY' => 'vgt-test-nonce-key-0123456789abcdef',
        ];
    }

    public function isMultisite(): bool
    {
        return false;
    }

    public function isSsl(): bool
    {
        return $this->ssl;
    }
}
