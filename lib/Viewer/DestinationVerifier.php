<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

/**
 * Reads a delivered publication back and judges it exactly as lof-core will.
 *
 * Health-last is only meaningful if the destination bytes are proved before
 * the pointer moves. A transport that can write but not read back therefore
 * cannot deliver a viewer publication: ViewerDistributor refuses to run
 * without a verifier.
 */
interface DestinationVerifier
{
    /** The destination health.json bytes, or null when absent. */
    public function healthBytes(): ?string;

    /**
     * The contract pipeline over the destination, using $health as the
     * pointer (the candidate before commit, the live one after).
     *
     * @param array<string,mixed>|null $health
     * @return array{0:string,1:string}
     */
    public function verdict(?array $health): array;

    /** Exact bytes of a delivered file, relative to the named destination ('viewer' or 'private'). */
    public function fileBytes(string $which, string $relative): ?string;

    /** Refuse a destination tree that has a symlinked directory on the delivery path. */
    public function assertNoSymlinkedDirectories(string $generation): void;
}
