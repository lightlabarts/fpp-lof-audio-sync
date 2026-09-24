#!/usr/bin/env php
<?php
/**
 * Test runner.
 *
 *   php tests/run.php              run everything
 *   php tests/run.php Injection    run cases whose class name matches a filter
 *
 * Exits non-zero on the first failing suite so CI and a pre-commit check can
 * both rely on it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "These tests require PHP 8.1 or newer (FPP 8/9/10 all ship 8.1+).\n");
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/Estate.php';
require_once __DIR__ . '/Doubles.php';
require_once __DIR__ . '/ViewerDoubles.php';

use LofTest\AssertionFailed;
use LofTest\SkipTest;
use LofTest\TestCase;

// Start a session before a single byte is emitted. ConfigPageTest includes
// config.php, which needs one to anchor a CSRF token, and PHP will not start
// a session once output has begun.
// Cookies are meaningless in CLI, and disabling them lets an individual test
// close and reopen the session without PHP trying to emit a header.
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_cookies', '0');
    @ini_set('session.use_only_cookies', '0');
    @ini_set('session.cache_limiter', '');
    @session_start();
}

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/cases/*Test.php');
sort($files, SORT_STRING);

$before = get_declared_classes();
foreach ($files as $file) {
    require_once $file;
}
$cases = array_values(array_filter(
    array_diff(get_declared_classes(), $before),
    static fn (string $class): bool => is_subclass_of($class, TestCase::class)
));
sort($cases, SORT_STRING);

$passed = 0;
$failed = 0;
$skipped = 0;
/** @var list<array{case:string,test:string,message:string}> $failures */
$failures = [];
/** @var list<string> $skips */
$skips = [];
$startedAll = microtime(true);

foreach ($cases as $class) {
    $short = (new ReflectionClass($class))->getShortName();
    if ($filter !== '' && stripos($short, $filter) === false) {
        continue;
    }
    $methods = array_values(array_filter(
        get_class_methods($class),
        static fn (string $method): bool => strncmp($method, 'test', 4) === 0
    ));
    sort($methods, SORT_STRING);

    echo "\n\033[1m" . $short . "\033[0m\n";
    foreach ($methods as $method) {
        /** @var TestCase $instance */
        $instance = new $class();
        $started = microtime(true);
        try {
            $instance->setUp();
            $instance->$method();
            $instance->tearDown();
            $passed++;
            printf("  \033[32mPASS\033[0m %-58s %5.0fms\n", $method, (microtime(true) - $started) * 1000);
        } catch (SkipTest $e) {
            $instance->tearDown();
            $skipped++;
            $skips[] = $short . '::' . $method . ' - ' . $e->getMessage();
            printf("  \033[33mSKIP\033[0m %-58s %s\n", $method, $e->getMessage());
        } catch (AssertionFailed $e) {
            try {
                $instance->tearDown();
            } catch (Throwable $ignored) {
            }
            $failed++;
            $failures[] = ['case' => $short, 'test' => $method, 'message' => $e->getMessage()];
            printf("  \033[31mFAIL\033[0m %-58s\n        %s\n", $method, $e->getMessage());
        } catch (Throwable $e) {
            try {
                $instance->tearDown();
            } catch (Throwable $ignored) {
            }
            $failed++;
            $message = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            $failures[] = ['case' => $short, 'test' => $method, 'message' => $message];
            printf("  \033[31mERROR\033[0m %-57s\n        %s\n", $method, $message);
        }
    }
}

$duration = round(microtime(true) - $startedAll, 2);
echo "\n" . str_repeat('-', 78) . "\n";
printf("%d passed, %d failed, %d skipped in %ss\n", $passed, $failed, $skipped, $duration);

if ($skips !== []) {
    echo "\nSkipped:\n";
    foreach ($skips as $skip) {
        echo '  - ' . $skip . "\n";
    }
}
if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure['case'] . '::' . $failure['test'] . "\n      " . $failure['message'] . "\n";
    }
}

exit($failed === 0 ? 0 : 1);
