<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\IntegrityException;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\Support\SafePath;

/**
 * Masters come only from the verified current media-supply generation: its
 * manifest must parse and self-verify, and the generation directory must match
 * it exactly (no missing, extra, resized or altered file) before any master is
 * handed to the encoder. The supply publication is read, never written.
 */
final class SupplyMasterSource implements MasterSource
{
    public function __construct(private Generations $supply)
    {
    }

    public function load(): MasterSet
    {
        $generation = $this->supply->currentGeneration();
        if ($generation === null) {
            throw new IntegrityException('viewer.supply_missing', 'There is no current media-supply generation.');
        }
        try {
            $manifest = Manifest::readFrom($this->supply->manifestPath($generation));
        } catch (\Throwable $e) {
            throw new IntegrityException('viewer.supply_unverified', 'The media-supply manifest does not verify.', ['generation' => $generation]);
        }
        $dir = $this->supply->generationDir($generation);
        if ($manifest->generation !== $generation || $manifest->verifyAgainst($dir) !== []) {
            throw new IntegrityException('viewer.supply_unverified', 'The media-supply generation does not match its manifest.', ['generation' => $generation]);
        }
        $masters = [];
        $digests = [];
        foreach ($manifest->assets() as $relative => $facts) {
            $digests[] = $facts['sha256'];
            if (!ViewerProfile::isMasterName($relative)) {
                continue;
            }
            $masters[$relative] = ['path' => SafePath::join($dir, $relative), 'size' => $facts['size'], 'sha256' => $facts['sha256']];
        }
        ksort($masters, SORT_STRING);

        return new MasterSet($generation, $manifest->digest(), $masters, array_values(array_unique($digests)));
    }

    public function stillCurrent(MasterSet $set): bool
    {
        return $this->supply->currentGeneration() === $set->supplyGeneration;
    }
}
