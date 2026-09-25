<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\SafePath;

/**
 * A destination supply root on this host, driven by the same Generations and
 * Manifest code FPP uses for its own publication.
 */
final class LocalSupplyDestination implements SupplyDestination
{
    private Generations $generations;

    public function __construct(private string $root)
    {
        $this->generations = new Generations($root);
    }

    public function lockPath(): string
    {
        return $this->generations->lockPath();
    }

    public function prepare(string $generation): void
    {
        $g = $this->generations;
        $dirs = [$this->root, $g->generationsRoot(), $g->generationDir($generation), $g->manifestsRoot(), $g->locksRoot()];
        foreach ($dirs as $dir) {
            if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
                throw new PolicyViolationException('supply_deliver.destination_symlink', 'A destination layout directory is a symlink or not a directory.');
            }
        }
        foreach ([$g->currentLink(), $g->previousLink()] as $link) {
            if (file_exists($link) && !is_link($link)) {
                throw new PolicyViolationException('supply_deliver.destination_layout', 'A destination pointer is not a symlink.');
            }
        }
        $g->initialise();
    }

    public function assertRealContainment(string $relative): void
    {
        SafePath::assertRelative($relative);
        // Resolves every existing component, the file itself included, so a
        // planted link anywhere on the path that leaves the root is refused.
        SafePath::assertRealWithin($this->root, SafePath::join($this->root, $relative), 'supply_delivery_asset');
    }

    public function pointers(): array
    {
        $read = static function (string $link): ?string {
            if (is_link($link)) {
                return Fs::readSymlink($link);
            }

            return file_exists($link) ? '!not-a-link' : null;
        };

        return ['current' => $read($this->generations->currentLink()), 'previous' => $read($this->generations->previousLink())];
    }

    public function currentGeneration(): ?string
    {
        return $this->generations->currentGeneration();
    }

    public function manifestBytes(string $generation): ?string
    {
        $path = $this->generations->manifestPath($generation);
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function verifyGeneration(Manifest $manifest, string $generation): array
    {
        return $manifest->verifyAgainst($this->generations->generationDir($generation));
    }

    public function activate(string $generation): void
    {
        $this->generations->activate($generation);
    }
}
