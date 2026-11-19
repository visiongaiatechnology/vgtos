# VGT Omega — static accessibility, semantics and source audit

Scope: the three rendered preview documents (real `Dashboard::renderWith(ConsoleData::fromFixture())`
output) plus the shipped PHP/CSS/JS sources.

> **VISUAL_QA = NOT_TESTED (screenshots blocked by the build environment).** Chromium/Firefox cannot start in
> this environment (browser process/IPC denial), so no PNG exists and no claim about pixels is made.
> Browser rendering, real screen-reader output, touch testing, 200% zoom reflow and OKLCH
> wide-gamut rendering remain unverified.

## DOM and semantics checks

| Document | Check | Result | Detail |
|---|---|---|---|
| dashboard-preview.html | DOM parses without fatal libxml errors | PASS | 64 HTML4-parser notice(s) for HTML5 elements |
| dashboard-preview.html | HTML doctype present | PASS |  |
| dashboard-preview.html | html[lang] declared | PASS |  |
| dashboard-preview.html | document title present | PASS |  |
| dashboard-preview.html | exactly one h1 | PASS | 1 found |
| dashboard-preview.html | heading levels never skip downward | PASS | h1 h2 h2 h3 h3 h3 h2 h2 h3 h3 h3 h2 |
| dashboard-preview.html | all element ids unique | PASS | 21 ids |
| dashboard-preview.html | every form control has label[for] or aria-label | PASS | 4 controls; all labelled |
| dashboard-preview.html | buttons and links have accessible names | PASS | 7 buttons, 4 links |
| dashboard-preview.html | aria-* idrefs resolve | PASS | ok |
| dashboard-preview.html | exactly one main landmark | PASS | 1 main |
| dashboard-preview.html | header/footer/nav landmarks present | PASS |  |
| dashboard-preview.html | skip link points to existing focus target | PASS |  |
| dashboard-preview.html | Z4 dialog has label, description and confirm/cancel buttons | PASS | labelledby=vgt-confirm-title describedby=vgt-confirm-desc confirm=1 cancel=1 |
| dashboard-preview.html | dialog closes natively via form method=dialog | PASS |  |
| dashboard-preview.html | all submission forms declare POST action | PASS | 4 form(s) |
| dashboard-preview.html | install form is multipart with nonce | PASS |  |
| dashboard-preview.html | one delete form per registry row, each with artifact_id + nonce | PASS | 3 form(s) / 3 row(s) |
| dashboard-preview.html | no inline event handlers | PASS | 0 found |
| dashboard-preview.html | no inline style attributes in console markup | PASS |  |
| dashboard-preview.html | no positive tabindex | PASS |  |
| dashboard-preview.html | no images/iframes/objects/canvas | PASS |  |
| dashboard-preview.html | no autofocus | PASS |  |
| dashboard-preview.html | no <table> (registry is a definition-list row list) | PASS |  |
| dashboard-preview.html | no external stylesheet/script URLs | PASS |  |
| dashboard-preview.html | every status pill carries exactly one text label | PASS | 5 pills |
| dashboard-preview.html | success notice uses role=status | PASS |  |
| dashboard-preview.html | safe-mode banner not shown in healthy state | PASS |  |
| dashboard-preview.html | locked artifact rendered with text state | PASS |  |
| dashboard-preview.html | three registry rows from fixture | PASS | 3 rows |
| dashboard-preview-safe-mode.html | DOM parses without fatal libxml errors | PASS | 62 HTML4-parser notice(s) for HTML5 elements |
| dashboard-preview-safe-mode.html | HTML doctype present | PASS |  |
| dashboard-preview-safe-mode.html | html[lang] declared | PASS |  |
| dashboard-preview-safe-mode.html | document title present | PASS |  |
| dashboard-preview-safe-mode.html | exactly one h1 | PASS | 1 found |
| dashboard-preview-safe-mode.html | heading levels never skip downward | PASS | h1 h2 h2 h2 h2 h3 h2 h2 h3 h2 |
| dashboard-preview-safe-mode.html | all element ids unique | PASS | 23 ids |
| dashboard-preview-safe-mode.html | every form control has label[for] or aria-label | PASS | 4 controls; all labelled |
| dashboard-preview-safe-mode.html | buttons and links have accessible names | PASS | 4 buttons, 4 links |
| dashboard-preview-safe-mode.html | aria-* idrefs resolve | PASS | ok |
| dashboard-preview-safe-mode.html | exactly one main landmark | PASS | 1 main |
| dashboard-preview-safe-mode.html | header/footer/nav landmarks present | PASS |  |
| dashboard-preview-safe-mode.html | skip link points to existing focus target | PASS |  |
| dashboard-preview-safe-mode.html | Z4 dialog has label, description and confirm/cancel buttons | PASS | labelledby=vgt-confirm-title describedby=vgt-confirm-desc confirm=1 cancel=1 |
| dashboard-preview-safe-mode.html | dialog closes natively via form method=dialog | PASS |  |
| dashboard-preview-safe-mode.html | all submission forms declare POST action | PASS | 1 form(s) |
| dashboard-preview-safe-mode.html | install form is multipart with nonce | PASS |  |
| dashboard-preview-safe-mode.html | one delete form per registry row, each with artifact_id + nonce | PASS | 0 form(s) / 0 row(s) |
| dashboard-preview-safe-mode.html | no inline event handlers | PASS | 0 found |
| dashboard-preview-safe-mode.html | no inline style attributes in console markup | PASS |  |
| dashboard-preview-safe-mode.html | no positive tabindex | PASS |  |
| dashboard-preview-safe-mode.html | no images/iframes/objects/canvas | PASS |  |
| dashboard-preview-safe-mode.html | no autofocus | PASS |  |
| dashboard-preview-safe-mode.html | no <table> (registry is a definition-list row list) | PASS |  |
| dashboard-preview-safe-mode.html | no external stylesheet/script URLs | PASS |  |
| dashboard-preview-safe-mode.html | every status pill carries exactly one text label | PASS | 2 pills |
| dashboard-preview-safe-mode.html | offline state disables the deployment fieldset | PASS |  |
| dashboard-preview-safe-mode.html | safe-mode recovery snippet documented | PASS |  |
| dashboard-preview-safe-mode.html | error notice uses role=alert | PASS |  |
| dashboard-preview-dialog.html | DOM parses without fatal libxml errors | PASS | 64 HTML4-parser notice(s) for HTML5 elements |
| dashboard-preview-dialog.html | HTML doctype present | PASS |  |
| dashboard-preview-dialog.html | html[lang] declared | PASS |  |
| dashboard-preview-dialog.html | document title present | PASS |  |
| dashboard-preview-dialog.html | exactly one h1 | PASS | 1 found |
| dashboard-preview-dialog.html | heading levels never skip downward | PASS | h1 h2 h2 h3 h3 h3 h2 h2 h3 h3 h3 h2 |
| dashboard-preview-dialog.html | all element ids unique | PASS | 21 ids |
| dashboard-preview-dialog.html | every form control has label[for] or aria-label | PASS | 4 controls; all labelled |
| dashboard-preview-dialog.html | buttons and links have accessible names | PASS | 7 buttons, 4 links |
| dashboard-preview-dialog.html | aria-* idrefs resolve | PASS | ok |
| dashboard-preview-dialog.html | exactly one main landmark | PASS | 1 main |
| dashboard-preview-dialog.html | header/footer/nav landmarks present | PASS |  |
| dashboard-preview-dialog.html | skip link points to existing focus target | PASS |  |
| dashboard-preview-dialog.html | Z4 dialog has label, description and confirm/cancel buttons | PASS | labelledby=vgt-confirm-title describedby=vgt-confirm-desc confirm=1 cancel=1 |
| dashboard-preview-dialog.html | dialog closes natively via form method=dialog | PASS |  |
| dashboard-preview-dialog.html | all submission forms declare POST action | PASS | 4 form(s) |
| dashboard-preview-dialog.html | install form is multipart with nonce | PASS |  |
| dashboard-preview-dialog.html | one delete form per registry row, each with artifact_id + nonce | PASS | 3 form(s) / 3 row(s) |
| dashboard-preview-dialog.html | no inline event handlers | PASS | 0 found |
| dashboard-preview-dialog.html | no inline style attributes in console markup | PASS |  |
| dashboard-preview-dialog.html | no positive tabindex | PASS |  |
| dashboard-preview-dialog.html | no images/iframes/objects/canvas | PASS |  |
| dashboard-preview-dialog.html | no autofocus | PASS |  |
| dashboard-preview-dialog.html | no <table> (registry is a definition-list row list) | PASS |  |
| dashboard-preview-dialog.html | no external stylesheet/script URLs | PASS |  |
| dashboard-preview-dialog.html | every status pill carries exactly one text label | PASS | 5 pills |
| dashboard-preview-dialog.html | success notice uses role=status | PASS |  |
| dashboard-preview-dialog.html | safe-mode banner not shown in healthy state | PASS |  |

DOM failures: **0**.

## Source, dependency and design-rule checks

| Check | Result | Detail |
|---|---|---|
| every PHP file starts with <?php (no BOM) | PASS | 13 files clean |
| every PHP file is <= 500 lines | PASS | largest src/UI/Dashboard/ConsoleData.php (397 lines) |
| every PHP file declares strict_types | PASS | all files |
| every PHP file carries SPDX AGPL-3.0-or-later header | PASS | all files |
| no raw variable echo in render code | PASS | all output uses esc_html/esc_attr/esc_url or literal text |
| no browser confirm() in PHP | PASS | delete uses an accessible native <dialog> |
| no emoji characters in shipped UI sources | PASS | inline SVG icon set only |
| console.js uses no HTML-injection sink | PASS | textContent / DOM construction only |
| console.js has no eval/new Function | PASS |  |
| console.js has no external runtime dependency | PASS |  |
| console.js implements dialog focus trap and restore | PASS |  |
| console.js prevents double submit | PASS |  |
| console.js collapses deployment only below desktop | PASS |  |
| CSS has no external URL/@import/CDN | PASS |  |
| CSS declares sRGB fallback tokens first | PASS |  |
| CSS overrides tokens with OKLCH behind @supports | PASS |  |
| CSS has no repeating grid/dot background pattern | PASS |  |
| CSS defines Z0-Z4 material level comments | PASS |  |
| CSS defines visible :focus-visible ring | PASS |  |
| CSS honours prefers-reduced-motion | PASS |  |
| CSS honours prefers-reduced-transparency | PASS |  |
| CSS honours forced-colors | PASS |  |
| CSS provides backdrop-filter @supports fallback | PASS |  |
| CSS uses container queries for the registry | PASS |  |
| CSS defines asymmetric desktop workbench | PASS |  |
| CSS defines tablet stack at 1179px | PASS |  |
| CSS defines mobile task-first layout at 719px | PASS |  |
| CSS defines sticky mobile action bar with safe-area inset | PASS |  |
| CSS enforces >=44px touch targets | PASS |  |
| CSS uses system font stack + monospace for data | PASS |  |
| !important only in reduced-motion block | PASS | 4 occurrence(s) |
| assets stay lean (informational) | PASS | glass-2100.css 486 lines, console.js 296 lines |

Source failures: **0**.

## PHP line ledger (limit 500)

| File | Lines |
|---|---|
| `src/UI/Dashboard/ArtifactRow.php` | 136 |
| `src/UI/Dashboard/Assets.php` | 49 |
| `src/UI/Dashboard/Components/ConfirmDialog.php` | 60 |
| `src/UI/Dashboard/Components/Icon.php` | 77 |
| `src/UI/Dashboard/Components/Notice.php` | 58 |
| `src/UI/Dashboard/Components/StatusPill.php` | 45 |
| `src/UI/Dashboard/ConsoleData.php` | 397 |
| `src/UI/Dashboard/ConsoleFixture.php` | 168 |
| `src/UI/Dashboard/Dashboard.php` | 156 |
| `src/UI/Dashboard/Sections/DeploySection.php` | 125 |
| `src/UI/Dashboard/Sections/DiagnosticsSection.php` | 226 |
| `src/UI/Dashboard/Sections/OverviewSection.php` | 176 |
| `src/UI/Dashboard/Sections/VaultSection.php` | 145 |

Files: **13**, largest: **src/UI/Dashboard/ConsoleData.php (397 lines)**.
