<?php
/**
 * GeDefense WPR environment contract.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Contracts;

/**
 * Isolates every host-system detail (paths, URLs, salts, platform flags)
 * from the vault domain. Implementations exist for WordPress, Laravel and a
 * dependency-free standalone runtime so that the complete core can be unit
 * tested without booting a CMS.
 */
interface EnvironmentInterface
{
    /** Host platform identifier, e.g. "wordpress", "laravel", "standalone". */
    public function getPlatformName(): string;

    /** Database table prefix of the host application. */
    public function getDbPrefix(): string;

    /** Absolute, slash-normalized filesystem root of the host application (with trailing slash). */
    public function getAbspath(): string;

    /** Absolute, slash-normalized content directory (with trailing slash). */
    public function getContentDir(): string;

    /** Absolute, slash-normalized uploads directory (with trailing slash). */
    public function getUploadDir(): string;

    /** Public base URL of the uploads directory (without trailing slash). */
    public function getUploadBaseUrl(): string;

    /** Canonical site URL (without trailing slash). */
    public function getSiteUrl(): string;

    /** Filesystem document root of the web server, or '' when unknown. */
    public function getDocumentRoot(): string;

    /**
     * Secret key material used for sovereign key derivation.
     *
     * @return array<string, string> e.g. AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY
     */
    public function getSalts(): array;

    public function isMultisite(): bool;

    public function isSsl(): bool;
}
