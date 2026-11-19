<?php
/**
 * GeDefense WPR artifact admin controller.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Admin;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\ConflictException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Support\PathGuard;
use VGT\Omega\System\Vault\VaultFormat;
use VGT\Omega\System\Vault\VaultManager;

/**
 * WordPress admin-post HTTP adapter for the vault.
 *
 * This is the only class that reads superglobals: capability check, nonce,
 * per-user rate limit and strict upload validation happen here, then the typed
 * domain call runs. Every failure is converted through ExceptionReporter, so a
 * SecurityException, StorageException or AvailabilityException detail can never
 * reach the browser — only the generic client sentence plus an opaque
 * correlation reference. The redirect always leaves the admin-post endpoint.
 */
final class ArtifactController
{
    public const NOTICE_TRANSIENT_PREFIX = 'vgt_notice_';

    private const CAPABILITY = 'activate_plugins';

    private const NONCE_ACTION = 'vgt_action';

    private const INSTALL_ACTION = 'vgt_install_artifact';

    private const DELETE_ACTION = 'vgt_delete_artifact';

    private const RATE_LIMIT = 20;

    private const RATE_WINDOW = 3600;

    private const RATE_PREFIX = 'vgt_rate_';

    private const NOTICE_TTL = 300;

    private const MAX_POST_LENGTH = 4096;

    private static ?BridgeInterface $bridge = null;

    public static function register(BridgeInterface $bridge): void
    {
        self::$bridge = $bridge;

        $bridge->addAction('admin_post_' . self::INSTALL_ACTION, [self::class, 'handleInstall']);
        $bridge->addAction('admin_post_' . self::DELETE_ACTION, [self::class, 'handleDelete']);
    }

    /** Handle a package deployment or a zero-downtime rotation. */
    public static function handleInstall(): void
    {
        // Authorization and CSRF are gates, not domain errors: a failed gate
        // must never be converted into an admin notice (fail closed, 403).
        self::guard();

        try {
            self::enforceRateLimit();

            $uploadPath = self::validatedUploadPath();
            $cloudKeyHex = self::postString('license_key');
            $customName = self::postString('artifact_name');
            $rotateRaw = self::postString('rotate_id');
            $rotateId = $rotateRaw === '' ? null : self::validateArtifactId($rotateRaw);

            if ($cloudKeyHex === '') {
                throw new ValidationException('Enter the artifact decryption key.');
            }

            $result = VaultManager::installer()->install($uploadPath, $cloudKeyHex, $customName, $rotateId);

            self::storeNotice(
                'success',
                $result['status'] === 'rotated'
                    ? 'Artifact rotated successfully (zero-downtime swap completed).'
                    : 'Artifact installed and sealed in the vault.',
                ''
            );
        } catch (ValidationException $error) {
            self::storeFailure($error, 'artifact-install');
        } catch (ConflictException | SecurityException | StorageException | AvailabilityException $error) {
            self::storeFailure($error, 'artifact-install');
        } catch (\Throwable $error) {
            self::storeFailure($error, 'artifact-install');
        }

        self::redirectToConsole();
    }

    /** Handle permanent artifact removal. */
    public static function handleDelete(): void
    {
        self::guard();

        try {
            self::enforceRateLimit();

            $rawId = self::postString('artifact_id');

            if ($rawId === '') {
                throw new ValidationException('No artifact was selected for removal.');
            }

            $artifactId = self::validateArtifactId($rawId);
            VaultManager::installer()->delete($artifactId);

            self::storeNotice('success', 'Artifact and public mirror were permanently removed.', '');
        } catch (ValidationException $error) {
            self::storeFailure($error, 'artifact-delete');
        } catch (ConflictException | SecurityException | StorageException | AvailabilityException $error) {
            self::storeFailure($error, 'artifact-delete');
        } catch (\Throwable $error) {
            self::storeFailure($error, 'artifact-delete');
        }

        self::redirectToConsole();
    }

    /**
     * Consume the one-shot admin notice of the current user.
     *
     * @return array{type: string, message: string, reference: string}|null
     */
    public static function pullNotice(): ?array
    {
        $key = self::noticeKey();

        if ($key === null) {
            return null;
        }

        $raw = self::cacheGet($key);
        self::cacheDelete($key);

        if (!is_array($raw)) {
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? $raw['type'] : '';
        $message = is_string($raw['message'] ?? null) ? self::sanitizeLine($raw['message']) : '';
        $reference = is_string($raw['reference'] ?? null) ? $raw['reference'] : '';

        if (!in_array($type, ['success', 'error', 'warning', 'info'], true) || $message === '') {
            return null;
        }

        if (preg_match('/^VGT-ERR-[A-F0-9]{8}$/', $reference) !== 1) {
            $reference = '';
        }

        return ['type' => $type, 'message' => $message, 'reference' => $reference];
    }

    /** Capability and CSRF gate for every admin-post action. */
    private static function guard(): void
    {
        if (!function_exists('current_user_can') || !\current_user_can(self::CAPABILITY)) {
            self::deny();
        }

        if (!function_exists('check_admin_referer')) {
            throw new SecurityException('CSRF nonce verification is unavailable on this host.');
        }

        \check_admin_referer(self::NONCE_ACTION);
    }

    private static function deny(): never
    {
        $message = 'Access denied.';

        if (function_exists('wp_die')) {
            \wp_die($message, '', ['response' => 403]);
            exit;
        }

        http_response_code(403);
        exit;
    }

    /** Per-user sliding-hour rate limit for mutating vault operations. */
    private static function enforceRateLimit(): void
    {
        $userId = function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;

        if ($userId <= 0) {
            throw new SecurityException('Vault operations require an authenticated user.');
        }

        $key = self::RATE_PREFIX . $userId;
        $now = time();
        $state = self::cacheGet($key);
        $count = 0;
        $start = $now;

        if (is_array($state)
            && is_int($state['count'] ?? null)
            && is_int($state['start'] ?? null)
            && $state['start'] > 0
            && ($now - $state['start']) < self::RATE_WINDOW
        ) {
            $count = $state['count'];
            $start = $state['start'];
        }

        $count++;

        if ($count > self::RATE_LIMIT) {
            throw new AvailabilityException('Vault operation rate limit exceeded. Please wait before retrying.');
        }

        if (!self::cacheSet($key, ['count' => $count, 'start' => $start], self::RATE_WINDOW + 60)) {
            error_log('[VGT][ADMIN] Vault rate-limit state could not be persisted.');
        }
    }

    /** @throws ValidationException|SecurityException when the upload is unusable */
    private static function validatedUploadPath(): string
    {
        if (!is_array($_FILES) || !array_key_exists('artifact', $_FILES)) {
            throw new ValidationException('No artifact package was selected.');
        }

        $upload = $_FILES['artifact'];

        if (!is_array($upload)) {
            throw new ValidationException('The uploaded package is malformed.');
        }

        $temporaryPath = $upload['tmp_name'] ?? null;
        $errorCode = $upload['error'] ?? null;

        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_int($errorCode)) {
            throw new ValidationException('The uploaded package is malformed.');
        }

        if ($errorCode !== \UPLOAD_ERR_OK) {
            throw new ValidationException(self::uploadErrorMessage($errorCode));
        }

        if (function_exists('is_uploaded_file') && !\is_uploaded_file($temporaryPath)) {
            throw new SecurityException('The uploaded package failed its upload-origin verification.');
        }

        clearstatcache(true, $temporaryPath);

        if (is_link($temporaryPath) || !is_file($temporaryPath)) {
            throw new ValidationException('The uploaded package could not be read.');
        }

        // Never trust $_FILES['size']: the real size is measured on the temp file.
        $size = @filesize($temporaryPath);

        if ($size === false || $size < 1) {
            throw new ValidationException('The uploaded package is empty.');
        }

        if ($size > VaultFormat::MAX_ARCHIVE_BYTES) {
            throw new ValidationException('The package exceeds the maximum allowed size.');
        }

        return $temporaryPath;
    }

    private static function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'The package exceeds the server upload size limit.',
            \UPLOAD_ERR_PARTIAL => 'The package was only partially uploaded. Please retry.',
            \UPLOAD_ERR_NO_FILE => 'No artifact package was uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory available.',
            \UPLOAD_ERR_CANT_WRITE => 'The server could not store the uploaded package on disk.',
            \UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'The package upload failed.',
        };
    }

    /** @throws ValidationException|SecurityException */
    private static function postString(string $key): string
    {
        $post = is_array($_POST) ? $_POST : [];

        if (!array_key_exists($key, $post)) {
            return '';
        }

        $value = $post[$key];

        if (!is_string($value)) {
            throw new ValidationException('Invalid form input received.');
        }

        if (function_exists('wp_unslash')) {
            $value = \wp_unslash($value);
        }

        $value = trim($value);

        if (strlen($value) > self::MAX_POST_LENGTH) {
            throw new ValidationException('Form input exceeds the permitted length.');
        }

        return $value;
    }

    /** @throws SecurityException */
    private static function validateArtifactId(string $rawId): string
    {
        PathGuard::assertArtifactId($rawId);

        return $rawId;
    }

    private static function storeFailure(\Throwable $error, string $scope): void
    {
        $client = ExceptionReporter::forClient($error, $scope);
        self::storeNotice('error', $client['message'], $client['reference']);
    }

    private static function storeNotice(string $type, string $message, string $reference): void
    {
        $key = self::noticeKey();

        if ($key === null) {
            return;
        }

        $notice = [
            'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
            'message' => self::sanitizeLine($message),
            'reference' => preg_match('/^VGT-ERR-[A-F0-9]{8}$/', $reference) === 1 ? $reference : '',
        ];

        if (!self::cacheSet($key, $notice, self::NOTICE_TTL)) {
            error_log('[VGT][ADMIN] Admin notice could not be stored.');
        }
    }

    private static function noticeKey(): ?string
    {
        $userId = function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;

        return $userId > 0 ? self::NOTICE_TRANSIENT_PREFIX . $userId : null;
    }

    private static function cacheGet(string $key): mixed
    {
        if (function_exists('get_transient')) {
            $value = \get_transient($key);

            return $value === false ? null : $value;
        }

        if (self::$bridge instanceof BridgeInterface) {
            return self::$bridge->getCache($key);
        }

        return null;
    }

    private static function cacheSet(string $key, array $value, int $ttl): bool
    {
        if (function_exists('set_transient')) {
            return (bool) \set_transient($key, $value, $ttl);
        }

        if (self::$bridge instanceof BridgeInterface) {
            return self::$bridge->setCache($key, $value, $ttl);
        }

        return false;
    }

    private static function cacheDelete(string $key): void
    {
        if (function_exists('delete_transient')) {
            \delete_transient($key);

            return;
        }

        if (self::$bridge instanceof BridgeInterface) {
            self::$bridge->setCache($key, '', 1);
        }
    }

    private static function redirectToConsole(): void
    {
        if (function_exists('admin_url') && function_exists('wp_safe_redirect')) {
            \wp_safe_redirect(\admin_url('admin.php?page=vgt-console'));
        }

        exit;
    }

    private static function sanitizeLine(string $message): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $clean = trim($clean);

        return function_exists('mb_substr') ? mb_substr($clean, 0, 300) : substr($clean, 0, 300);
    }
}
