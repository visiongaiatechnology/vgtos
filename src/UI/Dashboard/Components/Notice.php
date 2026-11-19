<?php
/**
 * VGT Omega console — operation notice component.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Components;

/**
 * Renders the one-shot admin notice produced by ArtifactController.
 *
 * Contract: array{type: string, message: string, reference: string}. The
 * message is plain text from the controller and is escaped here; the opaque
 * reference is rendered only when it matches the documented reference shape,
 * so a corrupted transient cannot inject markup or a path.
 */
final class Notice
{
    /** @param array{type?: mixed, message?: mixed, reference?: mixed}|null $notice */
    public static function render(?array $notice): void
    {
        if ($notice === null) {
            return;
        }

        $message = is_string($notice['message'] ?? null) ? trim($notice['message']) : '';
        if ($message === '') {
            return;
        }

        $type = is_string($notice['type'] ?? null) ? strtolower((string) $notice['type']) : '';
        $isSuccess = $type === 'success';
        $reference = is_string($notice['reference'] ?? null)
            ? (string) $notice['reference']
            : '';
        $reference = preg_match('/^[A-Za-z0-9._:-]{1,48}$/', $reference) === 1 ? $reference : '';

        $tone = $isSuccess ? 'ok' : 'crit';
        $title = $isSuccess ? 'Operation complete' : 'Operation rejected';
        $role = $isSuccess ? 'status' : 'alert';

        echo '<div class="vgt-notice vgt-notice--' . esc_attr($tone) . '" role="' . esc_attr($role) . '">';
        Icon::render($isSuccess ? 'check' : 'alert', 'vgt-icon vgt-notice__icon');
        echo '<div class="vgt-notice__body">';
        echo '<p class="vgt-notice__title">' . esc_html($title) . '</p>';
        echo '<p class="vgt-notice__text">' . esc_html($message) . '</p>';
        if ($reference !== '') {
            echo '<p class="vgt-notice__ref">Reference <code>' . esc_html($reference) . '</code>'
                . ' — quote it when contacting support.</p>';
        }
        echo '</div></div>';
    }
}
