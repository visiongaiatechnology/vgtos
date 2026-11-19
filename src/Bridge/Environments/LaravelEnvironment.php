<?php
/**
 * VGT Omega Laravel environment.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Environments;

use VGT\Omega\Bridge\Contracts\EnvironmentInterface;

/**
 * Laravel host implementation. Every framework call is guarded so the adapter
 * fails with a typed exception instead of a fatal error when the framework
 * contracts are not booted.
 */
final class LaravelEnvironment implements EnvironmentInterface
{
    public function getPlatformName(): string
    {
        return 'laravel';
    }

    public function getDbPrefix(): string
    {
        if (class_exists(\Illuminate\Support\Facades\DB::class)) {
            try {
                return (string) \Illuminate\Support\Facades\DB::getTablePrefix();
            } catch (\Throwable) {
                // Fall through to the conventional default.
            }
        }

        return 'wp_';
    }

    public function getAbspath(): string
    {
        return rtrim($this->normalize($this->basePath()), '/') . '/';
    }

    public function getContentDir(): string
    {
        return rtrim($this->normalize($this->basePath()), '/') . '/storage/framework/vgt-content/';
    }

    public function getUploadDir(): string
    {
        return rtrim($this->normalize($this->basePath()), '/') . '/storage/app/public/';
    }

    public function getUploadBaseUrl(): string
    {
        return rtrim($this->siteUrl(), '/') . '/storage';
    }

    public function getSiteUrl(): string
    {
        return rtrim($this->siteUrl(), '/');
    }

    public function getDocumentRoot(): string
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($docRoot) || $docRoot === '') {
            return '';
        }

        $real = realpath($docRoot);

        return $real === false ? '' : rtrim($this->normalize($real), '/');
    }

    /** @return array<string, string> */
    public function getSalts(): array
    {
        $appKey = $this->configString('app.key');

        if ($appKey === '') {
            return [];
        }

        $appKey = str_starts_with($appKey, 'base64:')
            ? (string) base64_decode(substr($appKey, 7), true)
            : $appKey;

        return [
            'AUTH_KEY' => hash_hmac('sha256', 'auth', $appKey),
            'SECURE_AUTH_KEY' => hash_hmac('sha256', 'secure-auth', $appKey),
            'LOGGED_IN_KEY' => hash_hmac('sha256', 'logged-in', $appKey),
            'NONCE_KEY' => hash_hmac('sha256', 'nonce', $appKey),
        ];
    }

    public function isMultisite(): bool
    {
        return false;
    }

    public function isSsl(): bool
    {
        if (function_exists('request') && $this->appBound()) {
            try {
                return (bool) request()->isSecure();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function appBound(): bool
    {
        return function_exists('app') && is_object(app());
    }

    private function basePath(): string
    {
        if ($this->appBound()) {
            try {
                $path = app()->basePath();
                if (is_string($path) && $path !== '') {
                    return $path;
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return dirname(__DIR__, 5);
    }

    private function siteUrl(): string
    {
        $configured = $this->configString('app.url');
        if ($configured !== '') {
            return $configured;
        }

        if ($this->appBound()) {
            try {
                return (string) url('/');
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return 'http://localhost';
    }

    private function configString(string $key): string
    {
        if (!function_exists('config') || !$this->appBound()) {
            return '';
        }

        try {
            $value = config($key);
        } catch (\Throwable) {
            return '';
        }

        return is_string($value) ? $value : '';
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
