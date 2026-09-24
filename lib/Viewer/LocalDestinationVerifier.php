<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Publish\Generations;
use LofAudioSupply\PolicyViolationException;

/**
 * Destination read-back for destinations on this host.
 *
 * The delivered tree has the contract layout, so it is read with the same
 * ViewerLayout and judged with the same Contract pipeline as the local
 * publication - which is what lof-core's reader implements.
 */
final class LocalDestinationVerifier implements DestinationVerifier
{
    private ViewerLayout $layout;

    public function __construct(string $viewerDestination, string $privateDestination, private bool $rootsPublic = false)
    {
        $this->layout = new ViewerLayout($viewerDestination, $privateDestination);
    }

    public function healthBytes(): ?string
    {
        $path = $this->layout->healthPath();
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function verdict(?array $health): array
    {
        $generation = is_array($health) && is_array($health['current'] ?? null) && is_string($health['current']['generation'] ?? null)
            && Generations::isValidGenerationId($health['current']['generation']) ? $health['current']['generation'] : null;
        if ($generation === null) {
            return Contract::pipeline($health, null, null, null);
        }

        return Contract::pipeline(
            $health,
            ViewerLayout::readDocument($this->layout->manifestPath($generation)),
            ViewerLayout::readDocument($this->layout->sourceMapPath($generation)),
            Contract::observe($this->layout->generationDir($generation), $this->rootsPublic)
        );
    }

    public function fileBytes(string $which, string $relative): ?string
    {
        $root = $which === 'private' ? $this->layout->privateRoot : $this->layout->viewerRoot;
        $path = $root . '/' . $relative;
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function assertNoSymlinkedDirectories(string $generation): void
    {
        $dirs = [
            $this->layout->viewerRoot, $this->layout->generationsRoot(), $this->layout->generationDir($generation),
            $this->layout->manifestsRoot(), $this->layout->privateRoot, $this->layout->sourceMapsRoot(),
        ];
        foreach ($dirs as $dir) {
            if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
                throw new PolicyViolationException('viewer_distribute.destination_symlink', 'A destination directory is a symlink or not a directory.');
            }
        }
    }
}
