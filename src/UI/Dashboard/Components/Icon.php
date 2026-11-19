<?php
/**
 * VGT Omega console — inline SVG icon set.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Components;

/**
 * Single source of truth for the console iconography.
 *
 * Every glyph is a hand-built 24x24 stroke symbol rendered inline (no icon
 * font, no CDN, no emoji). Decorative icons are always `aria-hidden`; the
 * adjacent textual label carries the meaning, so no information depends on
 * the glyph alone.
 */
final class Icon
{
    /** @var array<string, string> */
    private const PATHS = [
        'vault' => '<path d="M12 3 4.5 6.2v5.6c0 4.3 3.1 7.7 7.5 9.2 4.4-1.5 7.5-4.9 7.5-9.2V6.2L12 3Z"/>'
            . '<circle cx="12" cy="11" r="2.1"/><path d="M12 13.1V16"/>',
        'check' => '<path d="m5 12.6 4.2 4.2L19 7.2"/>',
        'lock' => '<rect x="5" y="10.6" width="14" height="9.4" rx="2.1"/>'
            . '<path d="M8.6 10.6V8.1a3.4 3.4 0 0 1 6.8 0v2.5"/>',
        'alert' => '<path d="M12 4.2 2.9 19.6h18.2L12 4.2Z"/><path d="M12 9.6v4.3"/>'
            . '<path d="M12 17.1h.01"/>',
        'rotate' => '<path d="M20 12a8 8 0 1 1-2.7-6"/><path d="M20 4.2V8.4h-4.2"/>',
        'trash' => '<path d="M4.2 7h15.6"/><path d="M9.2 7V4.8h5.6V7"/>'
            . '<path d="m6.3 7 1 12.4h9.4L18 7"/><path d="M10.4 10.8v5.2M13.6 10.8v5.2"/>',
        'activity' => '<path d="M3 13.2h3.8l2.6-6.4 4.2 12 2.6-5.6H21"/>',
        'key' => '<circle cx="8.2" cy="15.2" r="3.1"/><path d="m10.4 13 8.2-8.2"/>'
            . '<path d="m16.2 7.2 2.1 2.1"/><path d="m18.4 5 2.1 2.1"/>',
        'file' => '<path d="M13.2 3H7.4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h9.2a2 2 0 0 0 2-2V8.8L13.2 3Z"/>'
            . '<path d="M13.2 3v5.8h5.4"/>',
        'chevron' => '<path d="m7.2 10.2 4.8 4.8 4.8-4.8"/>',
        'close' => '<path d="M6.4 6.4l11.2 11.2M17.6 6.4 6.4 17.6"/>',
        'chip' => '<rect x="7" y="7" width="10" height="10" rx="2.2"/>'
            . '<path d="M10 3.4v3.6M14 3.4v3.6M10 17v3.6M14 17v3.6"/>'
            . '<path d="M3.4 10H7M3.4 14H7M17 10h3.6M17 14h3.6"/>',
        'layers' => '<path d="m12 3.4 8.4 4.3L12 12 3.6 7.7 12 3.4Z"/>'
            . '<path d="m4.6 12.4 7.4 3.8 7.4-3.8"/><path d="m4.6 16.9 7.4 3.8 7.4-3.8"/>',
        'arrow' => '<path d="M4.8 12h13.4"/><path d="m13.2 6.8 5.2 5.2-5.2 5.2"/>',
    ];

    /** Render an inline icon. The name is allowlisted, never interpolated raw. */
    public static function render(string $name, string $class = 'vgt-icon'): void
    {
        echo self::svg($name, $class);
    }

    /**
     * Return the icon markup.
     *
     * @param string $name  allowlisted glyph id
     * @param string $class extra CSS classes (escaped)
     */
    public static function svg(string $name, string $class = 'vgt-icon'): string
    {
        $body = self::PATHS[$name] ?? self::PATHS['vault'];

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" width="20" height="20"'
            . ' fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }
}
