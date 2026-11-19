<?php
/**
 * GeDefense WPR dependency injection container.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Core;

/**
 * Immutable, type-safe service container.
 *
 * The container rejects duplicate registrations (service hijacking), refuses
 * deserialization (PHP object injection) and resolves lazy factories exactly
 * once per request.
 */
final class Container
{
    /** @var array<string, object> */
    private array $services = [];

    /** @var array<string, callable> */
    private array $factories = [];

    private static ?self $instance = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new \BadMethodCallException('VGT Security: Deserialization of the DI container is forbidden.');
    }

    public function __unserialize(array $data): void
    {
        throw new \BadMethodCallException('VGT Security: Deserialization of the DI container is forbidden.');
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function set(string $id, callable|object $concrete): void
    {
        if (isset($this->services[$id]) || isset($this->factories[$id])) {
            throw new \RuntimeException(sprintf(
                'VGT Security: Service "%s" is already registered and immutable.',
                $id
            ));
        }

        // Closures are lazy factories. A plain object (including invokable
        // services) is registered as an already constructed singleton. This
        // distinction keeps provider factories from being stored as services.
        if ($concrete instanceof \Closure) {
            $this->factories[$id] = $concrete;

            return;
        }

        if (is_object($concrete)) {
            $this->services[$id] = $concrete;

            return;
        }

        $this->factories[$id] = $concrete;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|object
     */
    public function get(string $id): object
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (isset($this->factories[$id])) {
            $instance = ($this->factories[$id])($this);

            if (!is_object($instance)) {
                throw new \UnexpectedValueException(sprintf(
                    'VGT Logic Error: Factory for "%s" must return an object.',
                    $id
                ));
            }

            $this->services[$id] = $instance;
            unset($this->factories[$id]);

            return $this->services[$id];
        }

        throw new \OutOfBoundsException(sprintf('VGT Security: Service "%s" not found.', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->factories[$id]);
    }
}
