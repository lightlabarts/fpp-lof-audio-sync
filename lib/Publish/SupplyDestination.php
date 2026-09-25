<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

/**
 * A destination supply root that can be read back and whose pointer can be
 * moved. Only a destination that can do both can receive a delivery.
 */
interface SupplyDestination
{
    public function lockPath(): string;

    /** Create the standard layout and refuse a symlinked or non-directory layout directory. */
    public function prepare(string $generation): void;

    /** Refuse a destination path whose real location leaves the destination root. */
    public function assertRealContainment(string $relative): void;

    /**
     * Raw link state of `current` and `previous`: the link target, null when
     * absent, or '!not-a-link' when something other than a symlink is there.
     *
     * @return array{current:?string,previous:?string}
     */
    public function pointers(): array;

    public function currentGeneration(): ?string;

    public function manifestBytes(string $generation): ?string;

    /**
     * Problems found verifying the delivered generation against $manifest
     * (exact file set, sizes, digests; symlinks refused).
     *
     * @return list<array{asset:string,problem:string}>
     */
    public function verifyGeneration(Manifest $manifest, string $generation): array;

    /** Move the pointers with the existing Generations::activate(). */
    public function activate(string $generation): void;
}
