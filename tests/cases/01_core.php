<?php
/**
 * Core primitives: path jail, memory guard, container, exception policy, bridge.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

use VGT\Omega\Bridge\Adapters\StandaloneAdapter;
use VGT\Omega\Bridge\Core\Container;
use VGT\Omega\Bridge\Environments\StandaloneEnvironment;
use VGT\Omega\Core\Error\ExceptionReporter;
use VGT\Omega\Core\Exceptions\SecurityException;
use VGT\Omega\Core\Exceptions\StorageException;
use VGT\Omega\Core\Exceptions\ValidationException;
use VGT\Omega\System\Vault\Support\MemoryGuard;
use VGT\Omega\System\Vault\Support\PathGuard;

/** @var TestRunner $runner */
$runner->test('PathGuard accepts safe nested relative paths', function () use ($runner): void {
    $runner->assertSame('plugin.php', PathGuard::normalizeRelative('plugin.php'));
    $runner->assertSame('src/Deep/File.php', PathGuard::normalizeRelative('src\\Deep\\File.php'));
    $runner->assertSame('src/File.php', PathGuard::normalizeRelative('./src//File.php'));
    $runner->assertTrue(PathGuard::isSafeRelative('a/b/c.txt'));
});

$runner->test('PathGuard rejects traversal, absolute, drive, ADS, control and overlong paths', function () use ($runner): void {
    $attacks = [
        '../evil.php',
        'a/../../evil.php',
        '..\\evil.php',
        'a\\..\\..\\evil.php',
        '/etc/passwd',
        'C:\\Windows\\win.ini',
        'C:/Windows/win.ini',
        'file.php:stream',
        "file\0.php",
        "file\n.php",
        str_repeat('a', 260),
        str_repeat('a/', 30) . 'x.php',
    ];

    foreach ($attacks as $attack) {
        $runner->assertThrows(fn () => PathGuard::normalizeRelative($attack), SecurityException::class);
    }

    $runner->assertThrows(fn () => PathGuard::normalizeRelative('.'), SecurityException::class);
    $runner->assertThrows(fn () => PathGuard::normalizeRelative(''), SecurityException::class);
});

$runner->test('PathGuard joins inside an existing jail and rejects escapes', function () use ($runner): void {
    $jail = vgt_test_temp_dir('jail');
    $joined = PathGuard::joinInside($jail, 'sub/file.txt');
    $runner->assertTrue(str_starts_with(str_replace('\\', '/', $joined), str_replace('\\', '/', realpath($jail)) . '/'));
    $runner->assertThrows(fn () => PathGuard::joinInside($jail, '../outside.txt'), SecurityException::class);
    $runner->assertThrows(fn () => PathGuard::joinInside($jail . '/missing', 'x.txt'), StorageException::class);
});

$runner->test('Artifact and blob identifiers use strict CSPRNG shapes', function () use ($runner): void {
    $first = PathGuard::newArtifactId();
    $second = PathGuard::newArtifactId();

    $runner->assertMatches('/^vgt_[a-f0-9]{24}$/', $first);
    $runner->assertNotSame($first, $second);
    $runner->assertTrue(PathGuard::isValidArtifactId($first));
    $runner->assertTrue(PathGuard::isValidArtifactId('vgt_69b5bfb70f34f'));
    $runner->assertFalse(PathGuard::isValidArtifactId('../../plugins'));
    $runner->assertFalse(PathGuard::isValidArtifactId('vgt_ZZZ'));
    $runner->assertFalse(PathGuard::isValidArtifactId('vgt_69b5bfb70f34f', false));
    $runner->assertThrows(fn () => PathGuard::assertArtifactId('../../etc'), SecurityException::class);
    $runner->assertThrows(fn () => PathGuard::assertBlobName('../x.vgtblob'), SecurityException::class);
    $runner->assertMatches('/^[a-f0-9]{32}$/', PathGuard::newBlobId());
});

$runner->test('MemoryGuard parses ini budgets and enforces headroom', function () use ($runner): void {
    $original = ini_get('memory_limit');
    ini_set('memory_limit', '128M');
    $guard = new MemoryGuard();
    $runner->assertSame(134217728, $guard->limitBytes());
    $runner->assertSame(1048576, MemoryGuard::parseIniBytes('1M'));
    $runner->assertSame(1073741824, MemoryGuard::parseIniBytes('1G'));
    $runner->assertSame(PHP_INT_MAX, MemoryGuard::parseIniBytes('-1'));
    $runner->assertSame(2048, MemoryGuard::parseIniBytes('2048'));
    $runner->assertTrue($guard->availableBytes() > 0);
    $runner->assertThrows(fn () => $guard->assertCanAllocate(134217728), \VGT\Omega\Core\Exceptions\AvailabilityException::class);
    $runner->assertSame('1.0 MiB', MemoryGuard::formatBytes(1048576));
    ini_set('memory_limit', (string) $original);
});

$runner->test('Container rejects re-registration, unknown services and deserialization', function () use ($runner): void {
    $container = Container::getInstance();
    $object = new \stdClass();
    $container->set('test.service', $object);
    $runner->assertSame($object, $container->get('test.service'));
    $runner->assertTrue($container->has('test.service'));
    $runner->assertThrows(fn () => $container->set('test.service', new \stdClass()), \RuntimeException::class);
    $runner->assertThrows(fn () => $container->get('missing.service'), \OutOfBoundsException::class);
    $runner->assertThrows(fn () => $container->__wakeup(), \BadMethodCallException::class);
    $runner->assertThrows(fn () => $container->__unserialize([]), \BadMethodCallException::class);
});

$runner->test('ExceptionReporter never leaks security details to the client', function () use ($runner): void {
    $sensitiveMessage = 'AAD mismatch at /srv/private/vault with key material';
    $reported = ExceptionReporter::forClient(new SecurityException($sensitiveMessage), 'test');
    $runner->assertNotContains('private/vault', $reported['message']);
    $runner->assertNotContains('AAD', $reported['message']);
    $runner->assertMatches('/^VGT-ERR-[A-F0-9]{8}$/', $reported['reference']);

    $validation = ExceptionReporter::forClient(new ValidationException('Please select a package to deploy.'), 'test');
    $runner->assertSame('Please select a package to deploy.', $validation['message']);

    $storage = ExceptionReporter::forClient(new StorageException('disk /srv/private failed'), 'test');
    $runner->assertNotContains('/srv/private', $storage['message']);
});

$runner->test('StandaloneAdapter implements hooks, filters, nonces and persistent state', function () use ($runner): void {
    $base = vgt_test_temp_dir('bridge');
    $environment = new StandaloneEnvironment($base);
    $stateFile = $base . '/state.json';
    $adapter = new StandaloneAdapter($environment, $stateFile);

    $order = [];
    $adapter->addAction('vgt.test', function () use (&$order): void {
        $order[] = 'late';
    }, 20);
    $adapter->addAction('vgt.test', function () use (&$order): void {
        $order[] = 'early';
    }, 5);
    $adapter->doAction('vgt.test');
    $runner->assertSame(['early', 'late'], $order);

    $adapter->addFilter('vgt.value', static fn (string $value): string => $value . '-b', 20);
    $adapter->addFilter('vgt.value', static fn (string $value): string => $value . '-a', 5);
    $runner->assertSame('x-a-b', $adapter->applyFilters('vgt.value', 'x'));

    $adapter->setState('option', 'value');
    $runner->assertTrue(is_file($stateFile));
    $reloaded = new StandaloneAdapter($environment, $stateFile);
    $runner->assertSame('value', $reloaded->getState('option'));

    $nonce = $adapter->createNonce('vgt_action');
    $runner->assertNotFalse($adapter->verifyNonce($nonce, 'vgt_action'));
    $runner->assertFalse($adapter->verifyNonce($nonce, 'other_action'));
    $runner->assertFalse($adapter->verifyNonce(str_repeat('a', 64), 'vgt_action'));

    $adapter->enqueueJs('handle', 'vgt://vgt_abc/asset.js');
    $runner->assertSame(1, count($adapter->collectedAssets()));
});
