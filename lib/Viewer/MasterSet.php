<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

/**
 * The masters one viewer generation is built from, taken from exactly one
 * verified media-supply generation.
 */
final class MasterSet
{
    /**
     * @param array<string,array{path:string,size:int,sha256:string}> $masters source_rel => facts, sorted by source_rel
     * @param list<string> $supplyDigests sha256 of every asset in the supply generation (a rendition may equal none)
     */
    public function __construct(
        public readonly string $supplyGeneration,
        public readonly string $supplyManifestSha256,
        public readonly array $masters,
        public readonly array $supplyDigests
    ) {
    }
}
