<?php
/**
 * VGT Omega artifact classmap autoloader.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault;

use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\AvailabilityException;
use VGT\Omega\System\Vault\Support\PathGuard;

/**
 * Loads classes declared in the classmap of a mounted artifact on demand.
 *
 * The manifest classmap is an explicit allowlist: a requested class is only
 * ever resolved when the exact class name is present in it. There is no
 * directory or file scanning, no PSR-4 guessing and no path concatenation from
 * the requested class name. Every include goes through the vgt:// stream
 * wrapper, so the payload file itself was authenticated when it was mounted.
 *
 * Registry state is checked on every load: ArtifactMounter forgets an artifact
 * whose entry point failed to execute, which immediately disables its classes.
 * If two mounted artifacts declare the same class, the first registration wins;
 * a later artifact can never shadow an already authenticated class.
 */
final class ClassmapAutoloader
{
    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const MAX_CLASS_LENGTH = 255;

    /** @var array<string, array{artifact: string, path: string}> allowlisted classes */
    private array $classmap = [];

    /** @var array<string, list<string>> artifact id => classes registered from it */
    private array $ownedClasses = [];

    public function __construct(private readonly ManifestRegistry $registry)
    {
        spl_autoload_register([$this, 'autoload']);
    }

    /**
     * Publish the classmap of a mounted artifact.
     *
     * @throws AvailabilityException when the artifact is not present in the registry
     */
    public function registerArtifact(string $artifactId): void
    {
        PathGuard::assertArtifactId($artifactId);

        if (isset($this->ownedClasses[$artifactId])) {
            return;
        }

        if (!$this->registry->has($artifactId)) {
            throw new AvailabilityException('Cannot publish a classmap for an unmounted artifact.');
        }

        $classmap = $this->registry->get($artifactId)['manifest']->classmap();

        if ($classmap === []) {
            return;
        }

        $classes = [];

        foreach ($classmap as $class => $relativePath) {
            if (!is_string($class)
                || strlen($class) > self::MAX_CLASS_LENGTH
                || preg_match(self::CLASS_PATTERN, $class) !== 1) {
                continue;
            }

            if (!is_string($relativePath) || $relativePath === '' || isset($this->classmap[$class])) {
                continue;
            }

            $this->classmap[$class] = ['artifact' => $artifactId, 'path' => $relativePath];
            $classes[] = $class;
        }

        $this->ownedClasses[$artifactId] = $classes;
    }

    /**
     * Withdraw the classmap of an artifact (mounter rollback after a failed load).
     */
    public function unregisterArtifact(string $artifactId): void
    {
        foreach ($this->ownedClasses[$artifactId] ?? [] as $class) {
            unset($this->classmap[$class]);
        }

        unset($this->ownedClasses[$artifactId]);
    }

    /**
     * spl_autoload_register callback.
     *
     * Never throws into another autoloader: a payload that fails to load is
     * logged internally and left unresolved (fail-closed, no partial class).
     */
    public function autoload(string $class): void
    {
        if ($class === ''
            || strlen($class) > self::MAX_CLASS_LENGTH
            || preg_match(self::CLASS_PATTERN, $class) !== 1) {
            return;
        }

        $target = $this->classmap[$class] ?? null;

        if (!is_array($target)) {
            return;
        }

        if (!$this->registry->has($target['artifact'])) {
            return;
        }

        try {
            include_once 'vgt://' . $target['artifact'] . '/' . $target['path'];
        } catch (\Throwable $error) {
            ExceptionReporter::report($error, 'artifact-classmap');
        }
    }
}
