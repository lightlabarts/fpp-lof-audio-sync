<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

/**
 * Outcome of one publish attempt.
 */
final class PublishResult
{
    public const OUTCOME_PUBLISHED = 'published';
    public const OUTCOME_UNCHANGED = 'unchanged';
    public const OUTCOME_FAILED = 'failed';

    /**
     * @param list<string> $quarantined
     * @param list<string> $prunedGenerations
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $generation,
        public readonly ?string $previousGeneration,
        public readonly int $assetCount,
        public readonly int $totalBytes,
        public readonly array $quarantined,
        public readonly array $prunedGenerations,
        public readonly float $durationSeconds,
        public readonly array $details = []
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'generation' => $this->generation,
            'previous_generation' => $this->previousGeneration,
            'asset_count' => $this->assetCount,
            'total_bytes' => $this->totalBytes,
            'quarantined_count' => count($this->quarantined),
            'quarantined' => array_slice($this->quarantined, 0, 50),
            'pruned_generations' => $this->prunedGenerations,
            'duration_seconds' => $this->durationSeconds,
            'details' => $this->details,
        ];
    }
}
