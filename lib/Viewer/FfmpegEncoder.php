<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Process\ProcessRunner;
use LofAudioSupply\TransportException;

/**
 * The allowlisted encoder: ViewerProfile's fixed argv through the existing
 * ProcessRunner (argv array, allowlisted absolute binary, small environment,
 * bounded capture, timeout). No shell is involved at any point.
 */
final class FfmpegEncoder implements RenditionEncoder
{
    public function __construct(
        private ProcessRunner $runner,
        private string $binary,
        private int $timeoutSeconds
    ) {
    }

    public function name(): string
    {
        return 'ffmpeg';
    }

    /** @return list<string> */
    public function argv(string $source, string $output): array
    {
        return ViewerProfile::encoderArgv($this->binary, $source, $output);
    }

    public function encode(string $source, string $output): void
    {
        $argv = $this->argv($source, $output);
        $this->runner->assertArgvAcceptable($argv);
        if (!is_file($this->binary) || !is_executable($this->binary)) {
            throw new TransportException('viewer.encoder_missing', 'The allowlisted encoder is not installed on this host.');
        }
        $result = $this->runner->run($argv, $this->timeoutSeconds);
        if ($result->timedOut) {
            throw new TransportException('viewer.encode_timeout', 'Encoder exceeded its time limit.');
        }
        if (!$result->succeeded()) {
            // Exit status only. stderr names the master and is deliberately dropped.
            throw new TransportException('viewer.encode_failed', 'Encoder refused or failed on a master.', ['exit_code' => $result->exitCode]);
        }
        // The muxer's empty iTunes skeleton is the only tag structure it
        // writes under these flags; anything more is refused inside.
        TagSkeleton::neutralise($output);
    }
}
