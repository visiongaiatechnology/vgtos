<?php
declare(strict_types=1);

namespace VGT\Omega\System;

/**
 * STATUS: DIAMANT VGT SUPREME
 * Verwaltet den physikalischen Speicher, HKDF-Verschlüsselung, AAD-Binding und Stream-Mounting.
 * KERNEL UPGRADES:
 * - Sovereign Cryptographic Salt (Isoliert von WP AUTH_KEY Rotation).
 * - Advanced Zip Slip Guard (Windows Absolute Path Protection).
 * - Zero-Downtime Artifact Rotation & Naming Registry.
 * - Zero-I/O Virtual File System (SSD-Bypass für extreme RPS).
 */
final class VaultManager {
    private static array $registry = [];
    private static array $keys = [];
    private static bool $booted = false;

    private static function getBaseDir(): string {
        $upload = \wp_upload_dir();
        return wp_normalize_path($upload['basedir'] . '/vgt-vault');
    }

    private static function getBaseUrl(): string {
        $upload = \wp_upload_dir();
        return $upload['baseurl'] . '/vgt-vault';
    }

    public static function boot(): void {
        if (self::$booted) return; 
        self::$booted = true;

        self::secureVault();
        self::loadKeys();
        self::mountArtifacts();
        
        \add_filter('kses_allowed_protocols', function(array $p) { $p[] = 'vgt'; return $p; });
        \add_filter('plugins_url', [self::class, 'rewritePluginUrl'], 9999, 3);
        \add_filter('plugin_dir_url', [self::class, 'rewritePluginUrl'], 9999, 3);
        \add_filter('style_loader_src', [self::class, 'sanitizeAssetUrl'], 9999);
        \add_filter('script_loader_src', [self::class, 'sanitizeAssetUrl'], 9999);
        \add_filter('clean_url', [self::class, 'sanitizeAssetUrl'], 9999, 1);
        
        self::registerHooks();
    }

    public static function registerHooks(): void {
        \add_action('admin_post_vgt_install_artifact', [self::class, 'installArtifact']);
        \add_action('admin_post_vgt_delete_artifact', [self::class, 'deleteArtifact']);

        \add_filter('all_plugins', [self::class, 'injectVirtualPlugins']);
        \add_filter('plugin_action_links', [self::class, 'lockVirtualControls'], 10, 2);
        \add_filter('network_admin_plugin_action_links', [self::class, 'lockVirtualControls'], 10, 2);
        
        \add_action('admin_head-plugins.php', [self::class, 'styleVirtualPlugins']);
    }

    /* ====================================================================
     * METADATA REGISTRY (NAMING & ROTATION TRACKING)
     * ==================================================================== */

    public static function getArtifactMeta(string $id): array {
        $meta = \get_option('vgt_vault_meta', []);
        if (isset($meta[$id])) {
            return $meta[$id];
        }
        return [
            'name' => 'VGT Artifact: ' . strtoupper(substr($id, 0, 8)),
            'updated' => 0
        ];
    }

    private static function updateArtifactMeta(string $id, string $custom_name): void {
        $meta = \get_option('vgt_vault_meta', []);
        
        if (!empty($custom_name)) {
            $meta[$id]['name'] = $custom_name;
        } elseif (empty($meta[$id]['name'])) {
            $meta[$id]['name'] = 'VGT Artifact: ' . strtoupper(substr($id, 0, 8));
        }
        
        $meta[$id]['updated'] = time();
        \update_option('vgt_vault_meta', $meta);
    }

    private static function deleteArtifactMeta(string $id): void {
        $meta = \get_option('vgt_vault_meta', []);
        if (isset($meta[$id])) {
            unset($meta[$id]);
            \update_option('vgt_vault_meta', $meta);
        }
    }

    /* ====================================================================
     * KRYPTOGRAFIE KERNEL & STATE MANAGEMENT
     * ==================================================================== */

    public static function isUnlocked(string $id): bool {
        return isset(self::$keys[$id]);
    }

    private static function secureVault(): void {
        $base = self::getBaseDir();
        if (!is_dir($base)) \wp_mkdir_p($base);
        
        $htaccess = $base . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order Allow,Deny\nAllow from all\n<Files *.php>\nDeny from all\n</Files>");
        }

        $index = $base . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n/** VGT OMEGA KERNEL - ACCESS DENIED */\nheader('HTTP/1.1 403 Forbidden');\nexit('VGT SECURE VAULT');\n");
        }
    }

    private static function getMasterKey(): string {
        $vgt_salt = \get_option('vgt_vault_master_salt');
        if (empty($vgt_salt)) {
            try {
                $vgt_salt = bin2hex(random_bytes(32));
            } catch (\Exception $e) {
                $vgt_salt = hash('sha512', uniqid((string)mt_rand(), true));
            }
            \update_option('vgt_vault_master_salt', $vgt_salt);
        }
        return hash_hkdf('sha256', $vgt_salt, 32, 'vgt_omega_master_key_v1');
    }

    private static function loadKeys(): void {
        $stored = \get_option('vgt_vault_keys', []);
        $master_key = self::getMasterKey();

        foreach ($stored as $id => $enc_blob) {
            $raw = base64_decode($enc_blob);
            $raw_len = function_exists('mb_strlen') ? mb_strlen($raw, '8bit') : strlen($raw);
            
            if ($raw_len > 28) {
                $iv = substr($raw, 0, 12);
                $tag = substr($raw, 12, 16);
                $ciphertext = substr($raw, 28);
                $aad = "vgt_bind_" . $id;
                
                $key = openssl_decrypt($ciphertext, 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
                
                if ($key !== false) {
                    $key_len = function_exists('mb_strlen') ? mb_strlen($key, '8bit') : strlen($key);
                    
                    if ($key_len === 64 && ctype_xdigit($key)) {
                        self::$keys[$id] = hex2bin($key);
                    } elseif ($key_len === 32) {
                        self::$keys[$id] = $key;
                    }
                }
                unset($raw, $iv, $tag, $ciphertext, $key);
            }
        }
    }

    private static function storeKey(string $id, string $raw_key): void {
        $keys = \get_option('vgt_vault_keys', []);
        $master_key = self::getMasterKey();
        
        $iv = random_bytes(12);
        $tag = ""; 
        $aad = "vgt_bind_" . $id; 
        
        $encrypted_key = openssl_encrypt($raw_key, 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        
        if ($encrypted_key !== false) {
            $keys[$id] = base64_encode($iv . $tag . $encrypted_key);
            \update_option('vgt_vault_keys', $keys);
        }
    }

    /* ====================================================================
     * STREAM MOUNTING & DECRYPTION
     * ==================================================================== */

    private static function mountArtifacts(): void {
        $base = self::getBaseDir();
        if (!is_dir($base)) return;
        
        $dirs = glob($base . '/*', GLOB_ONLYDIR);
        if (!$dirs) return;

        if (!in_array('vgt', stream_get_wrappers())) {
            stream_wrapper_register('vgt', StreamWrapper::class);
        }

        foreach ($dirs as $dir) {
            $id = basename($dir);
            $manifest_file = $dir . '/vgt_manifest.vgt';
            
            if (!file_exists($manifest_file) && file_exists($dir . '/plugin.php')) {
                require_once $dir . '/plugin.php';
                continue;
            }

            if (!self::isUnlocked($id) || !file_exists($manifest_file)) continue;

            $raw = self::decryptFile($manifest_file, self::$keys[$id], false);
            if (!$raw) continue;

            $manifest = json_decode($raw, true);
            unset($raw); 

            if (!$manifest) continue;

            self::$registry[$id] = ['root' => wp_normalize_path($dir), 'map' => $manifest['files'], 'key' => self::$keys[$id]];
            
            $entry = '/' . ltrim($manifest['entry'], '/'); 
            try {
                include_once "vgt://{$id}{$entry}";
            } catch (\Throwable $e) {
                error_log("VGT BOOT ERROR [{$id}]: " . $e->getMessage());
            }
        }
    }

    public static function decryptFile(string $path, string $key, bool $has_header = true): string|false {
        $content = @file_get_contents($path);
        if (!$content) return false;
        
        if ($has_header) {
            $pos = strpos($content, 'VGT_GUARD');
            if ($pos !== false) $content = substr($content, $pos + 9); 
        }

        if (strlen($content) < 28) return false;
        $iv = substr($content, 0, 12);
        $tag = substr($content, 12, 16);
        $ciphertext = substr($content, 28);
        
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        unset($content, $ciphertext, $iv, $tag); 

        if ($decrypted === false) return false;
        
        $result = gzdecode($decrypted);
        unset($decrypted); 
        
        return $result;
    }

    /* ====================================================================
     * URL REWRITE ENGINE & CORE FILTERING
     * ==================================================================== */

    public static function rewritePluginUrl(string $url, string $path = '', string $plugin = ''): string {
        $plugin = rawurldecode($plugin);
        if (strpos($plugin, 'vgt://') === false) return $url;

        $clean_plugin = str_replace(['vgt://', 'http://', 'https://'], '', $plugin);
        if (strpos($clean_plugin, '/vgt://') !== false) {
            $parts = explode('/vgt://', $clean_plugin);
            $clean_plugin = end($parts);
        }

        $base_dir_rel = dirname($clean_plugin); 
        if ($base_dir_rel === '.') $base_dir_rel = '';

        $target_rel = !empty($path) ? $base_dir_rel . '/' . $path : $base_dir_rel;
        return self::resolveVaultUrl($target_rel);
    }

    public static function sanitizeAssetUrl(string $src): string {
        if (strpos($src, 'vgt://') !== false || strpos($src, 'vgt%3A%2F%2F') !== false) {
            $decoded = rawurldecode($src);
            if (preg_match('/vgt:\/\/([^\s"\']+)/', $decoded, $matches)) {
                return self::resolveVaultUrl($matches[1]);
            }
        }
        return $src;
    }

    private static function resolveVaultUrl(string $rel_path): string {
        $parts = explode('/', $rel_path, 2);
        if (count($parts) < 1) return '';
        
        $artifact_id = $parts[0];
        $internal_path = $parts[1] ?? '';

        $query = '';
        if (strpos($internal_path, '?') !== false) {
            [$internal_path, $query] = explode('?', $internal_path, 2);
            $query = '?' . $query;
        }

        $stack = [];
        foreach (explode('/', str_replace('\\', '/', $internal_path)) as $part) {
            if ($part === '..') {
                if (!empty($stack)) array_pop($stack);
            } elseif ($part !== '.' && $part !== '') {
                $stack[] = $part;
            }
        }
        $resolved_path = implode('/', $stack) . $query;
        
        $final_url = trailingslashit(self::getBaseUrl()) . $artifact_id . '/' . $resolved_path;
        return \is_ssl() ? str_replace('http://', 'https://', $final_url) : $final_url;
    }

    /* ====================================================================
     * INSTALLER LOGIC (ZERO-DOWNTIME ROTATION ENABLED)
     * ==================================================================== */

    public static function getRegistry(string $id): ?array { return self::$registry[$id] ?? null; }
    
    public static function getArtifactsList(): array {
        $base = self::getBaseDir();
        if (!is_dir($base)) return [];
        
        $res = [];
        $items = @scandir($base);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                if (is_dir($base . '/' . $item)) {
                    $res[$item] = true;
                }
            }
        }
        return $res;
    }

    public static function installArtifact(): void {
        \check_admin_referer('vgt_action');
        if (!\current_user_can('activate_plugins')) \wp_die('Access Denied');
        
        $file = $_FILES['artifact'];
        $raw_key = trim(\sanitize_text_field($_POST['license_key'] ?? $_POST['master_key'] ?? $_POST['key'] ?? ''));
        
        $rotate_id = \sanitize_text_field($_POST['rotate_id'] ?? '');
        $custom_name = \sanitize_text_field($_POST['artifact_name'] ?? '');
        
        if (empty($file['tmp_name'])) \wp_die('VGT SECURITY: Missing File Data');
        if (empty($raw_key)) \wp_die('VGT SECURITY: CRITICAL ABORT - Master Key was not transmitted correctly.');
        
        $vault_dir = self::getBaseDir();
        $zip = new \ZipArchive;
        
        if ($zip->open($file['tmp_name']) === TRUE) {
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                $fname = $info['name'];
                if (str_contains($fname, '../') || str_starts_with($fname, '/') || preg_match('/^[a-zA-Z]:\\\\/', $fname)) {
                    $zip->close();
                    \wp_die('VGT SECURITY: CRITICAL - Zip Slip Attack / Path Traversal detected in Archive. Installation Aborted.');
                }
            }

            if (!empty($rotate_id)) {
                $target = $vault_dir . '/' . $rotate_id;
                if (!is_dir($target)) {
                    $zip->close();
                    \wp_die('VGT SECURITY: Invalid Rotation Target. The specified Artifact ID does not exist.');
                }
                $art_id = $rotate_id;
                self::emptyDir($target);
                $msg_status = 'rotated';
            } else {
                $art_id = uniqid('vgt_'); 
                $target = $vault_dir . '/' . $art_id;
                \wp_mkdir_p($target);
                $msg_status = 'installed';
            }
            
            if (!$zip->extractTo($target)) {
                \wp_die('VGT SECURITY: Artifact Extraction Failed. Path Permissions Issue.');
            }
            $zip->close();
            
            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }
            
            @file_put_contents($target . '/index.php', "<?php header('HTTP/1.1 403 Forbidden'); exit('VGT OMEGA BLOCK');");
            
            $clean_key = preg_replace('/[^a-fA-F0-9]/', '', $raw_key);
            if (strlen($clean_key) === 64) {
                self::storeKey($art_id, hex2bin($clean_key));
            } else {
                self::rrmdir($target);
                \wp_die('VGT SECURITY: Invalid Master Key format. Must be exactly 64 HEX characters.');
            }

            self::updateArtifactMeta($art_id, $custom_name);
            
            \wp_redirect(\admin_url('admin.php?page=vgt-console&msg=' . $msg_status));
            exit;
        }
        \wp_die('VGT SECURITY: Invalid Zip File Format');
    }

    public static function deleteArtifact(): void {
        \check_admin_referer('vgt_action');
        if (!\current_user_can('activate_plugins')) \wp_die('Access Denied');
        
        $id = basename(\sanitize_text_field($_POST['artifact_id']));
        
        if (empty($id) || $id === '.' || $id === '..') \wp_die('VGT SECURITY: Invalid Artifact ID');

        $vault_dir = self::getBaseDir();
        self::rrmdir($vault_dir . '/' . $id);

        $keys = \get_option('vgt_vault_keys', []);
        if (isset($keys[$id])) {
            unset($keys[$id]);
            \update_option('vgt_vault_keys', $keys);
        }

        self::deleteArtifactMeta($id);
        
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        \wp_redirect(\admin_url('admin.php?page=vgt-console&msg=deleted'));
        exit;
    }

    private static function rrmdir(string $dir): void { 
        if (!is_dir($dir)) return;
        self::emptyDir($dir);
        rmdir($dir); 
    }

    private static function emptyDir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $obj) { 
            if ($obj != "." && $obj != "..") { 
                $path = $dir . "/" . $obj;
                is_dir($path) && !is_link($path) ? self::rrmdir($path) : unlink($path); 
            } 
        }
    }

    /* ====================================================================
     * GHOST INJECTION (NATIVE UI INTEGRATION)
     * ==================================================================== */

    public static function injectVirtualPlugins(array $plugins): array {
        $artifacts = self::getArtifactsList();
        
        foreach ($artifacts as $id => $val) {
            $virtual_file = "vgt-protected/{$id}.php";
            
            $is_unlocked = self::isUnlocked($id);
            $meta = self::getArtifactMeta((string)$id);
            
            $status_html = $is_unlocked 
                ? '<span style="color:#10b981; font-weight:800; letter-spacing:0.5px;">● SECURE RUNTIME ACTIVE</span>' 
                : '<span style="color:#ef4444; font-weight:800; letter-spacing:0.5px;">● LOCKED (MISSING KEY)</span>';

            $plugins[$virtual_file] = [
                'Name'        => "🛡️ " . esc_html($meta['name']),
                'PluginURI'   => \admin_url('admin.php?page=vgt-console'),
                'Version'     => 'Secure Stream',
                'Description' => "🔒 Encrypted Payload running directly in VGT Memory Stream. (ID: {$id})<br>{$status_html}",
                'Author'      => 'VGT Omega System',
                'AuthorURI'   => 'https://visiongaiatechnology.de',
                'TextDomain'  => '',
                'DomainPath'  => '',
                'Network'     => false,
                'Title'       => "🛡️ " . esc_html($meta['name']),
                'AuthorName'  => 'VGT Omega System',
            ];
        }
        return $plugins;
    }

    public static function lockVirtualControls(array $actions, string $plugin_file): array {
        if (strpos($plugin_file, 'vgt-protected/') === 0) {
            return [
                'manage' => '<a href="' . \admin_url('admin.php?page=vgt-console') . '" style="color:#10b981; font-weight:700;">Manage in Vault</a>'
            ];
        }
        return $actions;
    }

    public static function styleVirtualPlugins(): void {
        echo '<style>
            tr[data-plugin^="vgt-protected/"] { background-color: rgba(16, 185, 129, 0.05) !important; border-left: 4px solid #10b981 !important; }
            tr[data-plugin^="vgt-protected/"] th, tr[data-plugin^="vgt-protected/"] td { box-shadow: none !important; }
        </style>';
    }
}

/* ====================================================================
 * HIGH-PERFORMANCE VIRTUAL FILE SYSTEM (STREAM WRAPPER)
 * ==================================================================== */

class StreamWrapper {
    public $context;
    private int $position = 0;
    private string $buffer = '';
    private string $artifact_id = '';
    private string $virtual_path = '';
    private array $dir_list = [];
    private int $dir_idx = 0;

    private function parsePath(string $path): array|false {
        $path = str_replace('vgt://', '', $path);
        $parts = explode('/', $path, 2);
        if (count($parts) < 1) return false;
        
        $id = $parts[0];
        $sub = isset($parts[1]) ? '/' . $parts[1] : '/';
        
        // Strict Virtual Path Resolution (Prevents Traversal)
        $stack = [];
        foreach (explode('/', $sub) as $seg) {
            if ($seg == '..') {
                if (!empty($stack)) array_pop($stack);
            } elseif ($seg != '.' && $seg != '') {
                array_push($stack, $seg);
            }
        }
        return ['id' => $id, 'path' => '/' . implode('/', $stack)];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        $parsed = $this->parsePath($path);
        if (!$parsed) return false;

        $this->artifact_id = $parsed['id'];
        $this->virtual_path = $parsed['path'];

        $reg = VaultManager::getRegistry($this->artifact_id);
        if (!$reg) return false;

        // [ DIAMANT VGT FIX: ZERO-I/O RAM BRIDGE ]
        // Wir prüfen APCu BEVOR wir die physische Disk via file_exists/realpath konsultieren.
        // Das eliminiert IOPS-Flaschenhälse bei massiven include_once() Aufrufen.
        $use_apcu = function_exists('apcu_fetch');
        $cache_key = 'vgt_str_v2_' . md5($this->artifact_id . '|' . $this->virtual_path . '|' . $reg['key']);

        if ($use_apcu) {
            $cached_buffer = apcu_fetch($cache_key);
            if ($cached_buffer !== false) {
                $this->buffer = $cached_buffer;
                $this->position = 0;
                $opened_path = $path;
                return true;
            }
        }

        // --- PHYSICAL DISK FALLBACK ---
        $physical = wp_normalize_path($reg['root'] . $this->virtual_path);
        
        if (!file_exists($physical)) return false;
        
        $real_physical = wp_normalize_path(realpath($physical));
        $real_root = wp_normalize_path(realpath($reg['root']));
        
        if ($real_physical === false || $real_root === false) return false;
        
        $is_inside = str_starts_with($real_physical, $real_root . '/');
        $is_root   = ($real_physical === $real_root);
        
        if (!$is_inside && !$is_root) return false;

        $clean_lookup = ltrim($this->virtual_path, '/'); 
        $type = $reg['map'][$clean_lookup] ?? ($reg['map'][$this->virtual_path] ?? 'raw');

        if ($type === 'encrypted') {
            $decrypted = VaultManager::decryptFile($physical, $reg['key'], true);
            if ($decrypted === false) return false;
            $this->buffer = $decrypted;
            unset($decrypted);
        } else {
            $content = file_get_contents($physical);
            if ($content === false) return false;
            $this->buffer = $content;
            unset($content);
        }
        
        if ($use_apcu) {
            apcu_store($cache_key, $this->buffer, 3600);
        }
        
        $this->position = 0;
        $opened_path = $path;
        return true;
    }

    public function stream_read(int $count): string {
        $ret = substr($this->buffer, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_eof(): bool { return $this->position >= strlen($this->buffer); }
    
    // [ DIAMANT VGT FIX: ZERO-I/O STAT BRIDGE ]
    // PHP's include_once ruft zwingend stream_stat auf. Liefern wir den Cache.
    public function stream_stat(): array|false { 
        return $this->getStatArray(strlen($this->buffer)); 
    }
    
    public function stream_set_option(int $option, int $arg1, int $arg2): bool { return true; } 
    public function stream_metadata(string $path, int $option, mixed $value): bool { return true; }
    public function stream_lock(int $operation): bool { return false; }
    
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
        $len = strlen($this->buffer);
        switch ($whence) {
            case SEEK_SET: $this->position = $offset; break;
            case SEEK_CUR: $this->position += $offset; break;
            case SEEK_END: $this->position = $len + $offset; break;
        }
        return $this->position >= 0 && $this->position <= $len;
    }
    
    public function stream_tell(): int { return $this->position; }
    public function stream_flush(): bool { return true; }
    public function stream_write(string $data): int { return 0; }

    // [ DIAMANT VGT FIX: ZERO-I/O URL STAT BRIDGE ]
    // PHP ruft url_stat IMMER vor dem stream_open auf. Ohne Cache haben wir hier massiven SSD-Overhead.
    public function url_stat(string $path, int $flags): array|false {
        $parsed = $this->parsePath($path);
        if (!$parsed) return false;
        
        $reg = VaultManager::getRegistry($parsed['id']);
        if (!$reg) return false;

        $use_apcu = function_exists('apcu_fetch');
        $cache_key = 'vgt_stat_v2_' . md5($parsed['id'] . '|' . $parsed['path']);

        if ($use_apcu) {
            $cached_stat = apcu_fetch($cache_key);
            if ($cached_stat !== false) return $cached_stat;
        }

        // --- PHYSICAL DISK FALLBACK ---
        $physical = wp_normalize_path($reg['root'] . $parsed['path']);
        if (!file_exists($physical)) return false;

        $real_physical = wp_normalize_path(realpath($physical));
        $real_root = wp_normalize_path(realpath($reg['root']));
        
        if ($real_physical === false || $real_root === false) return false;
        if (!str_starts_with($real_physical, $real_root . '/') && $real_physical !== $real_root) return false;

        $stat = is_dir($physical) ? $this->getStatArray(0, true) : $this->getStatArray(1024);
        
        if ($use_apcu) {
            apcu_store($cache_key, $stat, 3600);
        }
        
        return $stat;
    }

    private function getStatArray(int $size, bool $is_dir = false): array {
        $time = time();
        return [
            'dev' => 0, 'ino' => 0, 'mode' => $is_dir ? 0040755 : 0100644, 
            'nlink' => 0, 'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => $size,
            'atime' => $time, 'mtime' => $time, 'ctime' => $time, 
            'blksize' => -1, 'blocks' => -1
        ];
    }

    public function dir_opendir(string $path, int $options): bool {
        $parsed = $this->parsePath($path);
        if (!$parsed) return false;
        $reg = VaultManager::getRegistry($parsed['id']);
        if (!$reg) return false;
        
        $physical = wp_normalize_path($reg['root'] . $parsed['path']);
        if (!is_dir($physical)) return false;
        
        $real_physical = wp_normalize_path(realpath($physical));
        $real_root = wp_normalize_path(realpath($reg['root']));
        
        if ($real_physical === false || $real_root === false || (!str_starts_with($real_physical, $real_root . '/') && $real_physical !== $real_root)) {
            return false;
        }
        
        $scanned = scandir($physical);
        $this->dir_list = $scanned !== false ? $scanned : [];
        $this->dir_idx = 0;
        return true;
    }
    
    public function dir_readdir(): string|false { 
        if ($this->dir_idx >= count($this->dir_list)) return false;
        return $this->dir_list[$this->dir_idx++];
    }
    
    public function dir_closedir(): bool { return true; }
    public function dir_rewinddir(): bool { $this->dir_idx = 0; return true; }
}
