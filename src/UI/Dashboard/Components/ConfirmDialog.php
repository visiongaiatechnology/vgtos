<?php
/**
 * GeDefense WPR console — destructive action confirmation dialog (Z4).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Components;

/**
 * Renders the single native <dialog> used for artifact deletion.
 *
 * The dialog is static markup: no per-artifact copy is server-rendered into
 * it, so no artifact data can ever be injected as markup. console.js fills the
 * name/id slots with textContent, traps Tab, restores focus and submits the
 * real per-row form only after explicit confirmation. Escape closes the dialog
 * natively (form method="dialog") and therefore never submits.
 */
final class ConfirmDialog
{
    public static function render(): void
    {
        ?>
<dialog id="vgt-confirm-dialog" class="vgt-dialog" aria-labelledby="vgt-confirm-title" aria-describedby="vgt-confirm-desc">
    <form method="dialog" class="vgt-dialog__panel">
        <p class="vgt-eyebrow vgt-dialog__eyebrow">Destructive action</p>
        <h2 id="vgt-confirm-title" class="vgt-dialog__title">Delete artifact permanently?</h2>
        <p id="vgt-confirm-desc" class="vgt-dialog__text">
            This removes the encrypted artifact package and every encrypted blob from the vault.
            Any code previously mounted from it stops loading immediately. The artifact cannot be
            recovered from this installation.
        </p>
        <dl class="vgt-dialog__target">
            <div>
                <dt>Artifact</dt>
                <dd data-vgt-confirm-name>&mdash;</dd>
            </div>
            <div>
                <dt>Artifact ID</dt>
                <dd><code data-vgt-confirm-id>&mdash;</code></dd>
            </div>
        </dl>
        <div class="vgt-dialog__actions">
            <button type="submit" value="cancel" class="vgt-btn vgt-btn--quiet" data-vgt-cancel>
                <span>Keep artifact</span>
            </button>
            <button type="submit" value="confirm" class="vgt-btn vgt-btn--danger" data-vgt-confirm>
                <?php Icon::render('trash', 'vgt-icon vgt-btn__icon'); ?>
                <span>Delete permanently</span>
            </button>
        </div>
    </form>
</dialog>
        <?php
    }
}
