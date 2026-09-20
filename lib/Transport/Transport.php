<?php

declare(strict_types=1);

namespace LofAudioSupply\Transport;

/**
 * Moves a named set of assets from a source root to a destination root.
 *
 * The publisher only ever asks for an explicit list of validated relative
 * paths. A transport is never handed a pattern, a glob, a command fragment, or
 * a delete instruction - removal is the quarantine step's job, not a
 * transport's.
 */
interface Transport
{
    public function name(): string;

    /**
     * @param list<string> $relativePaths validated, source-root-relative
     */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport;

    /** @return array<string,mixed> redacted description for the health record */
    public function describe(): array;
}
