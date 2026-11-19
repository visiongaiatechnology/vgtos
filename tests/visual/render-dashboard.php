<?php
/**
 * GeDefense WPR — standalone visual QA harness.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$previewDir = __DIR__;
$cssRelative = '../../src/UI/Dashboard/assets/glass-2100.css';
$jsRelative = '../../src/UI/Dashboard/assets/console.js';

/* ── WordPress stubs: the preview must never need a WP install ────────── */
if (!function_exists('esc_html')) {
    /** @param mixed $text */
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /** @param mixed $text */
    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    /** @param mixed $url */
    function esc_url($url): string
    {
        return htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        // The visual harness serves shipped assets from the repository root.
        if (str_starts_with($path, 'assets/')) {
            return '../../' . ltrim($path, '/');
        }

        return 'https://example.test/wp-content/plugins/ge-defense-wpr/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = 'vgt_action', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="preview-nonce-' . esc_attr($action) . '">';
        if ($display) {
            echo $field;

            return '';
        }

        return $field;
    }
}

if (!function_exists('wp_die')) {
    /** @param array<string, mixed> $args */
    function wp_die(string $message = '', string $title = '', array $args = []): never
    {
        echo '<h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p>';
        exit(1);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 1;
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, int|false $timestamp = false): string
    {
        return gmdate($format, is_int($timestamp) ? $timestamp : 1795100000);
    }
}

if (!function_exists('add_query_arg')) {
    /** @param array<string, string>|string $args */
    function add_query_arg(array|string $args = [], string $url = ''): string
    {
        return 'https://example.test/wp-admin/admin.php?page=vgt-console';
    }
}

require_once $projectRoot . '/src/Bootstrap/Autoloader.php';
\VGT\Omega\Bootstrap\Autoloader::register($projectRoot);

use VGT\Omega\UI\Dashboard\ConsoleData;
use VGT\Omega\UI\Dashboard\ConsoleFixture;
use VGT\Omega\UI\Dashboard\Dashboard;

/**
 * Compose a complete preview document around one rendered console snapshot.
 *
 * @param string $title       page title
 * @param ConsoleData $data   console snapshot
 * @param string $cssRelative relative CSS path from the preview directory
 * @param string $jsRelative  relative JS path from the preview directory
 * @param bool   $openDialog  additionally open the Z4 dialog for capture
 */
function vgt_render_preview(
    string $title,
    ConsoleData $data,
    string $cssRelative,
    string $jsRelative,
    bool $openDialog = false
): string {
    ob_start();
    Dashboard::renderWith($data);
    $console = (string) ob_get_clean();

    $shell = <<<'HTML'
    <style>
      html, body { margin: 0; padding: 0; background: #0a0f12; }
      body.vgt-preview {
        padding: 20px clamp(12px, 2vw, 28px) 48px;
        background:
          radial-gradient(120% 90% at 8% -12%, rgba(42, 168, 151, 0.12), transparent 56%),
          #0a0f12;
      }
      .vgt-preview-bar {
        display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 20px;
        max-width: 1720px; margin: 0 auto 10px;
        font: 500 10.5px/1.4 ui-monospace, "Cascadia Mono", Consolas, monospace;
        letter-spacing: 0.14em; text-transform: uppercase; color: #cbd7db;
      }
      .vgt-preview-bar b { color: #f1f6f7; font-weight: 600; }
    </style>
HTML;

    $dialogScript = $openDialog
        ? '<script>try { document.querySelector(".vgt-delete-form button[type=submit]").click(); } catch (error) {}</script>'
        : '';

    return '<!DOCTYPE html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . esc_html($title) . '</title>'
        . '<link rel="stylesheet" href="' . esc_attr($cssRelative) . '">'
        . $shell
        . '</head><body class="vgt-preview">'
        . '<div class="vgt-preview-bar">'
        . '<span><b>GeDefense WPR</b> &middot; Glass 2100 preview harness &middot; WordPress admin chrome omitted</span>'
        . '<span id="vgt-preview-viewport"></span>'
        . '</div>'
        . $console
        . '<script>document.getElementById("vgt-preview-viewport").textContent ='
        . ' window.innerWidth + " \u00d7 " + window.innerHeight + " px";</script>'
        . '<script src="' . esc_attr($jsRelative) . '"></script>'
        . $dialogScript
        . '</body></html>';
}

/* ── Fixtures ─────────────────────────────────────────────────────────── */
$readyData = ConsoleData::fromFixture();
$degradedData = ConsoleData::fromFixture(ConsoleFixture::offlineOverrides());

$previews = [
    'dashboard-preview.html' => ['GeDefense WPR — console preview', $readyData, false],
    'dashboard-preview-safe-mode.html' => ['GeDefense WPR — safe mode / boot failure preview', $degradedData, false],
    'dashboard-preview-dialog.html' => ['GeDefense WPR — delete confirmation dialog preview', $readyData, true],
];

$failures = [];

foreach ($previews as $fileName => [$title, $data, $openDialog]) {
    $html = vgt_render_preview($title, $data, $cssRelative, $jsRelative, $openDialog);
    $target = $previewDir . DIRECTORY_SEPARATOR . $fileName;
    $bytes = file_put_contents($target, $html);

    if ($bytes === false || $bytes < 4000) {
        $failures[] = $fileName . ': write failed or output implausibly small';
        continue;
    }

    $checks = ['vgt-console', 'GeDefense WPR', 'vgt-seal__logo', 'Artifact vault', 'Deploy or rotate artifact', 'System diagnostics'];
    if ($openDialog) {
        $checks[] = 'vgt-confirm-dialog';
    }

    foreach ($checks as $needle) {
        if (!str_contains($html, $needle)) {
            $failures[] = $fileName . ': missing marker "' . $needle . '"';
        }
    }

    printf("wrote %-38s %7d bytes  %s\n", $fileName, $bytes, $data->state);
}

if ($failures !== []) {
    fwrite(STDERR, "PREVIEW FAILED\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "preview OK: 3 documents\n";
echo 'WordPress available: ' . (function_exists('wp_nonce_field') ? 'stubbed only' : 'no') . "\n";
