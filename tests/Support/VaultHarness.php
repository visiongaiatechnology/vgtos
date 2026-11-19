<?php
/**
 * VGT Omega test harness: builds a real vault subsystem in an isolated temp dir.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Bridge\Adapters\StandaloneAdapter;
use VGT\Omega\Bridge\Environments\StandaloneEnvironment;
use VGT\Omega\System\Vault\ArtifactMounter;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\Crypto\KeyDerivation;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\ManifestRegistry;
use VGT\Omega\System\Vault\Storage\ArchiveLimits;
use VGT\Omega\System\Vault\Storage\ArtifactInstaller;
use VGT\Omega\System\Vault\Storage\ArtifactRepository;
use VGT\Omega\System\Vault\Storage\SafeArchive;
use VGT\Omega\System\Vault\Storage\VaultLocator;
use VGT\Omega\System\Vault\Storage\VaultLockdown;
use VGT\Omega\System\Vault\Storage\VaultPaths;
use VGT\Omega\System\Vault\Support\MemoryGuard;
use VGT\Omega\Tooling\ArtifactPacker;

/**
 * Integration harness around the real production classes.
 */
final class VaultHarness
{
    /**
     * Synthetic test-only AES key. Clearly artificial and never valid for a
     * production vault; the harness generates its own artifacts around it.
     */
    public const CLOUD_KEY_HEX = '6d1f4a8c9e2b7d3f5a0c6e8b1d4f7a2c5e9b3d6f8a1c4e7b0d2f5a8c3e6b9d1f';

    public readonly StandaloneEnvironment $environment;

    public readonly StandaloneAdapter $bridge;

    public readonly VaultPaths $paths;

    public readonly ArtifactRepository $store;

    public readonly CryptoEngine $crypto;

    public readonly KeyDerivation $keys;

    public readonly SignatureVerifier $signatures;

    public readonly ArtifactInstaller $installer;

    public readonly ManifestRegistry $registry;

    public readonly ArtifactMounter $mounter;

    public readonly MemoryGuard $memory;

    public readonly string $baseDir;

    public readonly string $documentRoot;

    public function __construct(
        bool $requireSignatures = false,
        ?ArchiveLimits $archiveLimits = null,
        bool $knownDocumentRoot = true
    ) {
        $this->baseDir = vgt_test_temp_dir('vault');
        $this->documentRoot = $this->baseDir . '/public_html';
        $wpRoot = $this->documentRoot . '/wp';

        if (!is_dir($wpRoot)) {
            mkdir($wpRoot, 0700, true);
        }

        $this->environment = new StandaloneEnvironment(
            $wpRoot,
            'http://example.test',
            null,
            $knownDocumentRoot ? $this->documentRoot : ''
        );
        $this->bridge = new StandaloneAdapter($this->environment);
        $this->paths = (new VaultLocator($this->environment))->locate();
        (new VaultLockdown())->apply($this->paths);

        $this->store = new ArtifactRepository($this->paths, $this->bridge, $this->environment);
        $this->memory = new MemoryGuard();
        $this->crypto = new CryptoEngine(
            \VGT\Omega\System\Vault\VaultFormat::MAX_PLAINTEXT_FILE_BYTES,
            \VGT\Omega\System\Vault\VaultFormat::RAW_COMPRESSION_THRESHOLD,
            $this->memory
        );
        $this->keys = new KeyDerivation($this->environment, $this->bridge);
        $this->signatures = new SignatureVerifier($requireSignatures);
        $this->installer = new ArtifactInstaller(
            $this->paths,
            $this->store,
            $this->crypto,
            $this->keys,
            $this->signatures,
            $this->bridge,
            new SafeArchive($archiveLimits),
            $this->memory
        );
        $this->registry = new ManifestRegistry();
        $this->mounter = new ArtifactMounter(
            $this->store,
            $this->crypto,
            $this->keys,
            $this->registry,
            $this->signatures,
            $this->bridge
        );
    }

    /**
     * Pack a temporary source tree into a real .zip with the reference encryptor.
     *
     * @param array<string, string> $files relative path => content
     * @param array<string, string> $classmap
     */
    public function packArtifact(
        array $files,
        string $entry = 'plugin.php',
        string $name = 'Test Artifact',
        ?string $ed25519SecretKeyBase64 = null,
        array $classmap = []
    ): string {
        $source = vgt_test_temp_dir('source');

        foreach ($files as $relative => $content) {
            $target = $source . '/' . $relative;
            $directory = dirname($target);
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($target, $content);
        }

        $zip = vgt_test_temp_dir('package') . '/artifact.zip';
        $packer = new ArtifactPacker();
        $packer->pack(
            $source,
            $zip,
            self::CLOUD_KEY_HEX,
            $entry,
            $name,
            $ed25519SecretKeyBase64,
            $classmap
        );

        return $zip;
    }

    /**
     * @param array<string, string> $files
     * @return array{id: string, status: string, signed: bool, files: int}
     */
    public function installFiles(
        array $files,
        string $entry = 'plugin.php',
        string $name = 'Test Artifact',
        ?string $ed25519SecretKeyBase64 = null,
        array $classmap = [],
        ?string $rotateId = null,
        ?string $cloudKeyHex = null
    ): array {
        $zip = $this->packArtifact($files, $entry, $name, $ed25519SecretKeyBase64, $classmap);

        return $this->installer->install($zip, $cloudKeyHex ?? self::CLOUD_KEY_HEX, $name, $rotateId);
    }

    /** Mount every installed artifact (idempotent enough for tests). */
    public function mount(): array
    {
        return $this->mounter->mountAll();
    }

    /** Recursively collect every file below a directory. */
    public static function filesUnder(string $dir): array
    {
        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $result[] = $file->getPathname();
            }
        }

        return $result;
    }

    /** Simple sample plugin payload. */
    public static function samplePlugin(string $marker = 'VGT_SAMPLE_MARKER_9F3A'): array
    {
        $plugin = "<?php\n"
            . "/** VGT test plugin. */\n"
            . "declare(strict_types=1);\n"
            . "// {$marker}\n"
            . "if (!function_exists('vgt_test_sample_boot')) {\n"
            . "    function vgt_test_sample_boot(): string { return 'sample-ok'; }\n"
            . "}\n"
            . "add_action('init', 'vgt_test_sample_boot');\n";

        $class = "<?php\n"
            . "declare(strict_types=1);\n"
            . "namespace VGT\\Sample;\n"
            . "final class LazyKernel { public static function ping(): string { return 'lazy-ok'; } }\n";

        $css = "body{--vgt-sample:1}\n";

        return [
            'plugin.php' => $plugin,
            'src/LazyKernel.php' => $class,
            'assets/sample.css' => $css,
        ];
    }
}
