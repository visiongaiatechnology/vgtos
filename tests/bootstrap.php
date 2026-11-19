<?php
/**
 * VGT Omega test bootstrap.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

define('VGT_OMEGA_TESTING', true);

$vgtTestRoot = dirname(__DIR__);
$vgtTestTmp = __DIR__ . '/tmp';
$vgtTestRunId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));

if (!is_dir($vgtTestTmp)) {
    mkdir($vgtTestTmp, 0700, true);
}

define('VGT_TEST_TMP_ROOT', $vgtTestTmp . DIRECTORY_SEPARATOR . 'run-' . $vgtTestRunId);
define('VGT_TEST_PROJECT_ROOT', dirname(__DIR__));

$vgtWpRoot = VGT_TEST_TMP_ROOT . DIRECTORY_SEPARATOR . 'wp';
mkdir($vgtWpRoot . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads', 0700, true);
mkdir(VGT_TEST_TMP_ROOT . DIRECTORY_SEPARATOR . 'mu-plugins', 0700, true);

if (!defined('ABSPATH')) {
    define('ABSPATH', $vgtWpRoot . DIRECTORY_SEPARATOR);
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', $vgtWpRoot . DIRECTORY_SEPARATOR . 'wp-content');
}
if (!defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', VGT_TEST_TMP_ROOT . DIRECTORY_SEPARATOR . 'mu-plugins');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'vgt_test_db');
}
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('VGT_OMEGA_FILE')) {
    define('VGT_OMEGA_FILE', VGT_TEST_PROJECT_ROOT . '/vgt-os.php');
}

require_once VGT_TEST_PROJECT_ROOT . '/src/Bootstrap/Autoloader.php';
\VGT\Omega\Bootstrap\Autoloader::register(VGT_TEST_PROJECT_ROOT);

/*
 * -------------------------------------------------------------------------
 * WordPress function stubs (only for the pure PHP test process).
 * These never ship inside the plugin; they make the host-facing adapters and
 * the admin controller testable without a WordPress installation.
 * -------------------------------------------------------------------------
 */

/** @var array{transients: array<string, mixed>, user_id: int, caps: array<string, bool>} $GLOBALS['vgt_test_wp'] */
$GLOBALS['vgt_test_wp'] = [
    'transients' => [],
    'options' => [],
    'user_id' => 7,
    'caps' => ['activate_plugins' => true, 'manage_options' => true],
];

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return array_key_exists($key, $GLOBALS['vgt_test_wp']['options'])
            ? $GLOBALS['vgt_test_wp']['options'][$key]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['vgt_test_wp']['options'][$key] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        $existed = array_key_exists($key, $GLOBALS['vgt_test_wp']['options']);
        unset($GLOBALS['vgt_test_wp']['options'][$key]);

        return $existed;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $key, $value = '', $deprecated = '', $autoload = 'yes'): bool
    {
        if (array_key_exists($key, $GLOBALS['vgt_test_wp']['options'])) {
            return false;
        }

        $GLOBALS['vgt_test_wp']['options'][$key] = $value;

        return true;
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = false): array
    {
        $base = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/uploads';

        return ['basedir' => $base, 'baseurl' => 'http://example.test/wp-content/uploads', 'error' => false];
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'http://example.test' . $path;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return true;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message = '', int|string $code = 500): never
    {
        throw new \RuntimeException('WP_DIE:' . (int) $code . ':' . $message);
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action = '-1', string $query_arg = '_wpnonce'): bool
    {
        $nonce = $_REQUEST[$query_arg] ?? '';
        if (!is_string($nonce) || !hash_equals('test-valid-nonce', $nonce)) {
            throw new \RuntimeException('WP_DIE:403:CSRF check failed.');
        }

        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['vgt_test_wp']['caps'][$capability] ?? false);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) $GLOBALS['vgt_test_wp']['user_id'];
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return $GLOBALS['vgt_test_wp']['transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        $GLOBALS['vgt_test_wp']['transients'][$key] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $existed = array_key_exists($key, $GLOBALS['vgt_test_wp']['transients']);
        unset($GLOBALS['vgt_test_wp']['transients'][$key]);

        return $existed;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['vgt_test_wp']['redirect'] = $location;

        return true;
    }
}

if (!function_exists('wp_redirect')) {
    function wp_redirect(string $location, int $status = 302): bool
    {
        return wp_safe_redirect($location, $status);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'http://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'http://example.test/wp-content/plugins/vgt-os/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="test-valid-nonce" />';

        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'vgt'], true) ? $url : '';
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, ?int $timestamp = null): string
    {
        return gmdate($format, $timestamp ?? time());
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}

if (!function_exists('is_uploaded_file')) {
    function is_uploaded_file(string $filename): bool
    {
        return is_file($filename);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['vgt_test_wp']['actions'][] = $tag;

        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void
    {
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('did_action')) {
    function did_action(string $tag): int
    {
        return 0;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null): never
    {
        throw new \RuntimeException('WP_SEND_JSON_SUCCESS');
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null): never
    {
        throw new \RuntimeException('WP_SEND_JSON_ERROR');
    }
}

/** Create an isolated temp directory below tests/tmp for one test helper. */
function vgt_test_temp_dir(string $suffix = ''): string
{
    $dir = VGT_TEST_TMP_ROOT . DIRECTORY_SEPARATOR . trim(str_replace(['..', '/', '\\'], '-', $suffix . '-' . bin2hex(random_bytes(4))), '-');

    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new \RuntimeException('Test temp directory could not be created: ' . $dir);
    }

    return $dir;
}

/** Run a shell command with merged stderr; null when process execution is unavailable. */
function vgt_run_command(string $command): ?string
{
    if (!function_exists('shell_exec')) {
        return null;
    }

    $output = @shell_exec($command . ' 2>&1');

    return is_string($output) ? $output : null;
}

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/Support/VaultHarness.php';
