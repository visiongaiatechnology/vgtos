<?php
/**
 * VGT Omega console — deployment / rotation rail.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\UI\Dashboard\Sections;

use VGT\Omega\UI\Dashboard\Components\Icon;
use VGT\Omega\UI\Dashboard\ConsoleData;

/**
 * Docked deployment tool: upload a signed package, provide the hex key, rotate.
 *
 * The body is collapsible on tablet and mobile only (console.js toggles the
 * `hidden` attribute and the button's aria-expanded). Without JavaScript the
 * toggle never appears and the panel stays fully open, so deployment can never
 * become unreachable when scripts are blocked.
 */
final class DeploySection
{
    public static function render(ConsoleData $data): void
    {
        $offline = $data->isOffline();

        ?>
<section class="vgt-panel vgt-panel--glass vgt-deploy" id="vgt-deploy" aria-labelledby="vgt-deploy-title">
    <header class="vgt-panel__head vgt-deploy__head">
        <div class="vgt-panel__heading">
            <p class="vgt-eyebrow">Deployment</p>
            <h2 id="vgt-deploy-title" class="vgt-panel__title">Deploy or rotate artifact</h2>
            <p class="vgt-panel__sub">Signed package plus its hex key. Verification happens before anything is mounted.</p>
        </div>
        <button type="button" class="vgt-btn vgt-btn--quiet vgt-deploy__toggle"
                aria-expanded="true" aria-controls="vgt-deploy-body" data-vgt-deploy-toggle hidden>
            <span data-vgt-deploy-toggle-label>Show deployment</span>
            <?php Icon::render('chevron', 'vgt-icon vgt-deploy__chevron'); ?>
        </button>
    </header>
    <div class="vgt-deploy__body" id="vgt-deploy-body">
        <?php if ($offline) : ?>
        <p class="vgt-deploy__blocked" role="note">
            Deployment is disabled while the vault subsystem is offline. Recover the vault first so a
            verified artifact can be staged and mounted.
        </p>
        <?php endif; ?>
        <?php if ($data->safeMode) : ?>
        <p class="vgt-deploy__note" role="note">
            Safe mode is active: a deployment is accepted, but the new artifact stays locked and is not
            mounted until safe mode is cleared.
        </p>
        <?php endif; ?>
        <form class="vgt-deploy__form"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              method="post"
              enctype="multipart/form-data">
            <input type="hidden" name="action" value="vgt_install_artifact">
            <?php wp_nonce_field('vgt_action'); ?>
            <fieldset class="vgt-fieldset"<?php if ($offline) : ?> disabled<?php endif; ?>>
                <legend class="vgt-sr-only">Artifact package and decryption key</legend>
                <div class="vgt-field">
                    <label class="vgt-label" for="vgt-artifact-file">Artifact package</label>
                    <input class="vgt-input vgt-input--file" type="file" id="vgt-artifact-file" name="artifact"
                           accept=".zip" required<?php if ($offline) : ?> disabled<?php endif; ?>
                           aria-describedby="vgt-hint-file">
                    <p class="vgt-hint" id="vgt-hint-file">
                        Signed <code>.zip</code> up to 16 MiB containing <code>vgt_manifest.vgt</code>.
                    </p>
                </div>
                <div class="vgt-field">
                    <label class="vgt-label" for="vgt-license-key">Decryption key</label>
                    <input class="vgt-input vgt-input--mono" type="text" id="vgt-license-key" name="license_key"
                           autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="text"
                           required pattern="[a-fA-F0-9]{64}" maxlength="64"
                           placeholder="64 hexadecimal characters"
                           aria-describedby="vgt-hint-key"<?php if ($offline) : ?> disabled<?php endif; ?>>
                    <p class="vgt-hint" id="vgt-hint-key">
                        The 64-character hex key shipped with this package. It is not stored by the console.
                    </p>
                </div>
                <div class="vgt-field">
                    <label class="vgt-label" for="vgt-artifact-name">
                        Artifact name <span class="vgt-label__optional">optional</span>
                    </label>
                    <input class="vgt-input" type="text" id="vgt-artifact-name" name="artifact_name"
                           maxlength="120" autocomplete="off" placeholder="Aegis Core Firewall"
                           aria-describedby="vgt-hint-name"<?php if ($offline) : ?> disabled<?php endif; ?>>
                    <p class="vgt-hint" id="vgt-hint-name">Shown in the registry. Defaults to the manifest name.</p>
                </div>
                <div class="vgt-field">
                    <label class="vgt-label" for="vgt-rotate-id">Rotation target</label>
                    <select class="vgt-input vgt-select" id="vgt-rotate-id" name="rotate_id"
                            aria-describedby="vgt-hint-rotate"<?php if ($offline) : ?> disabled<?php endif; ?>>
                        <option value="">Install as a new artifact</option>
                        <?php foreach ($data->artifacts as $row) : ?>
                        <option value="<?php echo esc_attr($row['id']); ?>">
                            Replace <?php echo esc_html($row['name']); ?> (<?php echo esc_html($row['short_id']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="vgt-hint" id="vgt-hint-rotate">
                        Rotation is atomic: the running version stays live until the replacement is staged
                        and verified.
                    </p>
                </div>
                <button type="submit" class="vgt-btn vgt-btn--primary"<?php if ($offline) : ?> disabled<?php endif; ?>>
                    <span>Deploy to vault</span>
                    <?php Icon::render('arrow', 'vgt-icon vgt-btn__icon'); ?>
                </button>
            </fieldset>
        </form>
        <p class="vgt-deploy__safety">
            Fail-closed by design: if the manifest, signature or classmap check fails, the vault stays
            closed instead of executing unverified code.
        </p>
    </div>
</section>
        <?php
    }
}
