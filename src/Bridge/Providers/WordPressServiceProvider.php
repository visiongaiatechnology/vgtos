<?php
/**
 * VGT Omega WordPress service provider.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Providers;

use VGT\Omega\Bridge\Adapters\WordPressAdapter;
use VGT\Omega\Bridge\Contracts\BridgeInterface;
use VGT\Omega\Bridge\Contracts\EnvironmentInterface;
use VGT\Omega\Bridge\Contracts\ServiceProviderInterface;
use VGT\Omega\Bridge\Core\Container;
use VGT\Omega\Bridge\Environments\WordPressEnvironment;

/**
 * Registers the WordPress host integration.
 */
final class WordPressServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(EnvironmentInterface::class, static fn (): EnvironmentInterface => new WordPressEnvironment());

        $container->set(
            BridgeInterface::class,
            static fn (Container $c): BridgeInterface => new WordPressAdapter(
                $c->get(EnvironmentInterface::class)
            )
        );
    }
}
