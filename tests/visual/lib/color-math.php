<?php
/**
 * VGT Omega — shared colour math for the static audits.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

/** @return array{0: int, 1: int, 2: int} */
function rgb_from_hex(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** @param array{0: int, 1: int, 2: int} $rgb */
function hex_from_rgb(array $rgb): string
{
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

/** Source-over composite in sRGB space (matches simple CSS alpha blending). */
function blend(string $fgHex, float $alpha, string $bgHex): string
{
    $fg = rgb_from_hex($fgHex);
    $bg = rgb_from_hex($bgHex);
    $out = [];

    foreach ([0, 1, 2] as $i) {
        $out[$i] = (int) round(($alpha * $fg[$i]) + ((1 - $alpha) * $bg[$i]));
    }

    return hex_from_rgb($out);
}

function channel_luminance(int $value): float
{
    $c = $value / 255;

    return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
}

function luminance(string $hex): float
{
    $rgb = rgb_from_hex($hex);

    return 0.2126 * channel_luminance($rgb[0])
        + 0.7152 * channel_luminance($rgb[1])
        + 0.0722 * channel_luminance($rgb[2]);
}

function contrast_ratio(string $fgHex, string $bgHex): float
{
    $a = luminance($fgHex);
    $b = luminance($bgHex);
    $hi = max($a, $b);
    $lo = min($a, $b);

    return ($hi + 0.05) / ($lo + 0.05);
}

/**
 * Read the declared sRGB fallback tokens from the console stylesheet.
 *
 * @return array<string, array{hex: string, alpha: float|null}>
 */
function parse_css_tokens(string $css): array
{
    $tokens = [];

    if (preg_match_all('/--vgt-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,6}|rgba\([^)]+\));/', $css, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $value = trim($match[2]);

            if ($value[0] === '#') {
                $tokens[$match[1]] = ['hex' => strtolower($value), 'alpha' => null];
                continue;
            }

            if (preg_match('/rgba\(\s*(\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\s*\)/', $value, $parts) === 1) {
                $tokens[$match[1]] = [
                    'hex' => hex_from_rgb([(int) $parts[1], (int) $parts[2], (int) $parts[3]]),
                    'alpha' => (float) $parts[4],
                ];
            }
        }
    }

    return $tokens;
}
