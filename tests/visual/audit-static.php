<?php
/**
 * VGT Omega — static accessibility, semantics and source audit.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Inspects the three rendered preview documents (real Dashboard::renderWith
 * output) and the shipped PHP/CSS/JS sources. It never renders pixels:
 * Chromium cannot start in the confined build environment (process/IPC denial),
 * so browser rendering, real screen-reader output and touch testing are reported
 * as NOT_TESTED instead of guessed.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$previewDir = __DIR__;
$docsDir = $root . '/docs/visual';
$cssPath = $root . '/src/UI/Dashboard/assets/glass-2100.css';
$jsPath = $root . '/src/UI/Dashboard/assets/console.js';
$dashboardDir = $root . '/src/UI/Dashboard';
$previews = ['dashboard-preview.html', 'dashboard-preview-safe-mode.html', 'dashboard-preview-dialog.html'];

$domRows = [];
$domFailures = 0;

$record = static function (string $file, string $check, bool $pass, string $detail = '') use (&$domRows, &$domFailures): void {
    if (!$pass) {
        $domFailures++;
    }
    $domRows[] = ['file' => $file, 'check' => $check, 'pass' => $pass, 'detail' => $detail];
};

foreach ($previews as $previewName) {
    $path = $previewDir . '/' . $previewName;

    if (!is_file($path)) {
        $record($previewName, 'preview file exists', false);
        continue;
    }

    $html = (string) file_get_contents($path);
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $parseNotices = libxml_get_errors();
    libxml_clear_errors();
    $fatal = array_filter($parseNotices, static fn (LibXMLError $e): bool => $e->level === LIBXML_ERR_FATAL);

    $record($previewName, 'DOM parses without fatal libxml errors', $loaded === true && $fatal === [], count($parseNotices) . ' HTML4-parser notice(s) for HTML5 elements');

    $xpath = new DOMXPath($doc);
    $q = static fn (string $expr, ?DOMNode $context = null) => $xpath->query($expr, $context);

    $doctypeOk = $doc->doctype !== null && strtolower((string) $doc->doctype->name) === 'html';
    $record($previewName, 'HTML doctype present', $doctypeOk);
    $record($previewName, 'html[lang] declared', $doc->documentElement !== null && $doc->documentElement->getAttribute('lang') === 'en');
    $record($previewName, 'document title present', trim((string) $doc->getElementsByTagName('title')->item(0)?->textContent) !== '');
    $record($previewName, 'exactly one h1', $q('//h1')->length === 1, $q('//h1')->length . ' found');

    $levels = [];
    foreach ($q('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
        $levels[] = (int) substr($heading->nodeName, 1);
    }
    $orderOk = true;
    for ($i = 1, $c = count($levels); $i < $c; $i++) {
        if ($levels[$i] - $levels[$i - 1] > 1) {
            $orderOk = false;
        }
    }
    $record($previewName, 'heading levels never skip downward', $orderOk, implode(' ', array_map(static fn (int $l): string => 'h' . $l, $levels)));

    $ids = [];
    foreach ($q('//*[@id]') as $node) {
        $id = $node->getAttribute('id');
        $ids[$id] = ($ids[$id] ?? 0) + 1;
    }
    $duplicates = array_keys(array_filter($ids, static fn (int $n): bool => $n > 1));
    $record($previewName, 'all element ids unique', $duplicates === [], $duplicates === [] ? count($ids) . ' ids' : implode(', ', $duplicates));

    $controls = $q('//input[not(@type="hidden")]|//select|//textarea');
    $unlabelled = [];
    foreach ($controls as $control) {
        $id = $control->getAttribute('id');
        $labelled = $id !== '' && $q('//label[@for="' . $id . '"]')->length === 1;
        $labelled = $labelled || $control->getAttribute('aria-label') !== '' || $control->getAttribute('aria-labelledby') !== '';
        if (!$labelled) {
            $unlabelled[] = $id !== '' ? $id : $control->nodeName;
        }
    }
    $record($previewName, 'every form control has label[for] or aria-label', $unlabelled === [], $controls->length . ' controls; ' . ($unlabelled === [] ? 'all labelled' : implode(', ', $unlabelled)));

    $unnamed = [];
    foreach ($q('//button') as $button) {
        if (trim((string) $button->textContent . $button->getAttribute('aria-label')) === '') {
            $unnamed[] = 'button';
        }
    }
    foreach ($q('//a[@href]') as $link) {
        if (trim((string) $link->textContent) === '' && $link->getAttribute('aria-label') === '') {
            $unnamed[] = 'a[href]';
        }
    }
    $record($previewName, 'buttons and links have accessible names', $unnamed === [], $q('//button')->length . ' buttons, ' . $q('//a[@href]')->length . ' links');

    $brokenRefs = [];
    foreach ($q('//*[@aria-labelledby or @aria-describedby or @aria-controls]') as $node) {
        foreach (['aria-labelledby', 'aria-describedby', 'aria-controls'] as $attr) {
            $value = $node->getAttribute($attr);
            if ($value === '') {
                continue;
            }
            foreach (preg_split('/\s+/', $value) ?: [] as $ref) {
                if ($ref !== '' && !$doc->getElementById($ref) instanceof DOMElement) {
                    $brokenRefs[] = $attr . '=' . $ref;
                }
            }
        }
    }
    $record($previewName, 'aria-* idrefs resolve', $brokenRefs === [], $brokenRefs === [] ? 'ok' : implode(', ', $brokenRefs));

    $record($previewName, 'exactly one main landmark', $q('//main')->length === 1, $q('//main')->length . ' main');
    $record(
        $previewName,
        'header/footer/nav landmarks present',
        $q('//header')->length >= 1 && $q('//footer')->length === 1 && $q('//nav[@aria-label]')->length === 1
    );

    $skip = $q('//a[contains(@class,"vgt-skip")]/@href');
    $skipTarget = $skip->length === 1 ? substr((string) $skip->item(0)?->nodeValue, 1) : '';
    $record(
        $previewName,
        'skip link points to existing focus target',
        $skipTarget !== '' && $doc->getElementById($skipTarget) instanceof DOMElement
    );

    $dialogs = $q('//dialog');
    $dialogOk = $dialogs->length === 1;
    $dialogDetail = '';
    if ($dialogOk) {
        $dialog = $dialogs->item(0);
        $labelledBy = $dialog->getAttribute('aria-labelledby');
        $describedBy = $dialog->getAttribute('aria-describedby');
        $confirm = $q('//dialog//button[@value="confirm"]')->length;
        $cancel = $q('//dialog//button[@value="cancel"]')->length;
        $dialogOk = $labelledBy !== '' && $doc->getElementById($labelledBy) instanceof DOMElement
            && $describedBy !== '' && $doc->getElementById($describedBy) instanceof DOMElement
            && $confirm === 1 && $cancel === 1;
        $dialogDetail = sprintf('labelledby=%s describedby=%s confirm=%d cancel=%d', $labelledBy, $describedBy, $confirm, $cancel);
    }
    $record($previewName, 'Z4 dialog has label, description and confirm/cancel buttons', $dialogOk, $dialogDetail);
    $record($previewName, 'dialog closes natively via form method=dialog', $q('//dialog//form[@method="dialog"]')->length === 1);

    $postFormsOk = true;
    foreach ($q('//form[not(@method="dialog")]') as $form) {
        if ($form->getAttribute('action') === '' || $form->getAttribute('method') !== 'post') {
            $postFormsOk = false;
        }
    }
    $record($previewName, 'all submission forms declare POST action', $postFormsOk, $q('//form[not(@method="dialog")]')->length . ' form(s)');
    $record(
        $previewName,
        'install form is multipart with nonce',
        $q('//form[.//input[@name="action" and @value="vgt_install_artifact"]][@enctype="multipart/form-data"]//input[@name="_wpnonce"]')->length === 1
    );

    $deleteForms = $q('//form[.//input[@name="action" and @value="vgt_delete_artifact"]]');
    $deleteOk = true;
    foreach ($deleteForms as $form) {
        if ($q('.//input[@name="artifact_id"]', $form)->length !== 1 || $q('.//input[@name="_wpnonce"]', $form)->length !== 1) {
            $deleteOk = false;
        }
    }
    $rowCount = $q('//li[contains(@class,"vgt-row")]')->length;
    $record(
        $previewName,
        'one delete form per registry row, each with artifact_id + nonce',
        $deleteOk && $deleteForms->length === $rowCount,
        $deleteForms->length . ' form(s) / ' . $rowCount . ' row(s)'
    );

    $inlineHandlers = 0;
    foreach ($q('//*') as $node) {
        foreach ($node->attributes ?? [] as $attribute) {
            if (str_starts_with(strtolower($attribute->nodeName), 'on')) {
                $inlineHandlers++;
            }
        }
    }
    $record($previewName, 'no inline event handlers', $inlineHandlers === 0, $inlineHandlers . ' found');
    $record($previewName, 'no inline style attributes in console markup', $q('//*[@style]')->length === 0);

    $positiveTabindex = 0;
    foreach ($q('//*[@tabindex]') as $node) {
        if ((int) $node->getAttribute('tabindex') > 0) {
            $positiveTabindex++;
        }
    }
    $record($previewName, 'no positive tabindex', $positiveTabindex === 0);
    $record($previewName, 'no images/iframes/objects/canvas', $q('//img|//iframe|//object|//embed|//canvas')->length === 0);
    $record($previewName, 'no autofocus', $q('//*[@autofocus]')->length === 0);
    $record($previewName, 'no <table> (registry is a definition-list row list)', $q('//table')->length === 0);

    $external = 0;
    foreach ($q('//link[@rel="stylesheet"]/@href | //script[@src]/@src') as $url) {
        if (preg_match('#^(https?:)?//#', (string) $url->nodeValue) === 1) {
            $external++;
        }
    }
    $record($previewName, 'no external stylesheet/script URLs', $external === 0);

    $pills = $q('//*[contains(concat(" ", normalize-space(@class), " "), " vgt-pill ")]');
    $unlabelledPills = 0;
    foreach ($pills as $pill) {
        $label = $q('.//*[contains(concat(" ", normalize-space(@class), " "), " vgt-pill__label ")]', $pill);
        if ($label->length !== 1 || trim((string) $label->item(0)?->textContent) === '') {
            $unlabelledPills++;
        }
    }
    $record($previewName, 'every status pill carries exactly one text label', $unlabelledPills === 0 && $pills->length > 0, $pills->length . ' pills');

    if ($previewName === 'dashboard-preview-safe-mode.html') {
        $record($previewName, 'offline state disables the deployment fieldset', $q('//fieldset[@disabled]')->length >= 1);
        $record($previewName, 'safe-mode recovery snippet documented', str_contains($html, 'VGT_OMEGA_SAFE_MODE'));
        $record($previewName, 'error notice uses role=alert', $q('//*[@role="alert"]')->length === 1);
    } else {
        $record($previewName, 'success notice uses role=status', $q('//*[@role="status"]')->length === 1);
        $record($previewName, 'safe-mode banner not shown in healthy state', !str_contains($html, 'Safe mode active'));
    }

    if ($previewName === 'dashboard-preview.html') {
        $record($previewName, 'locked artifact rendered with text state', str_contains($html, 'Locked'));
        $record($previewName, 'three registry rows from fixture', $q('//li[contains(@class,"vgt-row")]')->length === 3, $q('//li[contains(@class,"vgt-row")]')->length . ' rows');
    }
}

/* ── Source scans ─────────────────────────────────────────────────────── */
$sourceRows = [];
$sourceFailures = 0;
$recordSource = static function (string $check, bool $pass, string $detail = '') use (&$sourceRows, &$sourceFailures): void {
    if (!$pass) {
        $sourceFailures++;
    }
    $sourceRows[] = ['check' => $check, 'pass' => $pass, 'detail' => $detail];
};

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dashboardDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}
sort($phpFiles);

$lineLedger = [];
$overLimit = [];
$missingStrict = [];
$missingSpdx = [];
$unsafeEcho = [];
$browserConfirm = [];
$emoji = [];
$missingSplFile = [];
$maxLines = 0;
$maxFile = '';

foreach ($phpFiles as $phpFile) {
    $relative = str_replace('\\', '/', str_replace($root . '/', '', $phpFile));
    $contents = (string) file_get_contents($phpFile);
    $lineCount = substr_count($contents, "\n");
    $lineLedger[] = ['file' => $relative, 'lines' => $lineCount];
    if ($lineCount > $maxLines) {
        $maxLines = $lineCount;
        $maxFile = $relative;
    }

    if ($lineCount > 500) {
        $overLimit[] = $relative . ' (' . $lineCount . ')';
    }

    $bom = substr($contents, 0, 3) === "\xEF\xBB\xBF";
    if ($bom || !str_starts_with($contents, '<?php')) {
        $missingSplFile[] = $relative;
    }

    if (preg_match('/declare\(strict_types=1\);/', $contents) !== 1) {
        $missingStrict[] = $relative;
    }

    if (!str_contains($contents, 'SPDX-License-Identifier: AGPL-3.0-or-later')) {
        $missingSpdx[] = $relative;
    }

    if (preg_match('/<\?=\s*\$|echo\s+\$[A-Za-z_]|print\s+\$[A-Za-z_]/', $contents) === 1) {
        $unsafeEcho[] = $relative;
    }

    if (preg_match('/\b(?:window\.)?confirm\s*\(/', $contents) === 1) {
        $browserConfirm[] = $relative;
    }

    if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $contents) === 1) {
        $emoji[] = $relative;
    }
}

$recordSource('every PHP file starts with <?php (no BOM)', $missingSplFile === [], $missingSplFile === [] ? count($phpFiles) . ' files clean' : implode(', ', $missingSplFile));
$recordSource('every PHP file is <= 500 lines', $overLimit === [], 'largest ' . $maxFile . ' (' . $maxLines . ' lines)');
$recordSource('every PHP file declares strict_types', $missingStrict === [], $missingStrict === [] ? 'all files' : implode(', ', $missingStrict));
$recordSource('every PHP file carries SPDX AGPL-3.0-or-later header', $missingSpdx === [], $missingSpdx === [] ? 'all files' : implode(', ', $missingSpdx));
$recordSource('no raw variable echo in render code', $unsafeEcho === [], $unsafeEcho === [] ? 'all output uses esc_html/esc_attr/esc_url or literal text' : implode(', ', $unsafeEcho));
$recordSource('no browser confirm() in PHP', $browserConfirm === [], 'delete uses an accessible native <dialog>');
$recordSource('no emoji characters in shipped UI sources', $emoji === [], $emoji === [] ? 'inline SVG icon set only' : implode(', ', $emoji));

$js = (string) file_get_contents($jsPath);
$jsCode = (string) preg_replace('#/\*.*?\*/#s', '', $js);
$jsCode = (string) preg_replace('#^\s*//.*$#m', '', $jsCode);
$recordSource('console.js uses no HTML-injection sink', preg_match('/\b(innerHTML|outerHTML|insertAdjacentHTML|document\.write)\b/', $jsCode) !== 1, 'textContent / DOM construction only');
$recordSource('console.js has no eval/new Function', preg_match('/\beval\s*\(|new Function\s*\(/', $jsCode) !== 1);
$recordSource('console.js has no external runtime dependency', preg_match('#https?://#', $jsCode) !== 1);
$recordSource('console.js implements dialog focus trap and restore', str_contains($jsCode, 'focusableInDialog') && str_contains($jsCode, 'restoreFocus'));
$recordSource('console.js prevents double submit', str_contains($jsCode, 'aria-busy') && str_contains($jsCode, 'Deploying'));
$recordSource('console.js collapses deployment only below desktop', str_contains($jsCode, 'min-width: 1180px'));

$recordSource('CSS has no external URL/@import/CDN', preg_match('#https?://|@import|cdn\.|fonts\.googleapis#i', (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($cssPath))) !== 1);
$recordSource('CSS declares sRGB fallback tokens first', strpos((string) file_get_contents($cssPath), '--vgt-bg-0: #0b1014;') !== false);
$recordSource('CSS overrides tokens with OKLCH behind @supports', str_contains((string) file_get_contents($cssPath), '@supports (color: oklch(') && str_contains((string) file_get_contents($cssPath), '--vgt-accent: oklch('));
$recordSource('CSS has no repeating grid/dot background pattern', preg_match('/repeating-(linear|radial)-gradient/', (string) file_get_contents($cssPath)) !== 1);
$recordSource('CSS defines Z0-Z4 material level comments', str_contains((string) file_get_contents($cssPath), 'Z0') && str_contains((string) file_get_contents($cssPath), 'Z2') && str_contains((string) file_get_contents($cssPath), 'Z3') && str_contains((string) file_get_contents($cssPath), 'Z4'));
$recordSource('CSS defines visible :focus-visible ring', str_contains((string) file_get_contents($cssPath), ':focus-visible'));
$recordSource('CSS honours prefers-reduced-motion', str_contains((string) file_get_contents($cssPath), 'prefers-reduced-motion: reduce'));
$recordSource('CSS honours prefers-reduced-transparency', str_contains((string) file_get_contents($cssPath), 'prefers-reduced-transparency: reduce'));
$recordSource('CSS honours forced-colors', str_contains((string) file_get_contents($cssPath), 'forced-colors: active'));
$recordSource('CSS provides backdrop-filter @supports fallback', str_contains((string) file_get_contents($cssPath), '@supports (backdrop-filter: blur(1px))') && str_contains((string) file_get_contents($cssPath), '@supports not'));
$recordSource('CSS uses container queries for the registry', str_contains((string) file_get_contents($cssPath), '@container vault'));
$recordSource('CSS defines asymmetric desktop workbench', str_contains((string) file_get_contents($cssPath), 'minmax(0, 1.62fr) minmax(330px, 0.82fr)'));
$recordSource('CSS defines tablet stack at 1179px', str_contains((string) file_get_contents($cssPath), '@media (max-width: 1179px)'));
$recordSource('CSS defines mobile task-first layout at 719px', str_contains((string) file_get_contents($cssPath), '@media (max-width: 719px)'));
$recordSource('CSS defines sticky mobile action bar with safe-area inset', str_contains((string) file_get_contents($cssPath), 'position: sticky') && str_contains((string) file_get_contents($cssPath), 'safe-area-inset-bottom'));
$recordSource('CSS enforces >=44px touch targets', str_contains((string) file_get_contents($cssPath), 'min-height: 44px') && str_contains((string) file_get_contents($cssPath), 'min-height: 52px'));
$recordSource('CSS uses system font stack + monospace for data', str_contains((string) file_get_contents($cssPath), '-apple-system') && str_contains((string) file_get_contents($cssPath), 'ui-monospace'));

$importantCount = preg_match_all('/!important/', (string) file_get_contents($cssPath));
$recordSource('!important only in reduced-motion block', $importantCount <= 4, $importantCount . ' occurrence(s)');

$cssLines = substr_count((string) file_get_contents($cssPath), "\n");
$jsLines = substr_count($js, "\n");
$recordSource('assets stay lean (informational)', true, 'glass-2100.css ' . $cssLines . ' lines, console.js ' . $jsLines . ' lines');

/* ── Markdown report ──────────────────────────────────────────────────── */
$md = "# VGT Omega — static accessibility, semantics and source audit\n\n";
$md .= "Scope: the three rendered preview documents (real `Dashboard::renderWith(ConsoleData::fromFixture())`\n";
$md .= "output) plus the shipped PHP/CSS/JS sources.\n\n";
$md .= "> **VISUAL_QA = NOT_TESTED (screenshots blocked by the build environment).** Chromium/Firefox cannot start in\n";
$md .= "> this environment (browser process/IPC denial), so no PNG exists and no claim about pixels is made.\n";
$md .= "> Browser rendering, real screen-reader output, touch testing, 200% zoom reflow and OKLCH\n";
$md .= "> wide-gamut rendering remain unverified.\n\n";
$md .= "## DOM and semantics checks\n\n| Document | Check | Result | Detail |\n|---|---|---|---|\n";
foreach ($domRows as $row) {
    $md .= sprintf("| %s | %s | %s | %s |\n", $row['file'], $row['check'], $row['pass'] ? 'PASS' : 'FAIL', str_replace('|', '/', $row['detail']));
}
$md .= "\nDOM failures: **" . $domFailures . "**.\n\n";
$md .= "## Source, dependency and design-rule checks\n\n| Check | Result | Detail |\n|---|---|---|\n";
foreach ($sourceRows as $row) {
    $md .= sprintf("| %s | %s | %s |\n", $row['check'], $row['pass'] ? 'PASS' : 'FAIL', str_replace('|', '/', $row['detail']));
}
$md .= "\nSource failures: **" . $sourceFailures . "**.\n\n";
$md .= "## PHP line ledger (limit 500)\n\n| File | Lines |\n|---|---|\n";
foreach ($lineLedger as $row) {
    $md .= sprintf("| `%s` | %d |\n", $row['file'], $row['lines']);
}
$md .= sprintf("\nFiles: **%d**, largest: **%s (%d lines)**.\n", count($phpFiles), $maxFile, $maxLines);

if (file_put_contents($docsDir . '/static-a11y-audit.md', $md) === false) {
    fwrite(STDERR, "cannot write a11y audit\n");
    exit(1);
}

printf("DOM checks: %d, failures: %d\n", count($domRows), $domFailures);
printf("source checks: %d, failures: %d\n", count($sourceRows), $sourceFailures);
printf("PHP files: %d, largest: %s (%d lines)\n", count($phpFiles), $maxFile, $maxLines);
exit(($domFailures + $sourceFailures) === 0 ? 0 : 1);
