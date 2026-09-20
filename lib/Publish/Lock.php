<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\LockException;
use LofAudioSupply\Support\Fs;

/**
 * Exclusive, non-blocking publish lock.
 *
 * flock() is released by the kernel when the process dies, so a crashed or
 * killed publish never leaves a stale lock that needs manual clearing. A second
 * concurrent run fails immediately with its own error code rather than
 * interleaving with the first and corrupting a generation.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function acquire(): void
    {
        if ($this->handle !== null) {
            return;
        }
        Fs::ensureDir(\dirname($this->path));
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new LockException('lock.open_failed', 'Publish lock file could not be opened.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new LockException('lock.busy', 'Another publish run holds the lock.');
        }
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid() . "\n");
        fflush($handle);
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function held(): bool
    {
        return $this->handle !== null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
