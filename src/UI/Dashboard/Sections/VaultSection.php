<?php
/**
 * GeDefense WPR console — artifact vault registry section.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Sections;

use VGT\Omega\UI\Dashboard\Components\Icon;
use VGT\Omega\UI\Dashboard\Components\StatusPill;
use VGT\Omega\UI\Dashboard\ConsoleData;

/**
 * The primary work stage: one row per encrypted artifact.
 *
 * Deliberately a list of rows inside a single opaque panel — not a tile wall.
 * A dense registry has to be scanned and compared, so each row carries
 * identity, textual state plus pill, mount verdict and the destructive action.
 * The opaque material variant is used here because `backdrop-filter` under
 * dense text destroys contrast.
 */
final class VaultSection
{
    public static function render(ConsoleData $data): void
    {
        ?>
<section class="vgt-panel vgt-panel--opaque vgt-vault" id="vgt-vault" aria-labelledby="vgt-vault-title">
    <header class="vgt-panel__head">
        <div class="vgt-panel__heading">
            <p class="vgt-eyebrow">Registry</p>
            <h2 id="vgt-vault-title" class="vgt-panel__title">Artifact vault</h2>
            <p class="vgt-panel__sub">
                <?php if ($data->isOffline()) : ?>
                    Registry unavailable while the vault subsystem is offline.
                <?php else : ?>
                    Verified artifacts decrypted into RAM for this request only &mdash; nothing is re-written to disk.
                <?php endif; ?>
            </p>
        </div>
        <p class="vgt-vault__count">
            <span class="vgt-vault__count-value"><?php echo esc_html((string) $data->artifactCount()); ?></span>
            <span class="vgt-vault__count-label"><?php if ($data->artifactCount() === 1) : ?>artifact<?php else : ?>artifacts<?php endif; ?></span>
        </p>
    </header>

    <?php if ($data->isOffline()) : ?>
    <div class="vgt-empty vgt-empty--offline">
        <span class="vgt-empty__seal" aria-hidden="true"><?php Icon::render('alert', 'vgt-icon vgt-empty__glyph'); ?></span>
        <h3 class="vgt-empty__title">Registry cannot be read</h3>
        <p class="vgt-empty__text">
            The vault subsystem is offline, so no artifact list, mount verdict or signature state can
            be trusted right now. Recover the vault using the checklist above, then reload.
        </p>
        <?php if ($data->bootReference !== '') : ?>
        <p class="vgt-empty__ref">Failure reference <code><?php echo esc_html($data->bootReference); ?></code></p>
        <?php endif; ?>
    </div>
    <?php elseif ($data->artifactCount() === 0) : ?>
    <div class="vgt-empty">
        <span class="vgt-empty__seal" aria-hidden="true"><?php Icon::render('layers', 'vgt-icon vgt-empty__glyph'); ?></span>
        <h3 class="vgt-empty__title">No encrypted artifacts installed</h3>
        <p class="vgt-empty__text">
            The vault is empty. Upload a signed <code>.zip</code> package together with its
            64-character hex key in the deployment panel; the console verifies the manifest,
            signature and classmap before anything is mounted.
        </p>
        <a class="vgt-btn vgt-btn--quiet" href="#vgt-deploy" data-vgt-open-deploy>
            <span>Go to deployment</span>
            <?php Icon::render('arrow', 'vgt-icon vgt-btn__icon'); ?>
        </a>
    </div>
    <?php else : ?>
    <div class="vgt-vault__columns" aria-hidden="true">
        <span>Artifact</span>
        <span>Package details</span>
        <span>Actions</span>
    </div>
    <ol class="vgt-vault__list" role="list">
        <?php foreach ($data->artifacts as $row) : ?>
        <li class="vgt-row">
            <div class="vgt-row__identity">
                <div class="vgt-row__titleline">
                    <h3 class="vgt-row__name"><?php echo esc_html($row['name']); ?></h3>
                    <?php StatusPill::render($row['tone'], $row['state_label'], $row['icon']); ?>
                </div>
                <p class="vgt-row__id">ID <code><?php echo esc_html($row['id']); ?></code></p>
                <p class="vgt-row__verdict"><?php echo esc_html($row['state_detail']); ?></p>
            </div>
            <dl class="vgt-row__meta">
                <div class="vgt-row__pair">
                    <dt>Entry</dt>
                    <dd><code><?php echo esc_html($row['entry']); ?></code></dd>
                </div>
                <div class="vgt-row__pair">
                    <dt>Files</dt>
                    <dd><?php echo esc_html((string) $row['files']); ?></dd>
                </div>
                <div class="vgt-row__pair">
                    <dt>Format</dt>
                    <dd><?php echo esc_html($row['format']); ?></dd>
                </div>
                <div class="vgt-row__pair">
                    <dt>Signature</dt>
                    <dd><?php if ($row['signed']) : ?>Verified<?php else : ?>Unsigned<?php endif; ?></dd>
                </div>
                <div class="vgt-row__pair">
                    <dt>Last rotation</dt>
                    <dd><?php echo esc_html($row['updated_label']); ?></dd>
                </div>
                <div class="vgt-row__pair">
                    <dt>Load mode</dt>
                    <dd><?php if ($row['state'] === 'mounted') : ?>RAM only<?php else : ?>Not loaded<?php endif; ?></dd>
                </div>
            </dl>
            <form class="vgt-row__actions vgt-delete-form"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  method="post"
                  data-artifact-name="<?php echo esc_attr($row['name']); ?>"
                  data-artifact-id="<?php echo esc_attr($row['id']); ?>">
                <input type="hidden" name="action" value="vgt_delete_artifact">
                <input type="hidden" name="artifact_id" value="<?php echo esc_attr($row['id']); ?>">
                <?php wp_nonce_field('vgt_action'); ?>
                <button type="submit" class="vgt-btn vgt-btn--danger-ghost"
                        aria-label="<?php echo esc_attr('Delete artifact ' . $row['name'] . ' permanently'); ?>">
                    <?php Icon::render('trash', 'vgt-icon vgt-btn__icon'); ?>
                    <span>Delete</span>
                </button>
            </form>
        </li>
        <?php endforeach; ?>
    </ol>
    <p class="vgt-vault__footnote">
        Deletion is permanent and requires confirmation. A failed signature check keeps an artifact
        locked instead of executing it &mdash; verify the key and package before retrying.
    </p>
    <?php endif; ?>
</section>
        <?php
    }
}
