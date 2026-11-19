<?php
/**
 * GeDefense WPR standalone bridge adapter.
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
 * Dependency-free bridge for CLI tooling, tests and non-CMS embeddings.
 *
 * It implements the complete hook, filter, state, cache and nonce contract
 * deterministically in memory (optionally persisted to a JSON file), which
 * makes the whole vault domain testable without WordPress.
 */
final class StandaloneAdapter implements BridgeInterface
{
    /** @var array<string, array<int, array{0: callable, 1: int}>> */
    private array $actions = [];

    /** @var array<string, array<int, array{0: callable, 1: int}>> */
    private array $filters = [];

    /** @var array<string, array|string|int|float|bool> */
    private array $state = [];

    /** @var array<string, array|string|int|float|bool> */
    private array $cache = [];

    /** @var list<array{type: string, handle: string, src: string}> */
    private array $assets = [];

    private string $nonceSecret;

    public function __construct(
        private readonly EnvironmentInterface $environment,
        private readonly ?string $stateFile = null
    ) {
        $this->nonceSecret = hash('sha256', 'vgt-standalone|' . $environment->getAbspath());
        $this->loadState();
    }

    public function addAction(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $this->actions[$tag][$priority][] = [$callback, $acceptedArgs];

        return true;
    }

    public function doAction(string $tag, mixed ...$args): void
    {
        if (!isset($this->actions[$tag])) {
            return;
        }

        ksort($this->actions[$tag]);

        foreach ($this->actions[$tag] as $listeners) {
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
        return $this->state[$key] ?? $default;
    }

    public function setState(string $key, array|string|int|float|bool $value, bool $autoload = true): bool
    {
        $this->state[$key] = $value;
        $this->persistState();

        return true;
    }

    public function deleteState(string $key): bool
    {
        if (!array_key_exists($key, $this->state)) {
            return false;
        }

        unset($this->state[$key]);
        $this->persistState();

        return true;
    }

    public function setCache(string $key, array|string|int|float|bool $value, int $exp = 0): bool
    {
        $this->cache[$key] = $value;

        return true;
    }

    public function getCache(string $key): array|string|int|float|bool|null
    {
        return $this->cache[$key] ?? null;
    }

    public function addMenuMain(string $pTitle, string $mTitle, string $cap, string $slug, callable $cb, string $icon = '', ?int $pos = null): string|false
    {
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
        throw new \RuntimeException('VGT Standalone: sendSuccess is only available in an HTTP host.');
    }

    public function sendError(array|string|int|float|bool|null $data = null): never
    {
        throw new \RuntimeException('VGT Standalone: sendError is only available in an HTTP host.');
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
        return false;
    }

    public function getDbPrefix(): string
    {
        return $this->environment->getDbPrefix();
    }

    public function createNonce(string $action): string
    {
        return hash_hmac('sha256', $action, $this->nonceSecret);
    }

    public function verifyNonce(string $nonce, string $action): int|false
    {
        $expected = $this->createNonce($action);

        return hash_equals($expected, $nonce) ? 1 : false;
    }

    public function queryString(string $key, array|string $default = ''): array|string
    {
        $value = $_GET[$key] ?? null;

        return $value !== null ? $this->sanitize($value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $_GET[$key] ?? null;

        return is_scalar($value) ? abs((int) $value) : $default;
    }

    public function bodyString(string $key, array|string $default = ''): array|string
    {
        $value = $_POST[$key] ?? null;

        return $value !== null ? $this->sanitize($value) : $default;
    }

    public function bodyInt(string $key, int $default = 0): int
    {
        $value = $_POST[$key] ?? null;

        return is_scalar($value) ? abs((int) $value) : $default;
    }

    /** @return list<array{type: string, handle: string, src: string}> */
    public function collectedAssets(): array
    {
        return $this->assets;
    }

    /** @return array<string, array|string|int|float|bool> */
    public function exportState(): array
    {
        return $this->state;
    }

    /** @return array<string, mixed> */
    private function httpRequest(string $method, string $url, array $args): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SecurityException('Outbound URL scheme rejected.');
        }

        $timeout = isset($args['timeout']) && is_numeric($args['timeout']) ? (float) $args['timeout'] : 15.0;
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'max_redirects' => 3,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return ['is_error' => true, 'error_message' => 'Request failed.', 'code' => 500, 'body' => '', 'headers' => []];
        }

        return ['is_error' => false, 'error_message' => '', 'code' => 200, 'body' => $body, 'headers' => []];
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

    private function loadState(): void
    {
        if ($this->stateFile === null || !is_file($this->stateFile)) {
            return;
        }

        $json = @file_get_contents($this->stateFile);
        if (!is_string($json) || $json === '') {
            return;
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key) && (is_array($value) || is_scalar($value))) {
                    $this->state[$key] = $value;
                }
            }
        }
    }

    private function persistState(): void
    {
        if ($this->stateFile === null) {
            return;
        }

        $json = json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = $this->stateFile . '.tmp.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new StorageException('Standalone state could not be persisted.');
        }

        if (!@rename($temp, $this->stateFile)) {
            @unlink($temp);
            throw new StorageException('Standalone state could not be published atomically.');
        }
    }
}
