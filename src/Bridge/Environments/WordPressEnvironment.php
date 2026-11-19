<?php
/**
 * VGT Omega WordPress environment.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Environments;

use VGT\Omega\Bridge\Contracts\EnvironmentInterface;

/**
 * WordPress host implementation. All host globals are read exactly once here.
 */
final class WordPressEnvironment implements EnvironmentInterface
{
    public function getPlatformName(): string
    {
        return 'wordpress';
    }

    public function getDbPrefix(): string
    {
        global $wpdb;

        return isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
    }

    public function getAbspath(): string
    {
        return $this->normalizePath(defined('ABSPATH') ? (string) constant('ABSPATH') : '') . '/';
    }

    public function getContentDir(): string
    {
        $dir = defined('WP_CONTENT_DIR') ? (string) constant('WP_CONTENT_DIR') : $this->getAbspath() . 'wp-content';

        return $this->normalizePath($dir) . '/';
    }

    public function getUploadDir(): string
    {
        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir(null, false);
            if (is_array($upload) && isset($upload['basedir']) && is_string($upload['basedir'])) {
                return $this->normalizePath($upload['basedir']) . '/';
            }
        }

        return $this->getContentDir() . 'uploads/';
    }

    public function getUploadBaseUrl(): string
    {
        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir(null, false);
            if (is_array($upload) && isset($upload['baseurl']) && is_string($upload['baseurl'])) {
                return rtrim($upload['baseurl'], '/');
            }
        }

        return rtrim($this->getSiteUrl(), '/') . '/wp-content/uploads';
    }

    public function getSiteUrl(): string
    {
        if (function_exists('home_url')) {
            $url = home_url('/');
            if (is_string($url) && $url !== '') {
                return rtrim($url, '/');
            }
        }

        if (defined('WP_HOME')) {
            return rtrim((string) constant('WP_HOME'), '/');
        }

        return '';
    }

    public function getDocumentRoot(): string
    {
        $candidates = [
            $_SERVER['DOCUMENT_ROOT'] ?? '',
            defined('VGT_OMEGA_DOCUMENT_ROOT') ? (string) constant('VGT_OMEGA_DOCUMENT_ROOT') : '',
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $real = realpath($candidate);
            if ($real !== false && is_dir($real)) {
                return rtrim($this->normalizePath($real), '/');
            }
        }

        return '';
    }

    /** @return array<string, string> */
    public function getSalts(): array
    {
        $salts = [];

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $name) {
            if (defined($name)) {
                $value = (string) constant($name);
                if ($value !== '') {
                    $salts[$name] = $value;
                }
            }
        }

        return $salts;
    }

    public function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public function isSsl(): bool
    {
        return function_exists('is_ssl') && is_ssl();
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return rtrim($normalized, '/');
    }
}
