<?php
/**
 * Zero-dependency test harness.
 *
 * FPP images ship PHP but not composer, so the suite must run on a bare
 * interpreter. Everything here is deterministic: no network, no clock
 * dependence beyond explicit stamps, no reliance on a live FPP, and every
 * fixture lives in a temporary directory that is removed afterwards.
 */

declare(strict_types=1);

namespace LofTest;

final class AssertionFailed extends \RuntimeException
{
}

final class SkipTest extends \RuntimeException
{
}

abstract class TestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            self::removeTree($root);
        }
        $this->tempRoots = [];
    }

    public function makeTempRoot(string $label = 'lof'): string
    {
        $base = sys_get_temp_dir() . '/' . $label . '-' . bin2hex(random_bytes(8));
        if (!mkdir($base, 0755, true) && !is_dir($base)) {
            throw new \RuntimeException('Could not create temp root.');
        }
        // realpath() collapses the macOS /var -> /private/var symlink, which
        // would otherwise look like a containment violation to SafePath.
        $real = realpath($base);
        $root = $real === false ? $base : $real;
        $this->tempRoots[] = $root;

        return $root;
    }

    public static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            // chmod() follows a symlink: never touch a link's target (a test
            // may plant a link to /etc/passwd).
            if (!is_link($path)) {
                @chmod($path, 0644);
            }
            @unlink($path);

            return;
        }
        @chmod($path, 0755);
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    public function writeFile(string $path, string $contents): string
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create fixture directory.');
        }
        file_put_contents($path, $contents);

        return $path;
    }

    public function skip(string $why): void
    {
        throw new SkipTest($why);
    }

    public function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    // ---- assertions --------------------------------------------------------

    public function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected true.');
        }
    }

    public function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertTrue(!$condition, $message !== '' ? $message : 'Expected false.');
    }

    /** @param mixed $expected @param mixed $actual */
    public function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '')
                . 'Expected ' . self::describe($expected) . ' but got ' . self::describe($actual) . '.'
            );
        }
    }

    /** @param mixed $notExpected @param mixed $actual */
    public function assertNotSame($notExpected, $actual, string $message = ''): void
    {
        if ($notExpected === $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '') . 'Expected a value different from ' . self::describe($actual) . '.'
            );
        }
    }

    /** @param array<array-key,mixed> $haystack */
    public function assertCount(int $expected, array $haystack, string $message = ''): void
    {
        $this->assertSame($expected, count($haystack), $message !== '' ? $message : 'Unexpected element count.');
    }

    public function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '') . 'Expected to find "' . $needle . '" in: ' . substr($haystack, 0, 400)
            );
        }
    }

    public function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) !== false) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '') . 'Did not expect to find "' . $needle . '" in: ' . substr($haystack, 0, 400)
            );
        }
    }

    /** @param list<mixed> $haystack @param mixed $needle */
    public function assertContainsValue($needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '') . 'Expected list to contain ' . self::describe($needle) . '.'
            );
        }
    }

    /** @param list<mixed> $haystack @param mixed $needle */
    public function assertNotContainsValue($needle, array $haystack, string $message = ''): void
    {
        if (in_array($needle, $haystack, true)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '') . 'Did not expect list to contain ' . self::describe($needle) . '.'
            );
        }
    }

    /**
     * Assert the callable throws a LofAudioException carrying $expectedCode.
     * Pass null to accept any code.
     */
    public function assertRefused(?string $expectedCode, callable $callable, string $message = ''): \Throwable
    {
        try {
            $callable();
        } catch (\LofAudioSupply\LofAudioException $e) {
            if ($expectedCode !== null && $e->code() !== $expectedCode) {
                throw new AssertionFailed(
                    ($message !== '' ? $message . ' -- ' : '')
                    . 'Expected refusal code "' . $expectedCode . '" but got "' . $e->code() . '" (' . $e->getMessage() . ').'
                );
            }

            return $e;
        } catch (\Throwable $e) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' -- ' : '')
                . 'Expected a LofAudioException but got ' . get_class($e) . ': ' . $e->getMessage()
            );
        }

        throw new AssertionFailed(($message !== '' ? $message . ' -- ' : '') . 'Expected a refusal but the call succeeded.');
    }

    /** @param mixed $value */
    private static function describe($value): string
    {
        if (is_string($value)) {
            return '"' . (strlen($value) > 200 ? substr($value, 0, 200) . '...' : $value) . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return get_class($value);
        }

        return (string) $value;
    }
}
