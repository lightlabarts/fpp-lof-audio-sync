<?php

declare(strict_types=1);

namespace LofAudioSupply\Transport;

/**
 * What a transfer actually moved. Never carries raw process output that has
 * not been through the redactor.
 */
final class TransferReport
{
    /**
     * @param list<string> $failures redacted, one line per refused asset
     */
    public function __construct(
        public readonly string $transport,
        public readonly int $filesTransferred,
        public readonly int $bytesTransferred,
        public readonly float $durationSeconds,
        public readonly array $failures,
        public readonly string $output
    ) {
    }

    public function ok(): bool
    {
        return $this->failures === [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'transport' => $this->transport,
            'files_transferred' => $this->filesTransferred,
            'bytes_transferred' => $this->bytesTransferred,
            'duration_seconds' => $this->durationSeconds,
            'failure_count' => count($this->failures),
            'failures' => array_slice($this->failures, 0, 20),
        ];
    }
}
