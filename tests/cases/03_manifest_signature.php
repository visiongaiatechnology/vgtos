<?php
/**
 * Manifest schema validation and Ed25519 signature verification.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Crypto\Manifest;
use VGT\Omega\System\Vault\Crypto\SignatureVerifier;
use VGT\Omega\System\Vault\VaultFormat;

/** @var TestRunner $runner */
$validFile = static function (string $content, string $mode = 'encrypted'): array {
    return [
        'mode' => $mode,
        'compression' => strlen($content) >= VaultFormat::RAW_COMPRESSION_THRESHOLD ? 'gz' : 'none',
        'size' => strlen($content),
        'sha256' => hash('sha256', $content),
    ];
};

$runner->test('Manifest builds, canonicalises and roundtrips', function () use ($runner, $validFile): void {
    $manifest = Manifest::build('plugin.php', 'Demo', [
        'plugin.php' => $validFile('<?php echo 1;'),
        'src/A.php' => $validFile('<?php class A {}'),
    ], ['Demo\\A' => 'src/A.php']);

    $runner->assertSame(VaultFormat::SPEC_VERSION, $manifest->format());
    $runner->assertSame('plugin.php', $manifest->entry());
    $runner->assertSame('Demo', $manifest->name());
    $runner->assertSame(2, count($manifest->files()));
    $runner->assertSame('src/A.php', $manifest->classmap()['Demo\\A']);

    $json = $manifest->toJson();
    $reparsed = Manifest::fromJson($json);
    $runner->assertSame($manifest->canonicalPayload(), $reparsed->canonicalPayload());
    $runner->assertSame($json, $reparsed->toJson());
});

$runner->test('Manifest canonical payload is key-order independent', function () use ($runner, $validFile): void {
    $manifestA = Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'name' => 'X',
        'files' => ['plugin.php' => $validFile('a')],
    ]);

    $manifestB = Manifest::fromArray([
        'files' => ['plugin.php' => $validFile('a')],
        'name' => 'X',
        'entry' => 'plugin.php',
        'format' => VaultFormat::SPEC_VERSION,
    ]);

    $runner->assertSame($manifestA->canonicalPayload(), $manifestB->canonicalPayload());
});

$runner->test('Manifest rejects traversal, unknown fields, missing entry and raw executables', function () use ($runner, $validFile): void {
    $base = [
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => $validFile('<?php')],
    ];

    $runner->assertThrows(
        fn () => Manifest::fromArray(['entries' => []] + $base),
        SecurityException::class
    );
    $runner->assertThrows(
        fn () => Manifest::fromArray(['entry' => '../evil.php'] + $base),
        SecurityException::class
    );

    $traversal = $base;
    $traversal['files']['../evil.php'] = $validFile('x');
    $runner->assertThrows(fn () => Manifest::fromArray($traversal), SecurityException::class);

    $missingEntry = $base;
    unset($missingEntry['files']['plugin.php']);
    $missingEntry['files']['other.php'] = $validFile('x');
    $runner->assertThrows(fn () => Manifest::fromArray($missingEntry), SecurityException::class);

    $rawPhp = $base;
    $rawPhp['files']['plugin.php'] = $validFile('<?php', 'raw');
    $runner->assertThrows(fn () => Manifest::fromArray($rawPhp), SecurityException::class);

    $rawAsset = $base;
    $rawAsset['files']['assets/app.css'] = $validFile('body{}', 'raw');
    $runner->assertSame('raw', Manifest::fromArray($rawAsset)->files()['assets/app.css']['mode']);
});

$runner->test('Manifest requires size/hash in v4.2 and rejects bad hashes', function () use ($runner): void {
    $runner->assertThrows(fn () => Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => ['mode' => 'encrypted', 'compression' => 'none']],
    ]), SecurityException::class);

    $runner->assertThrows(fn () => Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => ['mode' => 'encrypted', 'compression' => 'none', 'size' => 3, 'sha256' => 'zz']],
    ]), SecurityException::class);
});

$runner->test('Manifest rejects duplicate post-normalisation paths and bad classmaps', function () use ($runner, $validFile): void {
    $runner->assertThrows(fn () => Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => [
            'plugin.php' => $validFile('x'),
            './plugin.php' => $validFile('x'),
        ],
    ]), SecurityException::class);

    $runner->assertThrows(fn () => Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => $validFile('x')],
        'classmap' => ['not a class!!' => 'plugin.php'],
    ]), SecurityException::class);

    $runner->assertThrows(fn () => Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => $validFile('x')],
        'classmap' => ['Demo\\Missing' => 'missing.php'],
    ]), SecurityException::class);
});

$runner->test('Ed25519 signature verifies, tampering fails, unsigned policy enforced', function () use ($runner, $validFile): void {
    if (!function_exists('sodium_crypto_sign_keypair')) {
        throw new \RuntimeException('libsodium missing in test PHP.');
    }

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);

    $manifest = Manifest::build('plugin.php', 'Signed', ['plugin.php' => $validFile('<?php')]);
    $signature = sodium_crypto_sign_detached($manifest->canonicalPayload(), $secret);
    $signed = $manifest->withSignature([
        'alg' => 'ed25519',
        'public_key' => base64_encode($public),
        'sig' => base64_encode($signature),
        'kid' => 'test-key',
    ]);

    $verifier = new SignatureVerifier(false);
    $verifier->verify($signed);
    $runner->assertTrue($signed->signature() !== null);

    // Payload change invalidates the signature.
    $tampered = Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'name' => 'Tampered',
        'files' => $signed->toArray()['files'],
        'signature' => $signed->signature(),
    ]);
    $runner->assertThrows(fn () => $verifier->verify($tampered), SecurityException::class);

    // Required policy rejects unsigned artifacts.
    $required = new SignatureVerifier(true);
    $runner->assertThrows(fn () => $required->verify($manifest), SecurityException::class);
});

$runner->test('SignatureVerifier rejects malformed signature material', function () use ($runner, $validFile): void {
    $manifest = Manifest::build('plugin.php', 'X', ['plugin.php' => $validFile('x')]);

    $runner->assertThrows(fn () => $manifest->withSignature([
        'alg' => 'rsa',
        'public_key' => base64_encode(random_bytes(32)),
        'sig' => base64_encode(random_bytes(64)),
    ]), SecurityException::class);

    $runner->assertThrows(fn () => Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => $validFile('x')],
        'signature' => ['alg' => 'ed25519', 'public_key' => 'not-base64', 'sig' => 'nope'],
    ]), SecurityException::class);
});

$runner->test('Installed manifest must reference blobs', function () use ($runner, $validFile): void {
    $manifest = Manifest::build('plugin.php', 'X', ['plugin.php' => $validFile('x')]);
    $runner->assertThrows(fn () => $manifest->assertHasBlobs(), SecurityException::class);

    $withBlob = Manifest::fromArray([
        'format' => VaultFormat::SPEC_VERSION,
        'entry' => 'plugin.php',
        'files' => ['plugin.php' => $validFile('x') + ['blob' => str_repeat('a', 32)]],
    ]);
    $withBlob->assertHasBlobs();
    $runner->assertTrue(true);
});
