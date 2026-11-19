<?php
/**
 * GeDefense WPR WordPress bridge adapter.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Adapters;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;

/**
 * Hardened WordPress implementation of the universal bridge.
 *
 * Hardening: recursive input sanitization with a hard depth limit, control
 * character stripping, URL scheme allowlist for outbound HTTP, immutable
 * virtual-plugin state and menu sandboxing without inline executable markup.
 */
final class WordPressAdapter implements BridgeInterface
{
    private const MAX_INPUT_DEPTH = 32;

    public function __construct(private readonly EnvironmentInterface $environment)
    {
    }

    public function addAction(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return (bool) \add_action($tag, $callback, $priority, $acceptedArgs);
    }

    public function doAction(string $tag, mixed ...$args): void
    {
        \do_action($tag, ...$args);
    }

    public function addFilter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return (bool) \add_filter($tag, $callback, $priority, $acceptedArgs);
    }

    public function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return \apply_filters($tag, $value, ...$args);
    }

    public function getState(string $key, array|string|int|float|bool|null $default = false): array|string|int|float|bool|null
    {
        return \get_option($key, $default);
    }

    public function setState(string $key, array|string|int|float|bool $value, bool $autoload = true): bool
    {
        return (bool) \update_option($key, $value, $autoload ? 'yes' : 'no');
    }

    public function deleteState(string $key): bool
    {
        return (bool) \delete_option($key);
    }

    public function setCache(string $key, array|string|int|float|bool $value, int $exp = 0): bool
    {
        return (bool) \set_transient($key, $value, $exp);
    }

    public function getCache(string $key): array|string|int|float|bool|null
    {
        $value = \get_transient($key);

        return $value === false ? null : $value;
    }

    public function addMenuMain(string $pTitle, string $mTitle, string $cap, string $slug, callable $cb, string $icon = '', ?int $pos = null): string|false
    {
        $safeSlug = $this->safeMenuSlug($slug);
        $icon = preg_match('/^(dashicons-[a-z0-9-]+|data:image\/[a-z+]+;base64,[A-Za-z0-9+\/=]+)$/', $icon) === 1
            ? $icon
            : 'dashicons-superhero';

        return \add_menu_page(
            $pTitle,
            $mTitle,
            $cap,
            $safeSlug,
            $this->sandboxCallback($cb),
            $icon,
            $pos
        );
    }

    public function addMenuSub(string $pSlug, string $pTitle, string $mTitle, string $cap, string $slug, callable $cb): string|false
    {
        return \add_submenu_page(
            $this->safeMenuSlug($pSlug),
            $pTitle,
            $mTitle,
            $cap,
            $this->safeMenuSlug($slug),
            $this->sandboxCallback($cb)
        );
    }

    public function enqueueJs(string $handle, string $src, array $deps = [], bool|string $version = false, bool $inFooter = false): void
    {
        \wp_enqueue_script($handle, $src, $deps, $version, $inFooter);
    }

    public function enqueueCss(string $handle, string $src, array $deps = [], bool|string $version = false, string $media = 'all'): void
    {
        \wp_enqueue_style($handle, $src, $deps, $version, $media);
    }

    public function sendSuccess(array|string|int|float|bool|null $data = null): never
    {
        \wp_send_json_success($data);
        exit;
    }

    public function sendError(array|string|int|float|bool|null $data = null): never
    {
        \wp_send_json_error($data);
        exit;
    }

    public function httpGet(string $url, array $args = []): array
    {
        return $this->mapHttpResponse(\wp_remote_get($this->assertHttpUrl($url), $this->httpDefaults($args)));
    }

    public function httpPost(string $url, array $args = []): array
    {
        return $this->mapHttpResponse(\wp_remote_post($this->assertHttpUrl($url), $this->httpDefaults($args)));
    }

    public function isAdmin(): bool
    {
        return \is_admin();
    }

    public function getDbPrefix(): string
    {
        return $this->environment->getDbPrefix();
    }

    public function createNonce(string $action): string
    {
        return \wp_create_nonce($action);
    }

    public function verifyNonce(string $nonce, string $action): int|false
    {
        return \wp_verify_nonce($nonce, $action);
    }

    public function queryString(string $key, array|string $default = ''): array|string
    {
        $value = $this->fetchUnslashed($key, 'GET');

        return $value !== null ? $this->sanitizeRecursive($value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->fetchUnslashed($key, 'GET');

        return is_scalar($value) ? \absint((string) $value) : $default;
    }

    public function bodyString(string $key, array|string $default = ''): array|string
    {
        $value = $this->fetchUnslashed($key, 'POST');

        return $value !== null ? $this->sanitizeRecursive($value) : $default;
    }

    public function bodyInt(string $key, int $default = 0): int
    {
        $value = $this->fetchUnslashed($key, 'POST');

        return is_scalar($value) ? \absint((string) $value) : $default;
    }

    /**
     * @param array<string|int, mixed> $pool
     */
    private function fetchUnslashed(string $key, string $method): mixed
    {
        $pool = $method === 'POST' ? $_POST : $_GET;

        if (!is_array($pool) || !array_key_exists($key, $pool)) {
            return null;
        }

        return \wp_unslash($pool[$key]);
    }

    /** @param array<string|int, mixed> $data */
    private function sanitizeRecursive(array $data, int $depth = 0): array
    {
        if ($depth > self::MAX_INPUT_DEPTH) {
            throw new SecurityException('Input nesting exceeds the security limit.');
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $safeKey = $this->sanitizeScalar((string) $key);
            if ($safeKey === '') {
                continue;
            }

            $sanitized[$safeKey] = is_array($value)
                ? $this->sanitizeRecursive($value, $depth + 1)
                : $this->sanitizeScalar($value);
        }

        return $sanitized;
    }

    private function sanitizeScalar(mixed $value): string
    {
        if ($value === null || is_array($value) || is_resource($value)) {
            return '';
        }

        if (is_object($value) && !method_exists($value, '__toString')) {
            return '';
        }

        $text = $this->stripControlChars((string) $value);
        $text = \sanitize_text_field($text);

        return trim($text);
    }

    private function stripControlChars(string $value): string
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            for ($i = 0; $i <= 31; $i++) {
                if (!in_array($i, [9, 10, 13], true)) {
                    $map[chr($i)] = '';
                }
            }
            $map[chr(127)] = '';
        }

        return strtr($value, $map);
    }

    /** @param array<string, mixed> $args */
    private function httpDefaults(array $args): array
    {
        $args += [
            'timeout' => 15,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
        ];

        return $args;
    }

    private function assertHttpUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SecurityException('Outbound URL scheme rejected.');
        }

        return $url;
    }

    private function safeMenuSlug(string $slug): string
    {
        $clean = preg_replace('/[^a-z0-9_\-]/', '-', strtolower($slug));
        $clean = trim((string) $clean, '-');

        if ($clean === '') {
            throw new StorageException('Invalid admin menu slug.');
        }

        return substr($clean, 0, 64);
    }

    private function sandboxCallback(callable $callback): callable
    {
        return static function () use ($callback): void {
            echo '<div class="vgt-artifact-sandbox">';
            $callback();
            echo '</div>';
        };
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array{is_error: bool, error_message: string, code: int, body: string, headers: array<string, string>}
     */
    private function mapHttpResponse(array|\WP_Error $response): array
    {
        if (\is_wp_error($response)) {
            return [
                'is_error' => true,
                'error_message' => (string) $response->get_error_message(),
                'code' => 500,
                'body' => '',
                'headers' => [],
            ];
        }

        $rawHeaders = \wp_remote_retrieve_headers($response);
        $headers = [];

        if (is_iterable($rawHeaders)) {
            foreach ($rawHeaders as $name => $value) {
                $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return [
            'is_error' => false,
            'error_message' => '',
            'code' => (int) \wp_remote_retrieve_response_code($response),
            'body' => (string) \wp_remote_retrieve_body($response),
            'headers' => $headers,
        ];
    }
}
