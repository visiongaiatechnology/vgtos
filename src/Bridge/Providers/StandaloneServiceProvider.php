<?php
/**
 * GeDefense WPR standalone service provider.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Providers;

use VGT\Omega\Bridge\Adapters\StandaloneAdapter;
use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Bridge\Contracts\ServiceProviderInterface;
use VGT\Omega\Bridge\Core\Container;
use VGT\Omega\Bridge\Environments\StandaloneEnvironment;

/**
 * Registers the dependency-free host integration used by CLI tooling and tests.
 */
final class StandaloneServiceProvider implements ServiceProviderInterface
{
    public function __construct(
        private readonly StandaloneEnvironment $environment,
        private readonly ?string $stateFile = null
    ) {
    }

    public function register(Container $container): void
    {
        $environment = $this->environment;
        $stateFile = $this->stateFile;

        $container->set(EnvironmentInterface::class, $environment);
        $container->set(
            BridgeInterface::class,
            static fn (): BridgeInterface => new StandaloneAdapter($environment, $stateFile)
        );
    }
}
