<?php
/**
 * Transport test doubles.
 *
 * These stand in for the failure modes a real transfer hits - a process killed
 * mid-copy, a link that drops, bytes that arrive corrupted - so the suite can
 * prove the active generation survives each of them without needing a live
 * host or an actual power cut.
 */

declare(strict_types=1);

namespace LofTest;

use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\Transport\TransferReport;
use LofAudioSupply\Transport\Transport;
use LofAudioSupply\TransportException;

/** Copies the first N assets, then dies the way a killed rsync would. */
final class InterruptedTransport implements Transport
{
    public function __construct(private int $copyBeforeFailing, private string $errorCode = 'transport.interrupted')
    {
    }

    public function name(): string
    {
        return 'interrupted';
    }

    /** @param list<string> $relativePaths */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        $copied = 0;
        foreach ($relativePaths as $relative) {
            if ($copied >= $this->copyBeforeFailing) {
                throw new TransportException($this->errorCode, 'Transfer was interrupted.');
            }
            Fs::copyFileDurable(SafePath::join($sourceRoot, $relative), SafePath::join($destinationRoot, $relative));
            $copied++;
        }

        throw new TransportException($this->errorCode, 'Transfer was interrupted.');
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['transport' => $this->name()];
    }
}

/** Copies everything but silently corrupts one named asset. */
final class CorruptingTransport implements Transport
{
    public function __construct(private string $corruptRelative, private bool $keepSize = true)
    {
    }

    public function name(): string
    {
        return 'corrupting';
    }

    /** @param list<string> $relativePaths */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        foreach ($relativePaths as $relative) {
            $from = SafePath::join($sourceRoot, $relative);
            $to = SafePath::join($destinationRoot, $relative);
            Fs::copyFileDurable($from, $to);
            if ($relative === $this->corruptRelative) {
                $original = (string) file_get_contents($to);
                // Same length, different bytes: only a digest catches this.
                $replacement = $this->keepSize
                    ? str_repeat('X', strlen($original))
                    : $original . 'extra';
                file_put_contents($to, $replacement);
            }
        }

        return new TransferReport($this->name(), count($relativePaths), 0, 0.0, [], '');
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['transport' => $this->name()];
    }
}

/** Copies everything, then drops an extra file the manifest never described. */
final class ExtraFileTransport implements Transport
{
    public function __construct(private string $extraRelative = 'not-in-manifest.mp3')
    {
    }

    public function name(): string
    {
        return 'extra-file';
    }

    /** @param list<string> $relativePaths */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        foreach ($relativePaths as $relative) {
            Fs::copyFileDurable(SafePath::join($sourceRoot, $relative), SafePath::join($destinationRoot, $relative));
        }
        file_put_contents(SafePath::join($destinationRoot, $this->extraRelative), 'smuggled');

        return new TransferReport($this->name(), count($relativePaths), 0, 0.0, [], '');
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['transport' => $this->name()];
    }
}

/** Fails before touching anything, the way a dead SSH link does. */
final class UnreachableTransport implements Transport
{
    public function __construct(private string $errorCode = 'transport.ssh_lost')
    {
    }

    public function name(): string
    {
        return 'unreachable';
    }

    /** @param list<string> $relativePaths */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        throw new TransportException($this->errorCode, 'Connection to the destination was lost.');
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['transport' => $this->name()];
    }
}
