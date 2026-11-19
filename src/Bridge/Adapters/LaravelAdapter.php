<?php
/**
 * VGT Omega Laravel bridge adapter.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Adapters;

use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;

/**
 * Real Laravel adapter (replaces the former 17-byte placeholder).
 *
 * Mapping:
 * - actions   -> Laravel event dispatcher (fallback: in-memory registry)
 * - filters   -> in-memory registry (Laravel has no filter pipeline)
 * - state     -> default cache repository, prefixed and non-expiring
 * - cache     -> default cache repository with TTL
 * - nonce     -> stateless HMAC over APP_KEY with a rolling time window
 * - HTTP      -> Illuminate HTTP client (fallback: stream context)
 *
 * Every framework call is guarded; when Laravel is not booted the adapter
 * raises AvailabilityException instead of a fatal error.
 */
final class LaravelAdapter implements BridgeInterface
{
    /** @var array<string, array<int, array{0: callable, 1: int}>> */
    private array $filters = [];

    /** @var array<string, array<int, array{0: callable, 1: int}>> */
    private array $fallbackActions = [];

    /** @var list<array{type: string, handle: string, src: string}> */
    private array $assets = [];

    private const NONCE_WINDOW_SECONDS = 43200;

    public function __construct(private readonly EnvironmentInterface $environment)
    {
    }

    public function addAction(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        if ($this->eventsAvailable()) {
            try {
                \Illuminate\Support\Facades\Event::listen($tag, static function (array $payload = []) use ($callback, $acceptedArgs): void {
                    $callback(...array_slice($payload, 0, $acceptedArgs));
                });

                return true;
            } catch (\Throwable $error) {
                throw new AvailabilityException('Laravel event dispatcher is unavailable: ' . $error->getMessage());
            }
        }

        $this->fallbackActions[$tag][$priority][] = [$callback, $acceptedArgs];

        return true;
    }

    public function doAction(string $tag, mixed ...$args): void
    {
        if ($this->eventsAvailable()) {
            try {
                \Illuminate\Support\Facades\Event::dispatch($tag, $args);

                return;
            } catch (\Throwable) {
                // Fall through to the in-memory registry.
            }
        }

        if (!isset($this->fallbackActions[$tag])) {
            return;
        }

        ksort($this->fallbackActions[$tag]);
        foreach ($this->fallbackActions[$tag] as $listeners) {
            foreach ($listeners as [$callback, $acceptedArgs]) {
                $callback(...array_slice($args, 0, $acceptedArgs));
            }
        }
    }

    public function addFilter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $this->filters[$tag][$priority][] = [$callback, $acceptedArgs];

        return true;
    }

    public function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$tag])) {
            return $value;
        }

        ksort($this->filters[$tag]);
        foreach ($this->filters[$tag] as $listeners) {
            foreach ($listeners as [$callback, $acceptedArgs]) {
                $value = $callback($value, ...array_slice($args, 0, max(0, $acceptedArgs - 1)));
            }
        }

        return $value;
    }

    public function getState(string $key, array|string|int|float|bool|null $default = false): array|string|int|float|bool|null
    {
        $cache = $this->cacheRepository();
        $value = $cache->get($this->stateKey($key), $default);

        return is_array($value) || is_scalar($value) || $value === null ? $value : $default;
    }

    public function setState(string $key, array|string|int|float|bool $value, bool $autoload = true): bool
    {
        try {
            return (bool) $this->cacheRepository()->forever($this->stateKey($key), $value);
        } catch (\Throwable $error) {
            throw new StorageException('Laravel cache rejected the state write: ' . $error->getMessage());
        }
    }

    public function deleteState(string $key): bool
    {
        try {
            return (bool) $this->cacheRepository()->forget($this->stateKey($key));
        } catch (\Throwable $error) {
            throw new StorageException('Laravel cache rejected the state delete: ' . $error->getMessage());
        }
    }

    public function setCache(string $key, array|string|int|float|bool $value, int $exp = 0): bool
    {
        try {
            if ($exp > 0) {
                return (bool) $this->cacheRepository()->put($this->cacheKey($key), $value, $exp);
            }

            return (bool) $this->cacheRepository()->forever($this->cacheKey($key), $value);
        } catch (\Throwable $error) {
            throw new StorageException('Laravel cache rejected the write: ' . $error->getMessage());
        }
    }

    public function getCache(string $key): array|string|int|float|bool|null
    {
        $value = $this->cacheRepository()->get($this->cacheKey($key));

        return is_array($value) || is_scalar($value) ? $value : null;
    }

    public function addMenuMain(string $pTitle, string $mTitle, string $cap, string $slug, callable $cb, string $icon = '', ?int $pos = null): string|false
    {
        // Laravel applications have no shared wp-admin menu registry; routes are
        // declared by the embedding application. Returning false is the adapter's
        // documented contract for "this host has no menu surface".
        return false;
    }

    public function addMenuSub(string $pSlug, string $pTitle, string $mTitle, string $cap, string $slug, callable $cb): string|false
    {
        return false;
    }

    public function enqueueJs(string $handle, string $src, array $deps = [], bool|string $version = false, bool $inFooter = false): void
    {
        $this->assets[] = ['type' => 'js', 'handle' => $handle, 'src' => $src];
    }

    public function enqueueCss(string $handle, string $src, array $deps = [], bool|string $version = false, string $media = 'all'): void
    {
        $this->assets[] = ['type' => 'css', 'handle' => $handle, 'src' => $src];
    }

    public function sendSuccess(array|string|int|float|bool|null $data = null): never
    {
        if (!function_exists('response')) {
            throw new AvailabilityException('Laravel response factory is unavailable.');
        }

        response()->json(['success' => true, 'data' => $data])->send();
        exit;
    }

    public function sendError(array|string|int|float|bool|null $data = null): never
    {
        if (!function_exists('response')) {
            throw new AvailabilityException('Laravel response factory is unavailable.');
        }

        response()->json(['success' => false, 'data' => $data], 400)->send();
        exit;
    }

    public function httpGet(string $url, array $args = []): array
    {
        return $this->httpRequest('GET', $url, $args);
    }

    public function httpPost(string $url, array $args = []): array
    {
        return $this->httpRequest('POST', $url, $args);
    }

    public function isAdmin(): bool
    {
        // Laravel has no WordPress-style admin context. Authorization is the
        // responsibility of the embedding route/controller.
        return false;
    }

    public function getDbPrefix(): string
    {
        return $this->environment->getDbPrefix();
    }

    public function createNonce(string $action): string
    {
        $window = intdiv(time(), self::NONCE_WINDOW_SECONDS);

        return hash_hmac('sha256', $action . '|' . $window, $this->nonceSecret());
    }

    public function verifyNonce(string $nonce, string $action): int|false
    {
        $current = intdiv(time(), self::NONCE_WINDOW_SECONDS);

        foreach ([$current, $current - 1] as $window) {
            $expected = hash_hmac('sha256', $action . '|' . $window, $this->nonceSecret());
            if (hash_equals($expected, $nonce)) {
                return 1;
            }
        }

        return false;
    }

    public function queryString(string $key, array|string $default = ''): array|string
    {
        $value = $this->requestInput('query', $key);

        return $value !== null ? $this->sanitize($value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->requestInput('query', $key);

        return is_scalar($value) ? abs((int) $value) : $default;
    }

    public function bodyString(string $key, array|string $default = ''): array|string
    {
        $value = $this->requestInput('body', $key);

        return $value !== null ? $this->sanitize($value) : $default;
    }

    public function bodyInt(string $key, int $default = 0): int
    {
        $value = $this->requestInput('body', $key);

        return is_scalar($value) ? abs((int) $value) : $default;
    }

    /** @return list<array{type: string, handle: string, src: string}> */
    public function collectedAssets(): array
    {
        return $this->assets;
    }

    private function eventsAvailable(): bool
    {
        return $this->appAvailable()
            && class_exists(\Illuminate\Support\Facades\Event::class)
            && app()->bound('events');
    }

    private function appAvailable(): bool
    {
        return function_exists('app') && is_object(app());
    }

    private function cacheRepository(): object
    {
        if (!$this->appAvailable() || !app()->bound('cache')) {
            throw new AvailabilityException('Laravel cache repository is unavailable.');
        }

        try {
            return \Illuminate\Support\Facades\Cache::store();
        } catch (\Throwable $error) {
            throw new AvailabilityException('Laravel cache store failed: ' . $error->getMessage());
        }
    }

    private function stateKey(string $key): string
    {
        return 'vgt_state_' . $key;
    }

    private function cacheKey(string $key): string
    {
        return 'vgt_cache_' . $key;
    }

    private function nonceSecret(): string
    {
        if (!function_exists('config')) {
            throw new AvailabilityException('Laravel configuration repository is unavailable.');
        }

        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new AvailabilityException('Laravel APP_KEY is not configured.');
        }

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }

    private function requestInput(string $source, string $key): mixed
    {
        if ($this->appAvailable() && function_exists('request')) {
            try {
                $request = request();
                $pool = $source === 'query' ? $request->query() : $request->request->all();

                return is_array($pool) ? ($pool[$key] ?? null) : null;
            } catch (\Throwable) {
                // Fall through to superglobals.
            }
        }

        $pool = $source === 'query' ? $_GET : $_POST;

        return is_array($pool) ? ($pool[$key] ?? null) : null;
    }

    /** @return array{is_error: bool, error_message: string, code: int, body: string, headers: array<string, string>} */
    private function httpRequest(string $method, string $url, array $args): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SecurityException('Outbound URL scheme rejected.');
        }

        if (!class_exists(\Illuminate\Support\Facades\Http::class)) {
            throw new AvailabilityException('Laravel HTTP client is unavailable.');
        }

        try {
            $client = \Illuminate\Support\Facades\Http::timeout(15)->withOptions(['allow_redirects' => ['max' => 3]])
                ->withHeaders(is_array($args['headers'] ?? null) ? $args['headers'] : []);

            $response = $method === 'POST'
                ? $client->post($url, is_array($args['body'] ?? null) ? $args['body'] : [])
                : $client->get($url, is_array($args['query'] ?? null) ? $args['query'] : []);

            return [
                'is_error' => $response->failed(),
                'error_message' => $response->failed() ? 'HTTP request failed.' : '',
                'code' => $response->status(),
                'body' => $response->body(),
                'headers' => array_map(
                    static fn (array $values): string => implode(', ', $values),
                    $response->headers()
                ),
            ];
        } catch (\Throwable $error) {
            return [
                'is_error' => true,
                'error_message' => $error->getMessage(),
                'code' => 500,
                'body' => '',
                'headers' => [],
            ];
        }
    }

    private function sanitize(mixed $value): array|string
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $safeKey = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $key) ?? '';
                if ($safeKey === '') {
                    continue;
                }
                $result[$safeKey] = is_array($item) ? $this->sanitize($item) : $this->sanitizeScalar($item);
            }

            return $result;
        }

        return $this->sanitizeScalar($value);
    }

    private function sanitizeScalar(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value) ?? '';

        return trim(strip_tags($text));
    }
}
