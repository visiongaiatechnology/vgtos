<?php
/**
 * GeDefense WPR PSR-4 autoloader.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bootstrap;

/**
 * O(1) cached PSR-4 autoloader for the "VGT\Omega\" prefix.
 *
 * Hostile, dynamically constructed class names can never make the autoloader
 * scan arbitrary files: the class name is allowlisted and the resolved file is
 * realpath-jailed inside the src/ directory.
 */
final class Autoloader
{
    private const PREFIX = 'VGT\\Omega\\';
    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** @var array<string, string> resolved class => absolute file */
    private static array $map = [];

    private static string $srcDir = '';

    private static bool $registered = false;

    public static function register(string $projectDir): void
    {
        if (self::$registered) {
            return;
        }

        $base = rtrim(str_replace('\\', '/', $projectDir), '/') . '/src/';
        $real = realpath($base);

        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException('VGT Bootstrap: src/ directory is not readable.');
        }

        self::$srcDir = rtrim(str_replace('\\', '/', $real), '/') . '/';
        spl_autoload_register([self::class, 'load'], true, true);
        self::$registered = true;
    }

    public static function load(string $class): void
    {
        if (isset(self::$map[$class])) {
            $cached = self::$map[$class];
            if (is_file($cached)) {
                require_once $cached;
            }

            return;
        }

        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $candidate = self::$srcDir . str_replace('\\', '/', $relative) . '.php';

        if (!is_file($candidate)) {
            return;
        }

        $resolved = realpath($candidate);
        if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved), self::$srcDir)) {
            return;
        }

        self::$map[$class] = $resolved;
        require_once $resolved;
    }

    /** Test/diagnostics helper: number of classes resolved in this request. */
    public static function resolvedCount(): int
    {
        return count(self::$map);
    }
}
