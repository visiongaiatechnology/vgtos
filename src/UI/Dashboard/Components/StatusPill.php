<?php
/**
 * VGT Omega console — status pill component.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Components;

/**
 * Short state label with an icon and a fixed semantic tone.
 *
 * The pill always contains a visible text label: tone colour is a redundant
 * second channel only (WCAG 1.4.1, "use of colour"). Tones are allowlisted so
 * no caller can inject arbitrary class names.
 */
final class StatusPill
{
    private const TONES = ['ok', 'warn', 'crit', 'neutral', 'info'];

    /** @var array<string, string> */
    private const DEFAULT_ICONS = [
        'ok' => 'check',
        'warn' => 'lock',
        'crit' => 'alert',
        'neutral' => 'file',
        'info' => 'activity',
    ];

    /** @param string $tone  allowlisted semantic tone */
    public static function render(string $tone, string $label, string $icon = ''): void
    {
        $tone = in_array($tone, self::TONES, true) ? $tone : 'neutral';
        $glyph = $icon !== '' ? $icon : (self::DEFAULT_ICONS[$tone] ?? 'file');

        echo '<span class="vgt-pill vgt-pill--' . esc_attr($tone) . '">';
        Icon::render($glyph, 'vgt-icon vgt-pill__icon');
        echo '<span class="vgt-pill__label">' . esc_html($label) . '</span>';
        echo '</span>';
    }
}
