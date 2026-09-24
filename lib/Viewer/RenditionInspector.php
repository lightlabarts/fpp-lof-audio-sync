<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

/**
 * Proves an encoded file is the V1 profile and carries no metadata.
 *
 * Problem codes starting with `metadata` or naming an unexpected box are
 * treated as a metadata leak; every other code is a profile mismatch. An
 * empty list is the only pass.
 */
interface RenditionInspector
{
    /** @return list<string> */
    public function inspect(string $path): array;
}
