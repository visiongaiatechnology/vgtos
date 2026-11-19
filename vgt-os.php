<?php
/**
 * Plugin Name:       GeDefense WPR
 * Plugin URI:        https://visiongaiatechnology.de
 * Description:       GeDefense WPR — sovereign encrypted artifact vault, universal host bridge and Glass 2100 security console for WordPress. Part of the GeDefense security series. Loads AES-256-GCM artifacts from RAM only.
 * Version:           4.2.3
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            VisionGaia Technology
 * Author URI:        https://visiongaiatechnology.de
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       ge-defense-wpr
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (C) 2026 VisionGaiaTechnology
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file for the complete license text.
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit('VGT_ACCESS_DENIED');
}

require_once __DIR__ . '/src/Bootstrap/Autoloader.php';
\VGT\Omega\Bootstrap\Autoloader::register(__DIR__);
\VGT\Omega\Bootstrap\Plugin::boot(__FILE__);
