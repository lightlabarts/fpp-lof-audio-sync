<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

interface MasterSource
{
    /** A verified snapshot of the masters, or an exception; never a partial set. */
    public function load(): MasterSet;

    /** Whether $set still describes the supply generation that is current now. */
    public function stillCurrent(MasterSet $set): bool;
}
