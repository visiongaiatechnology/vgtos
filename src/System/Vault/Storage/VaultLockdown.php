<?php
/**
 * GeDefense WPR vault access lockdown.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Storage;

use VGT\Omega\Core\Exceptions\SecurityException;

/**
 * Writes the defensive web-server configuration into the vault root.
 *
 * Even though the vault is normally provisioned outside the document root,
 * a misconfigured host may still expose it. Three layers deny HTTP access:
 * Apache .htaccess (2.2 and 2.4 syntax in one file), IIS web.config URL
 * authorization and an index.php that answers 403 for directory requests.
 *
 * Files are managed: missing or outdated content is replaced atomically through
 * a temp file plus rename(). File mode is 0644, directory mode 0700, never 0777.
 */
final class VaultLockdown
{
    private const HTACCESS = <<<'HTACCESS'
# GeDefense WPR vault lockdown (managed file, do not edit)
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
Options -Indexes
HTACCESS;

    private const WEB_CONFIG = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <security>
            <authorization>
                <deny users="*" />
            </authorization>
        </security>
        <directoryBrowse enabled="false" />
    </system.webServer>
</configuration>
XML;

    private const INDEX_PHP = <<<'PHP'
<?php
// GeDefense WPR vault lockdown (managed file, do not edit).
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo 'Forbidden';
exit;
PHP;

    /**
     * @throws SecurityException when a lockdown file path is an unexpected link
     * @throws \VGT\Omega\Core\Exceptions\StorageException when the vault is unwritable
     */
    public function apply(VaultPaths $paths): void
    {
        $root = SafeFilesystem::ensureDirectory($paths->vaultDir, 0700);

        $this->ensureFile($root, '.htaccess', self::HTACCESS, 0644);
        $this->ensureFile($root, 'web.config', self::WEB_CONFIG, 0644);
        $this->ensureFile($root, 'index.php', self::INDEX_PHP, 0644);
    }

    private function ensureFile(string $root, string $name, string $contents, int $mode): void
    {
        $target = $root . '/' . $name;

        if (is_link($target)) {
            throw new SecurityException('Vault lockdown path is a symbolic link.');
        }

        if (is_file($target)) {
            $existing = @file_get_contents($target);

            if (is_string($existing) && $existing === $contents) {
                return;
            }
        }

        SafeFilesystem::writeAtomic($target, $contents, $mode);
    }
}
