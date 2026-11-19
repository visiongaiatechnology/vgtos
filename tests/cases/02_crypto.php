<?php
/**
 * AES-256-GCM container: AAD binding, strict header, smart compression, legacy import.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\System\Vault\Crypto\CryptoEngine;
use VGT\Omega\System\Vault\VaultFormat;

/** @var TestRunner $runner */
$key = random_bytes(32);
$aad = VaultFormat::aad('vgt_0123456789abcdef01234567', 'plugin.php');

/** Build a v4.0/4.1 style container without AAD and without version/flags bytes. */
$makeLegacy = static function (string $plaintext, string $key, ?string $firstIvByte = null): string {
    $gzip = gzencode($plaintext, 9, FORCE_GZIP);
    $iv = ($firstIvByte ?? '') . random_bytes(12 - strlen($firstIvByte ?? ''));
    $tag = '';
    $ciphertext = openssl_encrypt($gzip, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) {
        throw new \RuntimeException('Legacy fixture encryption failed.');
    }

    return VaultFormat::MAGIC . $iv . $tag . $ciphertext;
};

$runner->test('Crypto roundtrip marks small payloads VGT_RAW and decrypts byte-identically', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();
    $plaintext = random_bytes(512);
    $container = $engine->encrypt($plaintext, $key, $aad);

    $runner->assertTrue(str_starts_with($container, VaultFormat::MAGIC));
    $runner->assertSame(VaultFormat::VERSION_BYTE, ord($container[VaultFormat::MAGIC_LENGTH]));
    $runner->assertSame(VaultFormat::FLAG_NONE, ord($container[VaultFormat::MAGIC_LENGTH + 1]));
    $runner->assertSame($plaintext, $engine->decrypt($container, $key, $aad));
});

$runner->test('Crypto compresses large payloads with VGT_GZ flag and bounded inflate', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();
    $plaintext = str_repeat('VGT-COMPRESSIBLE-PAYLOAD-', 4000);
    $container = $engine->encrypt($plaintext, $key, $aad);

    $runner->assertSame(VaultFormat::FLAG_GZIP, ord($container[VaultFormat::MAGIC_LENGTH + 1]));
    $runner->assertTrue(strlen($container) < strlen($plaintext));
    $runner->assertSame($plaintext, $engine->decrypt($container, $key, $aad));
});

$runner->test('Crypto rejects wrong key, wrong AAD, tampering and truncation', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();
    $container = $engine->encrypt('payload', $key, $aad);

    $runner->assertThrows(fn () => $engine->decrypt($container, random_bytes(32), $aad), SecurityException::class);
    $runner->assertThrows(fn () => $engine->decrypt($container, $key, $aad . ':other-path'), SecurityException::class);
    $runner->assertThrows(fn () => $engine->decrypt($container, $key, ''), SecurityException::class);

    $tampered = $container;
    $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);
    $runner->assertThrows(fn () => $engine->decrypt($tampered, $key, $aad), SecurityException::class);

    $runner->assertThrows(fn () => $engine->decrypt(substr($container, 0, 20), $key, $aad), SecurityException::class);
});

$runner->test('Crypto requires the magic header exactly at byte offset zero', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();
    $container = $engine->encrypt('payload', $key, $aad);

    $prefixed = 'PREFIX-BYTES' . $container;
    $runner->assertThrows(fn () => $engine->decrypt($prefixed, $key, $aad), SecurityException::class);

    // Trailing bytes are authenticated as part of the ciphertext and rejected.
    $runner->assertThrows(fn () => $engine->decrypt($container . 'VGT_GUARD', $key, $aad), SecurityException::class);
});

$runner->test('Crypto rejects unknown version bytes and invalid flags', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();
    $container = $engine->encrypt('payload', $key, $aad);

    $unknownVersion = $container;
    $unknownVersion[VaultFormat::MAGIC_LENGTH] = chr(0x99);
    $runner->assertThrows(fn () => $engine->decrypt($unknownVersion, $key, $aad), SecurityException::class);

    $unknownFlags = $container;
    $unknownFlags[VaultFormat::MAGIC_LENGTH + 1] = chr(0x80);
    $runner->assertThrows(fn () => $engine->decrypt($unknownFlags, $key, $aad), SecurityException::class);
});

$runner->test('Legacy v4.0 containers are accepted for import only', function () use ($runner, $key, $aad, $makeLegacy): void {
    $engine = new CryptoEngine();
    $legacy = $makeLegacy('legacy-payload', $key);

    $runner->assertSame('legacy-payload', $engine->decrypt($legacy, $key, $aad, true));
    $runner->assertThrows(fn () => $engine->decrypt($legacy, $key, $aad, false), SecurityException::class);

    // Legacy IV that coincidentally starts with the new version byte must still import.
    $coincidental = $makeLegacy('coincidence', $key, chr(VaultFormat::VERSION_BYTE));
    $runner->assertSame('coincidence', $engine->decrypt($coincidental, $key, $aad, true));
});

$runner->test('Crypto refuses non-32-byte keys and empty AAD', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();

    $runner->assertThrows(fn () => $engine->encrypt('x', substr($key, 0, 16), $aad), SecurityException::class);
    $runner->assertThrows(fn () => $engine->encrypt('x', $key, ''), SecurityException::class);
    $runner->assertThrows(fn () => $engine->decrypt('x', substr($key, 0, 31), $aad), SecurityException::class);
});

$runner->test('Crypto container layout matches the documented v4.2 offsets', function () use ($runner, $key, $aad): void {
    $engine = new CryptoEngine();
    $plaintext = 'layout-check';
    $container = $engine->encrypt($plaintext, $key, $aad);

    $runner->assertSame(VaultFormat::MAGIC, substr($container, 0, VaultFormat::MAGIC_LENGTH));
    $runner->assertSame(VaultFormat::HEADER_LENGTH + strlen($plaintext), strlen($container));
    $runner->assertSame(VaultFormat::IV_LENGTH, strlen(substr($container, VaultFormat::MAGIC_LENGTH + 2, VaultFormat::IV_LENGTH)));
    $runner->assertSame(VaultFormat::TAG_LENGTH, strlen(substr($container, VaultFormat::MAGIC_LENGTH + 2 + VaultFormat::IV_LENGTH, VaultFormat::TAG_LENGTH)));
    $runner->assertSame($plaintext, $engine->decrypt($container, $key, $aad));
});
