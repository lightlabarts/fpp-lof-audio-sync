<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Publish\Lock;
use LofTest\Estate;
use LofTest\TestCase;

final class ConcurrencyTest extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
    }

    public function testASecondHolderIsRefusedImmediately(): void
    {
        $path = $this->estate->publicationRoot . '/locks/publish.lock';
        $first = new Lock($path);
        $first->acquire();
        $this->assertTrue($first->held());

        $second = new Lock($path);
        $this->assertRefused('lock.busy', static fn () => $second->acquire());
        $this->assertFalse($second->held());

        $first->release();
        // Once released the lock is immediately reusable.
        $second->acquire();
        $this->assertTrue($second->held());
        $second->release();
    }

    public function testAcquireIsIdempotentForTheHolder(): void
    {
        $lock = new Lock($this->estate->publicationRoot . '/locks/publish.lock');
        $lock->acquire();
        $lock->acquire();
        $this->assertTrue($lock->held());
        $lock->release();
        $this->assertFalse($lock->held());
    }

    public function testPublishRefusesWhileAnotherRunHoldsTheLock(): void
    {
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings();
        $first = $this->estate->publisher($settings)->publish();

        $generations = $this->estate->generations($settings);
        $holder = new Lock($generations->lockPath());
        $holder->acquire();

        $this->estate->seedMusic(['a.mp3' => 'two']);
        $publisher = $this->estate->publisher($settings);
        $this->assertRefused('lock.busy', static fn () => $publisher->publish());

        // The blocked run changed nothing.
        $this->assertSame($first->generation, $generations->currentGeneration());
        $this->assertCount(1, $generations->listGenerations());
        $this->assertCount(0, $generations->listStaging());

        $holder->release();
        $second = $this->estate->publisher($settings)->publish();
        $this->assertSame('published', $second->outcome);
    }

    public function testTwoRealProcessesCannotPublishAtOnce(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->skip('pcntl is not available on this PHP build');
        }
        $this->estate->seedMusic(['a.mp3' => str_repeat('x', 200000)]);
        $settings = $this->estate->settings();
        $generations = $this->estate->generations($settings);
        $generations->initialise();

        // A real second process, not a second object in the same process:
        // flock semantics are per open file description, so this is the case
        // that actually matters on a live box.
        $resultFile = $this->estate->root . '/child-result';
        $pid = pcntl_fork();
        if ($pid === 0) {
            $holder = new Lock($generations->lockPath());
            try {
                $holder->acquire();
                file_put_contents($resultFile, 'acquired');
                usleep(400000);
                $holder->release();
            } catch (\Throwable $e) {
                file_put_contents($resultFile, 'refused:' . $e->getMessage());
            }
            exit(0);
        }

        // Wait for the child to take the lock.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !is_file($resultFile)) {
            usleep(10000);
        }
        $this->assertSame('acquired', (string) @file_get_contents($resultFile), 'The child should hold the lock.');

        $publisher = $this->estate->publisher($settings);
        $this->assertRefused('lock.busy', static fn () => $publisher->publish());

        pcntl_waitpid($pid, $status);

        // After the child exits, the kernel has dropped its lock.
        $result = $this->estate->publisher($settings)->publish();
        $this->assertSame('published', $result->outcome);
    }

    public function testAKilledHolderDoesNotLeaveAStaleLock(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->skip('pcntl/posix are not available on this PHP build');
        }
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings();
        $generations = $this->estate->generations($settings);
        $generations->initialise();

        $marker = $this->estate->root . '/held';
        $pid = pcntl_fork();
        if ($pid === 0) {
            $holder = new Lock($generations->lockPath());
            $holder->acquire();
            file_put_contents($marker, 'held');
            sleep(30);
            exit(0);
        }

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && !is_file($marker)) {
            usleep(10000);
        }
        $this->assertTrue(is_file($marker));

        // SIGKILL: no cleanup code runs in the child at all.
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        // The kernel released the flock when the process died, so recovery
        // needs no manual lock clearing.
        $result = $this->estate->publisher($settings)->publish();
        $this->assertSame('published', $result->outcome);
    }

    public function testPublishingIsIdempotentUnderRepetition(): void
    {
        $this->estate->seedMusic(['a.mp3' => 'one', 'b.mp3' => 'two']);
        $settings = $this->estate->settings();

        $outcomes = [];
        for ($i = 0; $i < 5; $i++) {
            $outcomes[] = $this->estate->publisher($settings)->publish()->outcome;
        }

        $this->assertSame(['published', 'unchanged', 'unchanged', 'unchanged', 'unchanged'], $outcomes);
        $this->assertCount(1, $this->estate->generations($settings)->listGenerations());
    }
}
