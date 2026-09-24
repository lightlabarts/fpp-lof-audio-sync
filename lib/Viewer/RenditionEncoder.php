<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

/**
 * Turns one verified master into one viewer rendition file.
 *
 * An implementation writes only $output, which does not exist beforehand, and
 * throws on any failure. It never reports child output: an encoder's stderr
 * names the master, and nothing the lane surfaces may do that.
 */
interface RenditionEncoder
{
    public function name(): string;

    public function encode(string $source, string $output): void;
}
