<?php
/**
 * End-to-end installer: staging quarantine, no plaintext at rest, rotation, delete.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\VaultFormat;

/** @var TestRunner $runner */
$plaintextMarker = 'VGT_SAMPLE_MARKER_9F3A';

$runner->test('Installer imports a cloud package into an encrypted hardware-bound artifact', function () use ($runner, $plaintextMarker): void {
    $harness = new VaultHarness();
    $files = VaultHarness::samplePlugin($plaintextMarker);
    $result = $harness->installFiles($files, 'plugin.php', 'Demo Artifact');

    $runner->assertSame('installed', $result['status']);
    $runner->assertMatches('/^vgt_[a-f0-9]{24}$/', $result['id']);
    $runner->assertSame(3, $result['files']);
    $runner->assertTrue($harness->store->exists($result['id']));
    $runner->assertSame([$result['id']], $harness->store->listArtifactIds());

    $meta = $harness->store->getMeta($result['id']);
    $runner->assertSame('Demo Artifact', $meta['name']);
    $runner->assertSame('plugin.php', $meta['entry']);
    $runner->assertSame(3, $meta['files']);
    $runner->assertTrue($meta['updated'] > 0);

    // No plaintext payload may exist anywhere below the vault.
    $marker = $plaintextMarker;
    foreach (VaultHarness::filesUnder($harness->paths->vaultDir) as $file) {
        $runner->assertFileDoesNotContain($file, $marker);
        $runner->assertFileDoesNotContain($file, 'function vgt_test_sample_boot');
    }

    $manifestContainer = $harness->store->readManifestContainer($result['id']);
    $runner->assertTrue(is_string($manifestContainer));
    $runner->assertTrue(str_starts_with((string) $manifestContainer, VaultFormat::MAGIC));
});

$runner->test('Installer rejects an invalid cloud key without leaving partial state', function () use ($runner): void {
    $harness = new VaultHarness();
    $zip = $harness->packArtifact(VaultHarness::samplePlugin());

    $runner->assertThrows(fn () => $harness->installer->install($zip, 'zz'), ValidationException::class);
    $runner->assertThrows(fn () => $harness->installer->install($zip, bin2hex(random_bytes(32))), SecurityException::class);
    $runner->assertSame([], $harness->store->listArtifactIds());
    $staging = glob($harness->paths->stagingDir() . '/*') ?: [];
    $runner->assertSame([], $staging);
});

$runner->test('Installer rejects zip-slip packages containing undeclared payloads', function () use ($runner): void {
    $harness = new VaultHarness();
    $zip = $harness->packArtifact(VaultHarness::samplePlugin());

    $handle = new \ZipArchive();
    $runner->assertSame(true, $handle->open($zip));
    $handle->addFromString('extra-backdoor.php', '<?php system($_GET["c"]);');
    $handle->close();

    $runner->assertThrows(
        fn () => $harness->installer->install($zip, VaultHarness::CLOUD_KEY_HEX),
        SecurityException::class
    );
    $runner->assertSame([], $harness->store->listArtifactIds());
});

$runner->test('Installer rotates an artifact atomically and serves the new payload', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin(), 'plugin.php', 'Rotate Me');

    $updated = VaultHarness::samplePlugin('VGT_ROTATED_MARKER_B42');
    $updated['plugin.php'] = str_replace("return 'sample-ok';", "return 'rotated-ok';", $updated['plugin.php']);
    $rotated = $harness->installer->install(
        $harness->packArtifact($updated, 'plugin.php', 'Rotate Me'),
        VaultHarness::CLOUD_KEY_HEX,
        'Rotate Me',
        $installed['id']
    );

    $runner->assertSame('rotated', $rotated['status']);
    $runner->assertSame($installed['id'], $rotated['id']);
    $runner->assertSame([$installed['id']], $harness->store->listArtifactIds());

    $stats = $harness->mount();
    $runner->assertArrayContains($installed['id'], $stats['mounted']);
    $served = file_get_contents('vgt://' . $installed['id'] . '/plugin.php');
    $runner->assertTrue(is_string($served));
    $runner->assertContains('rotated-ok', (string) $served);
    $runner->assertNotContains('sample-ok', (string) $served);
});

$runner->test('Installer rejects rotation into an unknown artifact id', function () use ($runner): void {
    $harness = new VaultHarness();
    $zip = $harness->packArtifact(VaultHarness::samplePlugin());

    $runner->assertThrows(
        fn () => $harness->installer->install($zip, VaultHarness::CLOUD_KEY_HEX, 'X', 'vgt_aaaaaaaaaaaaaaaaaaaaaaaa'),
        \VGT\Omega\Core\Exceptions\AppException::class
    );
    $runner->assertSame([], $harness->store->listArtifactIds());
});

$runner->test('Installer enforces required Ed25519 signatures', function () use ($runner): void {
    $harness = new VaultHarness(true);
    $unsignedZip = $harness->packArtifact(VaultHarness::samplePlugin());

    $runner->assertThrows(
        fn () => $harness->installer->install($unsignedZip, VaultHarness::CLOUD_KEY_HEX),
        SecurityException::class
    );
    $runner->assertSame([], $harness->store->listArtifactIds());

    $keypair = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
    $signed = $harness->installFiles(VaultHarness::samplePlugin(), 'plugin.php', 'Signed Artifact', $secret);
    $runner->assertTrue($signed['signed']);
    $runner->assertTrue($harness->store->getMeta($signed['id'])['signed']);
});

$runner->test('Installer mirrors raw public assets and resolves their public URL', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin());

    $mirror = $harness->paths->publicDir . '/' . $installed['id'] . '/assets/sample.css';
    $runner->assertTrue(is_file($mirror));
    $runner->assertSame("body{--vgt-sample:1}\n", file_get_contents($mirror));

    $url = $harness->store->publicAssetUrl($installed['id'], 'assets/sample.css');
    $runner->assertMatches('#^http://example\.test/wp-content/uploads/vgt-public/vgt_[a-f0-9]{24}/assets/sample\.css$#', $url);
});

$runner->test('Installer deletes artifacts, metadata and public mirror atomically', function () use ($runner): void {
    $harness = new VaultHarness();
    $installed = $harness->installFiles(VaultHarness::samplePlugin());
    $mirror = $harness->paths->publicDir . '/' . $installed['id'];
    $runner->assertTrue(is_dir($mirror));

    $harness->installer->delete($installed['id']);
    $runner->assertSame([], $harness->store->listArtifactIds());
    $runner->assertFalse(is_dir($harness->paths->artifactDir($installed['id'])));
    $runner->assertFalse(is_dir($mirror));
    $runner->assertFalse($harness->store->exists($installed['id']));
});

$runner->test('Installer refuses to mark executable payloads as cleartext raw', function () use ($runner): void {
    $harness = new VaultHarness();
    $zip = $harness->packArtifact(VaultHarness::samplePlugin());
    $cloudKey = (string) hex2bin(VaultHarness::CLOUD_KEY_HEX);
    $crypto = new \VGT\Omega\System\Vault\Crypto\CryptoEngine();

    $handle = new \ZipArchive();
    $runner->assertSame(true, $handle->open($zip));
    $container = $handle->getFromName(\VGT\Omega\System\Vault\VaultFormat::MANIFEST_FILENAME);
    $runner->assertTrue(is_string($container));

    $json = $crypto->decrypt(
        (string) $container,
        $cloudKey,
        \VGT\Omega\System\Vault\VaultFormat::cloudManifestAad(),
        true
    );
    $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    $data['files']['plugin.php']['mode'] = 'raw';
    $forged = $crypto->encrypt(
        json_encode($data, JSON_THROW_ON_ERROR),
        $cloudKey,
        \VGT\Omega\System\Vault\VaultFormat::cloudManifestAad(),
        true
    );
    $handle->addFromString(\VGT\Omega\System\Vault\VaultFormat::MANIFEST_FILENAME, $forged);
    $handle->close();

    $runner->assertThrows(
        fn () => $harness->installer->install($zip, VaultHarness::CLOUD_KEY_HEX),
        SecurityException::class
    );
});
