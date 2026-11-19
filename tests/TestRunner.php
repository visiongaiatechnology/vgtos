<?php
/**
 * Minimal dependency-free test runner.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega\Tests
 */

declare(strict_types=1);

final class TestRunner
{
    private int $tests = 0;

    private int $passed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    /** @var list<array{name: string, message: string}> */
    private array $failures = [];

    private ?string $filter;

    public function __construct(?string $filter = null)
    {
        $this->filter = $filter !== '' ? $filter : null;
    }

    public function test(string $name, callable $case): void
    {
        if ($this->filter !== null && stripos($name, $this->filter) === false) {
            return;
        }

        $this->tests++;

        try {
            $case();
            $this->passed++;
            fwrite(STDOUT, 'PASS  ' . $name . PHP_EOL);
        } catch (\Throwable $error) {
            $this->failed++;
            $message = $error::class . ': ' . $error->getMessage();
            $this->failures[] = ['name' => $name, 'message' => $message];
            fwrite(STDOUT, 'FAIL  ' . $name . PHP_EOL . '      ' . $message . PHP_EOL);
        }
    }

    public function skip(string $name, string $reason): void
    {
        $this->skipped++;
        fwrite(STDOUT, 'SKIP  ' . $name . ' (' . $reason . ')' . PHP_EOL);
    }

    public function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public function assertFalse(bool $condition, string $message = 'Expected false.'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    public function assertNotFalse(mixed $value, string $message = 'Expected a non-false value.'): void
    {
        if ($value === false) {
            throw new \RuntimeException($message);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message !== '' ? $message : 'Expected ' . $this->export($expected) . ' but got ' . $this->export($actual) . '.'
            );
        }
    }

    public function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            throw new \RuntimeException($message !== '' ? $message : 'Did not expect ' . $this->export($actual) . '.');
        }
    }

    public function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException($message !== '' ? $message : 'Expected to find "' . $needle . '".');
        }
    }

    public function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new \RuntimeException($message !== '' ? $message : 'Did not expect to find "' . $needle . '".');
        }
    }

    /** Strict array membership assertion. */
    public function assertArrayContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException(
                $message !== '' ? $message : 'Expected ' . $this->export($needle) . ' in array of ' . count($haystack) . ' values.'
            );
        }
    }

    public function assertArrayNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (in_array($needle, $haystack, true)) {
            throw new \RuntimeException(
                $message !== '' ? $message : 'Did not expect ' . $this->export($needle) . ' in array.'
            );
        }
    }

    public function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        if (preg_match($pattern, $subject) !== 1) {
            throw new \RuntimeException($message !== '' ? $message : 'Value does not match ' . $pattern . '.');
        }
    }

    public function assertFileDoesNotContain(string $file, string $needle, string $message = ''): void
    {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            throw new \RuntimeException('Could not read ' . $file);
        }

        $this->assertNotContains($needle, $content, $message !== '' ? $message : $needle . ' found in ' . $file);
    }

    public function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        if (!$value instanceof $class) {
            throw new \RuntimeException($message !== '' ? $message : 'Expected instance of ' . $class . '.');
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    public function assertThrows(callable $operation, string $class, ?string $messagePart = null): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            if (!$error instanceof $class) {
                throw new \RuntimeException('Expected ' . $class . ' but got ' . $error::class . ': ' . $error->getMessage());
            }

            if ($messagePart !== null && !str_contains($error->getMessage(), $messagePart)) {
                throw new \RuntimeException('Exception message does not contain "' . $messagePart . '": ' . $error->getMessage());
            }

            return $error;
        }

        throw new \RuntimeException('Expected ' . $class . ' but nothing was thrown.');
    }

    public function summary(): int
    {
        fwrite(STDOUT, PHP_EOL . str_repeat('-', 68) . PHP_EOL);
        fwrite(STDOUT, sprintf(
            'TEST_LEDGER: total=%d passed=%d failed=%d skipped=%d exit=%d%s',
            $this->tests,
            $this->passed,
            $this->failed,
            $this->skipped,
            $this->failed === 0 ? 0 : 1,
            PHP_EOL
        ));

        foreach ($this->failures as $failure) {
            fwrite(STDOUT, '  FAILED: ' . $failure['name'] . ' — ' . $failure['message'] . PHP_EOL);
        }

        return $this->failed === 0 ? 0 : 1;
    }

    public function counts(): array
    {
        return ['total' => $this->tests, 'passed' => $this->passed, 'failed' => $this->failed, 'skipped' => $this->skipped];
    }

    private function export(mixed $value): string
    {
        if (is_object($value)) {
            return $value::class;
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        return var_export($value, true);
    }
}
