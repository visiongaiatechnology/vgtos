<?php
/**
 * VGT Omega service provider contract.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\Bridge\Contracts;

use VGT\Omega\Bridge\Core\Container;

/**
 * A provider registers exactly one host integration (WordPress, Laravel,
 * standalone) into the immutable service container.
 */
interface ServiceProviderInterface
{
    public function register(Container $container): void;
}
