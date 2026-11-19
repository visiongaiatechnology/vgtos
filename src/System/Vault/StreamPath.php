<?php
/**
 * VGT Omega virtual path parsing.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Crypto\Manifest;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Strict parser for vgt:// URIs and synthetic directory listings.
 *
 * Every path that reaches the stream wrapper passes through this class. The
 * artifact id must match PathGuard, control bytes and query/fragment syntax are
 * rejected, and the relative path must survive PathGuard::normalizeRelative()
 * (no traversal, overlong segments, Windows drive or ADS syntax, and so on).
 * Directory listings are derived exclusively from the authenticated manifest
 * file table; the physical blobs/ directory is never exposed.
 */
final class StreamPath
{
    public const SCHEME = 'vgt://';

    private const SCHEME_LENGTH = 6;

    /**
     * Parse a virtual URI into artifact id and normalized relative path.
     *
     * @return array{id: string, rel: string}|null null when the URI is invalid
     */
    public static function parse(string $uri): ?array
    {
        if (strlen($uri) < self::SCHEME_LENGTH + 1 || stripos($uri, self::SCHEME) !== 0) {
            return null;
        }
        $rest = substr($uri, self::SCHEME_LENGTH);
        if ($rest === '' || str_contains($rest, "\0")) {
            return null;
        }
        $slash = strpos($rest, '/');
        if ($slash === false) {
            $artifactId = $rest;
            $relative = '';
        } else {
            $artifactId = substr($rest, 0, $slash);
            $relative = substr($rest, $slash + 1);
        }
        if (!PathGuard::isValidArtifactId($artifactId)) {
            return null;
        }
        if ($relative === '') {
            return ['id' => $artifactId, 'rel' => ''];
        }
        if (str_contains($relative, '?') || str_contains($relative, '#')) {
            return null;
        }
        try {
            return ['id' => $artifactId, 'rel' => PathGuard::normalizeRelative($relative)];
        } catch (SecurityException) {
            return null;
        }
    }

    /**
     * Synthesize the entries of a virtual directory from the manifest file table.
     *
     * @return list<string>|null entry names, or null when the directory does not exist
     */
    public static function directoryEntries(Manifest $manifest, string $directory): ?array
    {
        $prefix = $directory === '' ? '' : $directory . '/';
        $names = [];
        foreach (array_keys($manifest->files()) as $filePath) {
            if (!str_starts_with($filePath, $prefix)) {
                continue;
            }
            $rest = substr($filePath, strlen($prefix));
            if ($rest === '') {
                continue;
            }
            $slash = strpos($rest, '/');
            $name = $slash === false ? $rest : substr($rest, 0, $slash);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $names[$name] = true;
        }
        if ($names === []) {
            return null;
        }
        $list = array_keys($names);
        sort($list, SORT_STRING);
        return $list;
    }
}
