/*
 * GEDEFENSE WPR — CONSOLE INTERACTION LAYER
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vanilla, dependency-free, CSP-safe. Everything here is progressive
 * enhancement: without JavaScript the page is fully readable and the forms
 * still submit. Dynamic artifact data is written with textContent only — this
 * file never uses innerHTML, outerHTML, insertAdjacentHTML or document.write.
 */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;

    if (!root) {
        return;
    }

    /* Tells the stylesheet that enhancement is available (toggle visibility). */
    root.classList.add('vgt-js');

    var KEY_HINT = 'The 64-character hex key shipped with this package. It is not stored by the console.';
    var WIDE_QUERY = '(min-width: 1180px)';

    /* ── Z4 destructive confirmation dialog ───────────────────────────── */
    var dialog = doc.getElementById('vgt-confirm-dialog');
    var modalSupported = !!dialog && typeof dialog.showModal === 'function';
    var pendingForm = null;
    var opener = null;

    function setSlot(selector, text) {
        if (!dialog) {
            return;
        }

        var node = dialog.querySelector(selector);
        if (node) {
            node.textContent = text;
        }
    }

    function restoreFocus() {
        if (opener && typeof opener.focus === 'function') {
            opener.focus();
        }

        opener = null;
    }

    function focusableInDialog() {
        if (!dialog) {
            return [];
        }

        var nodes = dialog.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );

        return Array.prototype.slice.call(nodes).filter(function (node) {
            return node.getClientRects().length > 0;
        });
    }

    function openConfirm(form) {
        if (!dialog || !form) {
            return;
        }

        pendingForm = form;
        opener = doc.activeElement;
        setSlot('[data-vgt-confirm-name]', form.getAttribute('data-artifact-name') || 'Artifact');
        setSlot('[data-vgt-confirm-id]', form.getAttribute('data-artifact-id') || 'unknown');

        if (modalSupported) {
            dialog.returnValue = '';
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
            dialog.setAttribute('aria-modal', 'true');
            doc.body.classList.add('vgt-dialog-open');
            var cancel = dialog.querySelector('[data-vgt-cancel]');
            if (cancel) {
                cancel.focus();
            }
        }
    }

    function finishConfirm(confirmed) {
        if (!dialog) {
            return;
        }

        var form = pendingForm;
        pendingForm = null;

        if (modalSupported) {
            if (dialog.open) {
                dialog.close(confirmed ? 'confirm' : 'cancel');
            }
        } else {
            dialog.removeAttribute('open');
            dialog.removeAttribute('aria-modal');
            doc.body.classList.remove('vgt-dialog-open');
        }

        if (confirmed && form) {
            /* Native submit() intentionally bypasses the submit interception:
               reaching this line requires the explicit dialog confirmation. */
            form.submit();
        }

        restoreFocus();
    }

    if (dialog) {
        if (modalSupported) {
            dialog.addEventListener('close', function () {
                var form = pendingForm;
                var confirmed = dialog.returnValue === 'confirm';
                pendingForm = null;

                if (confirmed && form) {
                    form.submit();
                }

                restoreFocus();
            });
        } else {
            dialog.addEventListener('click', function (event) {
                var target = event.target instanceof Element ? event.target : null;
                if (!target) {
                    return;
                }

                if (target.closest('[data-vgt-cancel]')) {
                    event.preventDefault();
                    finishConfirm(false);
                } else if (target.closest('[data-vgt-confirm]')) {
                    event.preventDefault();
                    finishConfirm(true);
                }
            });
        }
    }

    /* Explicit Tab trap + Escape for the fallback dialog; the native modal
       already traps focus, so this only acts at the boundary in that case. */
    doc.addEventListener('keydown', function (event) {
        if (!dialog || !dialog.hasAttribute('open')) {
            return;
        }

        if (event.key === 'Escape' && !modalSupported) {
            event.preventDefault();
            finishConfirm(false);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        var items = focusableInDialog();
        if (items.length === 0) {
            return;
        }

        var first = items[0];
        var last = items[items.length - 1];
        var active = doc.activeElement;

        if (event.shiftKey && (active === first || !dialog.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    });

    /* ── Delete interception: the real form submits after confirmation ── */
    doc.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.classList.contains('vgt-delete-form') && form.getAttribute('data-vgt-submitting') !== 'true') {
            event.preventDefault();
            openConfirm(form);
            return;
        }

        if (form.classList.contains('vgt-deploy-form')) {
            var submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                var label = submit.querySelector('span');
                if (label) {
                    label.textContent = 'Deploying…';
                }
            }
            form.setAttribute('aria-busy', 'true');
        }
    });

    /* ── Deployment rail: collapsible on tablet/mobile, fixed on desktop ─ */
    var deployBody = doc.getElementById('vgt-deploy-body');
    var deployToggle = doc.querySelector('[data-vgt-deploy-toggle]');
    var deployLabel = doc.querySelector('[data-vgt-deploy-toggle-label]');
    var wide = window.matchMedia(WIDE_QUERY);

    function applyDeployLayout(collapsed) {
        if (!deployBody || !deployToggle) {
            return;
        }

        if (wide.matches) {
            deployBody.hidden = false;
            deployToggle.hidden = true;
            deployToggle.setAttribute('aria-expanded', 'true');
            return;
        }

        deployToggle.hidden = false;
        deployBody.hidden = collapsed;
        deployToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (deployLabel) {
            deployLabel.textContent = collapsed ? 'Show deployment' : 'Hide deployment';
        }
    }

    var deployCollapsed = !wide.matches;
    applyDeployLayout(deployCollapsed);

    if (deployToggle) {
        deployToggle.addEventListener('click', function () {
            deployCollapsed = !deployCollapsed;
            applyDeployLayout(deployCollapsed);
        });
    }

    var onWideChange = function () {
        applyDeployLayout(deployCollapsed);
    };

    if (typeof wide.addEventListener === 'function') {
        wide.addEventListener('change', onWideChange);
    } else if (typeof wide.addListener === 'function') {
        wide.addListener(onWideChange);
    }

    doc.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        if (target.closest('[data-vgt-open-deploy]')) {
            deployCollapsed = false;
            applyDeployLayout(false);
        }
    });

    /* ── Decryption key: local format feedback (server stays authoritative) ─ */
    var keyInput = doc.getElementById('vgt-license-key');
    var keyHint = doc.getElementById('vgt-hint-key');

    function validateKey() {
        if (!keyInput || !keyHint) {
            return;
        }

        var value = keyInput.value.trim();
        var empty = value === '';
        var valid = empty || /^[a-fA-F0-9]{64}$/.test(value);

        keyInput.setAttribute('aria-invalid', valid ? 'false' : 'true');
        keyHint.setAttribute('data-vgt-invalid', valid ? 'false' : 'true');

        if (empty) {
            keyHint.textContent = KEY_HINT;
        } else if (valid) {
            keyHint.textContent = 'Format accepted: 64 hexadecimal characters.';
        } else {
            keyHint.textContent = 'A key is exactly 64 hexadecimal characters (' + value.length + ' of 64 entered).';
        }
    }

    if (keyInput) {
        keyInput.addEventListener('input', validateKey);
        keyInput.addEventListener('blur', validateKey);
    }
}());
