<?php
/**
 * VGT Omega layer-0 MU kernel deployer.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Deployment;

use VGT\Omega\Core\Error\ExceptionReporter;

/**
 * Deploys the generated VGT Omega MU kernel into the WordPress mu-plugins
 * directory so the vault runtime boots at layer 0, before regular plugins.
 *
 * Hardening:
 * - Only a user with the manage_options capability may trigger a deployment
 *   (checked when the WordPress function exists).
 * - The file is only replaced when its SHA-256 differs from the generated
 *   payload; an unchanged kernel is never touched.
 * - Writes go through a unique temporary sibling with LOCK_EX, then chmod 0644,
 *   then rename() so a request can never load a half-written PHP file. Every
 *   failure is logged instead of silently swallowed, and the published file is
 *   verified against the expected hash after the rename.
 * - The generated payload uses var_export() for the plugin path (no direct
 *   interpolation) and is proven parseable by the project's lint gate.
 */
final class MuDeployer
{
    public const CAPABILITY = 'manage_options';

    private const MAX_PAYLOAD_BYTES = 65536;

    private const FILE_MODE = 0644;

    private const DIRECTORY_MODE = 0755;

    /** Deploy (or refresh) the layer-0 kernel for the current installation. */
    public static function deploy(): void
    {
        if (!self::isAuthorized()) {
            return;
        }

        $pluginFile = defined('VGT_OMEGA_FILE') ? (string) constant('VGT_OMEGA_FILE') : '';

        if ($pluginFile === '') {
            self::logFailure('Layer-0 kernel not deployed: VGT_OMEGA_FILE is not defined.');
            return;
        }

        $version = defined('VGT_OMEGA_VERSION')
            ? (string) constant('VGT_OMEGA_VERSION')
            : MuKernelPayload::DEFAULT_VERSION;

        try {
            $payload = MuKernelPayload::build($pluginFile, $version);
        } catch (\Throwable $error) {
            ExceptionReporter::report($error, 'mu-payload');
            return;
        }

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            self::logFailure('Layer-0 kernel payload exceeds the size budget.');
            return;
        }

        $target = self::targetPath();

        if ($target === '') {
            // Not a WordPress filesystem (CLI tooling, tests): nothing to deploy.
            return;
        }

        $expectedHash = hash('sha256', $payload);

        if (is_file($target)) {
            $installedHash = @hash_file('sha256', $target);

            if (is_string($installedHash) && hash_equals($expectedHash, $installedHash)) {
                return;
            }
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            self::logFailure('Layer-0 directory is not creatable.');
            return;
        }

        $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $written = @file_put_contents($temporary, $payload, LOCK_EX);

        if ($written === false || $written !== strlen($payload)) {
            @unlink($temporary);
            self::logFailure('Layer-0 kernel temporary write failed.');
            return;
        }

        @chmod($temporary, self::FILE_MODE);

        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            self::logFailure('Layer-0 kernel atomic rename failed.');
            return;
        }

        $publishedHash = @hash_file('sha256', $target);

        if (!is_string($publishedHash) || !hash_equals($expectedHash, $publishedHash)) {
            self::logFailure('Layer-0 kernel verification after publish failed.');
        }
    }

    /**
     * Absolute target path of the generated MU kernel, or '' outside WordPress.
     */
    public static function targetPath(): string
    {
        $directory = '';

        if (defined('WPMU_PLUGIN_DIR')) {
            $candidate = constant('WPMU_PLUGIN_DIR');

            if (is_string($candidate) && $candidate !== '') {
                $directory = $candidate;
            }
        }

        if ($directory === '' && defined('WP_CONTENT_DIR')) {
            $candidate = constant('WP_CONTENT_DIR');

            if (is_string($candidate) && $candidate !== '') {
                $directory = rtrim(str_replace('\\', '/', $candidate), '/') . '/mu-plugins';
            }
        }

        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        if ($directory === '' || preg_match('/[\x00-\x1F\x7F]/', $directory) === 1) {
            return '';
        }

        return $directory . '/' . MuKernelPayload::FILENAME;
    }

    /**
     * Capability boundary. Outside a WordPress request there is no admin user
     * boundary to enforce; deployment then still requires the WordPress
     * directory constants, so no file is written by a bare CLI invocation.
     */
    private static function isAuthorized(): bool
    {
        if (function_exists('current_user_can')) {
            return (bool) current_user_can(self::CAPABILITY);
        }

        return true;
    }

    /** Internal failure sink: log a single sanitized line, never throw. */
    private static function logFailure(string $message): void
    {
        error_log('[VGT][MU] ' . ExceptionReporter::sanitizeLogMessage($message));
    }
}
