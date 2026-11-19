<?php
/**
 * GeDefense WPR asset URL resolver.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Contracts\ArtifactStoreInterface;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Translates vgt:// asset references into browser-visible URLs.
 *
 * Only intentional public assets are HTTP-servable. A manifest file with mode
 * "encrypted" stays encrypted on disk; there is no decrypting HTTP handler and
 * no inline fallback, so resolve() returns '' for it. Raw cleartext assets are
 * mirrored by the installer; this resolver asks the store for the stable public
 * URL and never builds a filesystem path itself.
 *
 * Filter contract (registered via BridgeInterface::addFilter):
 * - plugins_url, plugin_dir_url (3 accepted args, priority 9999)
 * - style_loader_src, script_loader_src (2 accepted args, priority 9999)
 * - clean_url (1 accepted arg, priority 9999)
 *
 * A URL without a vgt:// reference is returned byte-identical. A URL that
 * references an unknown artifact, an undeclared file, a traversal attempt or
 * an encrypted payload resolves to '' (fail-closed, no half-trusted URL).
 */
final class AssetResolver
{
    private const SCHEME = 'vgt://';

    public function __construct(
        private readonly ManifestRegistry $registry,
        private readonly ArtifactStoreInterface $store
    ) {
    }

    /** Attach the resolver to the host URL filters. */
    public function registerFilters(BridgeInterface $bridge): void
    {
        $bridge->addFilter('plugins_url', [$this, 'rewritePluginUrl'], 9999, 3);
        $bridge->addFilter('plugin_dir_url', [$this, 'rewritePluginUrl'], 9999, 3);
        $bridge->addFilter('style_loader_src', [$this, 'sanitizeAssetUrl'], 9999, 2);
        $bridge->addFilter('script_loader_src', [$this, 'sanitizeAssetUrl'], 9999, 2);
        $bridge->addFilter('clean_url', [$this, 'sanitizeAssetUrl'], 9999, 1);
    }

    /**
     * plugins_url / plugin_dir_url filter callback.
     *
     * When the plugin argument references a virtual artifact plugin file, the
     * requested relative path is joined to the virtual plugin directory and
     * resolved as a public asset.
     */
    public function rewritePluginUrl(string $url, ?string $path = null, ?string $plugin = null): string
    {
        $path = $path ?? '';
        $plugin = $plugin ?? '';
        $hasVirtualPlugin = $plugin !== '' && stripos($plugin, self::SCHEME) !== false;
        $hasVirtualUrl = stripos($url, self::SCHEME) !== false;

        if (!$hasVirtualPlugin && !$hasVirtualUrl) {
            return $url;
        }

        if ($hasVirtualPlugin) {
            $base = $this->decodeVirtual(rawurldecode($plugin));

            if ($base !== null && $path !== '') {
                $directory = dirname($base['rel']);
                $joined = $this->joinRelative($directory === '.' ? '' : $directory, $path);

                if ($joined !== null) {
                    $resolved = $this->resolve(self::SCHEME . $base['id'] . '/' . $joined);

                    if ($resolved !== '') {
                        return $resolved;
                    }
                }
            }

            return $this->resolve($plugin);
        }

        return $this->resolve($url);
    }

    /**
     * style_loader_src / script_loader_src / clean_url filter callback.
     *
     * Keeps every non-virtual URL untouched; virtual URLs fail closed to ''.
     */
    public function sanitizeAssetUrl(string $src, string $handle = ''): string
    {
        if ($src === '') {
            return '';
        }

        if (stripos($src, self::SCHEME) === false && stripos(rawurldecode($src), self::SCHEME) === false) {
            return $src;
        }

        return $this->resolve($src);
    }

    /**
     * Resolve one URL that may contain a vgt:// reference.
     *
     * @return string stable public URL, or '' when not servable
     */
    public function resolve(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (stripos($value, self::SCHEME) === false && stripos(rawurldecode($value), self::SCHEME) === false) {
            return $value;
        }

        $decoded = rawurldecode($value);
        [$base, $suffix] = self::splitSuffix($decoded);
        $parsed = $this->decodeVirtual($base);

        if ($parsed === null || $parsed['rel'] === '') {
            return '';
        }

        $resolved = $this->resolveDeclaredAsset($parsed['id'], $parsed['rel']);

        if ($resolved === '') {
            return '';
        }

        return $suffix === '' ? $resolved : $resolved . $suffix;
    }

    /**
     * @return array{id: string, rel: string}|null
     */
    private function decodeVirtual(string $value): ?array
    {
        $matches = [];

        if (preg_match('~vgt://([^/\s"\'<>&]+)(/([^\s"\'<>&?#]*))?~i', $value, $matches) !== 1) {
            return null;
        }

        $artifactId = $matches[1];
        $relative = $matches[3] ?? '';

        if (!PathGuard::isValidArtifactId($artifactId)) {
            return null;
        }

        if ($relative === '') {
            return ['id' => $artifactId, 'rel' => ''];
        }

        try {
            return ['id' => $artifactId, 'rel' => PathGuard::normalizeRelative($relative)];
        } catch (SecurityException) {
            return null;
        }
    }

    /**
     * Return the public URL of a manifest-declared raw public asset.
     *
     * @return string public URL, or '' when the file is encrypted/undeclared/not mirrored
     */
    private function resolveDeclaredAsset(string $artifactId, string $relativePath): string
    {
        if (!$this->registry->has($artifactId)) {
            return '';
        }

        $spec = $this->registry->get($artifactId)['manifest']->file($relativePath);

        if ($spec === null || ($spec['mode'] ?? '') !== 'raw') {
            // Encrypted payloads are not HTTP-servable by design.
            return '';
        }

        if (!VaultFormat::isPublicAssetPath($relativePath) || VaultFormat::isExecutablePath($relativePath)) {
            return '';
        }

        $url = $this->store->publicAssetUrl($artifactId, $relativePath);

        return is_string($url) ? $url : '';
    }

    /** Join a request path onto a virtual directory, rejecting traversal. */
    private function joinRelative(string $directory, string $path): ?string
    {
        try {
            $normalizedPath = PathGuard::normalizeRelative($path);
        } catch (SecurityException) {
            return null;
        }

        $combined = $directory === '' ? $normalizedPath : $directory . '/' . $normalizedPath;

        try {
            return PathGuard::normalizeRelative($combined);
        } catch (SecurityException) {
            return null;
        }
    }

    /**
     * Split a URL into its path part and a trailing query/fragment suffix.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitSuffix(string $value): array
    {
        $query = strpos($value, '?');
        $fragment = strpos($value, '#');
        $candidates = [];

        if ($query !== false) {
            $candidates[] = $query;
        }

        if ($fragment !== false) {
            $candidates[] = $fragment;
        }

        if ($candidates === []) {
            return [$value, ''];
        }

        $cut = min($candidates);

        return [substr($value, 0, $cut), substr($value, $cut)];
    }
}
