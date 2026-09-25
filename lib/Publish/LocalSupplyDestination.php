<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\PublishException;
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

    public function generationExists(string $generation): bool
    {
        $dir = $this->generations->generationDir($generation);

        return file_exists($dir) || is_link($dir);
    }

    private function manifestStagingDir(string $generation): string
    {
        return $this->generations->stagingDir($generation) . '.manifest';
    }

    public function quarantineStaging(string $generation): int
    {
        $moved = 0;
        $into = null;
        foreach ([$this->generations->stagingDir($generation), $this->manifestStagingDir($generation)] as $path) {
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            if ($into === null) {
                $into = $this->generations->quarantineRoot() . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '/staging';
                Fs::ensureDir($into, 0750);
            }
            if (!@rename($path, $into . '/' . basename($path))) {
                throw new PublishException('supply_deliver.quarantine_failed', 'Stale staging could not be moved to quarantine.');
            }
            $moved++;
        }
        if ($into !== null) {
            Fs::fsyncDir($this->generations->stagingRoot());
        }

        return $moved;
    }

    public function beginStaging(string $generation): string
    {
        $dir = $this->generations->stagingDir($generation);
        if (file_exists($dir) || is_link($dir)) {
            throw new PublishException('supply_deliver.staging_exists', 'Staging for this generation already exists.');
        }
        Fs::ensureDir($dir, 0750);
        $this->generations->markIncomplete($generation);

        return $dir;
    }

    public function beginManifestStaging(string $generation): string
    {
        $dir = $this->manifestStagingDir($generation);
        if (file_exists($dir) || is_link($dir)) {
            throw new PublishException('supply_deliver.staging_exists', 'Manifest staging for this generation already exists.');
        }
        Fs::ensureDir($dir, 0750);

        return $dir;
    }

    public function verifyStaging(Manifest $manifest, string $generation): array
    {
        return array_values(array_filter(
            $manifest->verifyAgainst($this->generations->stagingDir($generation)),
            static fn (array $p): bool => $p['asset'] !== Generations::INCOMPLETE_MARKER
        ));
    }

    public function stagedManifestBytes(string $generation): ?string
    {
        $path = $this->manifestStagingDir($generation) . '/' . $generation . '.json';
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function promote(string $generation): void
    {
        $this->generations->clearIncomplete($generation);
        $this->generations->promoteStaging($generation);
    }

    public function commitManifest(string $generation): void
    {
        $target = $this->generations->manifestPath($generation);
        if (file_exists($target) || is_link($target)) {
            throw new PublishException('supply_deliver.manifest_exists', 'A manifest for this generation already exists.');
        }
        $staged = $this->manifestStagingDir($generation);
        Fs::ensureDir($this->generations->manifestsRoot(), 0750);
        if (!@rename($staged . '/' . $generation . '.json', $target)) {
            throw new PublishException('supply_deliver.manifest_commit_failed', 'The staged manifest could not be committed.');
        }
        Fs::fsyncDir($this->generations->manifestsRoot());
        @rmdir($staged);
    }

    public function activate(string $generation): void
    {
        $this->generations->activate($generation);
    }
}
