<?php

declare(strict_types=1);

namespace LofAudioSupply\Process;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\Redactor;
use LofAudioSupply\TransportException;

/**
 * The only way this component starts a child process.
 *
 * proc_open() is always called with an argv *array*, never a string. PHP hands
 * an array straight to execvp, so there is no shell to quote for and no
 * metacharacter that can change the meaning of an argument. A caller cannot
 * supply a command fragment: it supplies an executable that must already be on
 * the allowlist, plus arguments that are passed through untouched as separate
 * argv entries.
 */
final class ProcessRunner
{
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /** @var list<string> absolute paths of executables this runner may start */
    private array $allowedBinaries;

    /** @var array<string,string> */
    private array $environment;

    private int $maxCaptureBytes;

    /**
     * @param list<string> $allowedBinaries
     * @param array<string,string> $environment
     */
    public function __construct(array $allowedBinaries, array $environment = [], int $maxCaptureBytes = 262144)
    {
        $normalized = [];
        foreach ($allowedBinaries as $binary) {
            if (!is_string($binary) || $binary === '' || $binary[0] !== '/') {
                throw new PolicyViolationException('process.bad_allowlist', 'Allowed binaries must be absolute paths.');
            }
            $normalized[] = $binary;
        }
        $this->allowedBinaries = $normalized;
        $this->environment = $environment !== [] ? $environment : self::defaultEnvironment();
        $this->maxCaptureBytes = $maxCaptureBytes;
    }

    /**
     * A deliberately small environment.
     *
     * SSH_AUTH_SOCK is not forwarded: the transport names an explicit key file,
     * so an agent socket inherited from whoever started the process must never
     * become an alternative source of authority.
     *
     * @return array<string,string>
     */
    public static function defaultEnvironment(): array
    {
        return [
            'PATH' => '/usr/bin:/bin',
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ];
    }

    /** @return list<string> */
    public function allowedBinaries(): array
    {
        return $this->allowedBinaries;
    }

    /**
     * Validate an argv vector without running it.
     *
     * Exposed so tests and the dry-run path can assert exactly what would be
     * executed for a hostile input, with no process ever being started.
     *
     * @param list<string> $argv
     */
    public function assertArgvAcceptable(array $argv): void
    {
        if ($argv === []) {
            throw new PolicyViolationException('process.empty_argv', 'Argument vector is empty.');
        }
        $binary = $argv[0];
        if (!in_array($binary, $this->allowedBinaries, true)) {
            throw new PolicyViolationException(
                'process.binary_not_allowed',
                'Executable is not on the allowlist.',
                ['binary' => basename((string) $binary)]
            );
        }
        foreach ($argv as $index => $arg) {
            if (!is_string($arg)) {
                throw new PolicyViolationException('process.arg_not_string', 'Argument is not a string.', ['index' => $index]);
            }
            if (strpos($arg, "\0") !== false) {
                throw new PolicyViolationException('process.arg_nul_byte', 'Argument contains a NUL byte.', ['index' => $index]);
            }
            if ($arg === '') {
                throw new PolicyViolationException('process.arg_empty', 'Argument is empty.', ['index' => $index]);
            }
        }
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv, ?int $timeoutSeconds = null, ?string $workingDirectory = null): ProcessResult
    {
        $this->assertArgvAcceptable($argv);
        $timeout = $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
        if ($timeout < 1 || $timeout > 86400) {
            throw new PolicyViolationException('process.bad_timeout', 'Timeout is out of range.');
        }
        if (!is_file($argv[0]) || !is_executable($argv[0])) {
            throw new TransportException(
                'process.binary_missing',
                'Executable is missing or not executable on this host.',
                ['binary' => basename($argv[0])]
            );
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $started = microtime(true);

        // Array form: no shell is involved, so no argument can be reinterpreted.
        $process = @proc_open($argv, $descriptors, $pipes, $workingDirectory, $this->environment);
        if (!is_resource($process)) {
            throw new TransportException(
                'process.spawn_failed',
                'Child process could not be started.',
                ['binary' => basename($argv[0])]
            );
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $elapsed = microtime(true) - $started;
            if ($elapsed >= $timeout) {
                $timedOut = true;
                break;
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            $remaining = max(1, (int) ceil($timeout - $elapsed));
            $ready = @stream_select($read, $write, $except, min($remaining, 1), 0);
            if ($ready === false) {
                break;
            }
            foreach ($open as $fd => $stream) {
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$fd]);
                    continue;
                }
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($fd === 1) {
                    $stdout = self::appendBounded($stdout, $chunk, $this->maxCaptureBytes);
                } else {
                    $stderr = self::appendBounded($stderr, $chunk, $this->maxCaptureBytes);
                }
            }
        }

        if ($timedOut) {
            @proc_terminate($process, 15);
            // Give the child a moment to exit on SIGTERM before SIGKILL.
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(50000);
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                @proc_terminate($process, 9);
            }
        }

        foreach ($open as $stream) {
            @fclose($stream);
        }
        $exitCode = proc_close($process);
        if ($timedOut) {
            $exitCode = 124;
        }

        return new ProcessResult(
            $argv,
            $exitCode,
            Redactor::processOutput($stdout),
            Redactor::processOutput($stderr),
            round(microtime(true) - $started, 3),
            $timedOut
        );
    }

    private static function appendBounded(string $buffer, string $chunk, int $max): string
    {
        if (strlen($buffer) >= $max) {
            return $buffer;
        }

        return substr($buffer . $chunk, 0, $max);
    }
}
