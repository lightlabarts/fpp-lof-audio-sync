<?php

declare(strict_types=1);

namespace LofAudioSupply\Process;

/**
 * Outcome of one child process. Output is already redacted and bounded.
 */
final class ProcessResult
{
    /** @param list<string> $argv */
    public function __construct(
        public readonly array $argv,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $durationSeconds,
        public readonly bool $timedOut
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    /**
     * Render the argv for a log line.
     *
     * Each element is shown individually so a reader can see that no element
     * was ever concatenated into a command string.
     */
    public function argvForLog(): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => '[' . $arg . ']',
            $this->argv
        ));
    }
}
