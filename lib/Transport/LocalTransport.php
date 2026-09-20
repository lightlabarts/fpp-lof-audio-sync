<?php

declare(strict_types=1);

namespace LofAudioSupply\Transport;

use LofAudioSupply\PublishException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\SafePath;

/**
 * Same-host transfer into the staging generation.
 *
 * This is the transport the publish pipeline uses in production: the FPP media
 * tree and the publication root are both local, so staging is a durable copy
 * rather than a network operation. Each asset is written to a `.part` file and
 * renamed, so an interrupted run leaves partial bytes in staging and never a
 * truncated file that could pass a size check.
 */
final class LocalTransport implements Transport
{
    private int $maxAssetBytes;

    public function __construct(int $maxAssetBytes = 2147483648)
    {
        $this->maxAssetBytes = $maxAssetBytes;
    }

    public function name(): string
    {
        return 'local';
    }

    /** @param list<string> $relativePaths */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        $started = microtime(true);
        $files = 0;
        $bytes = 0;
        $failures = [];

        $realSource = realpath($sourceRoot);
        if ($realSource === false) {
            throw new PublishException('transport.source_missing', 'Source root does not exist.');
        }
        Fs::ensureDir($destinationRoot);

        foreach ($relativePaths as $relative) {
            SafePath::assertRelative($relative);
            $from = SafePath::join($realSource, $relative);
            $to = SafePath::join($destinationRoot, $relative);

            // Re-check containment per asset: the tree may have changed between
            // the walk and the copy.
            SafePath::assertRealWithin($realSource, $from, 'asset_source');
            SafePath::assertWithin($destinationRoot, $to, 'asset_destination');

            if (!is_file($from) || (is_link($from) && SafePath::isSymlinkEscape($realSource, $from))) {
                $failures[] = $relative . ': source vanished or escaped';
                continue;
            }
            $size = @filesize($from);
            if ($size === false) {
                $failures[] = $relative . ': size unavailable';
                continue;
            }
            if ($size > $this->maxAssetBytes) {
                $failures[] = $relative . ': exceeds the maximum asset size';
                continue;
            }

            Fs::copyFileDurable($from, $to);
            $files++;
            $bytes += (int) $size;
        }

        return new TransferReport(
            $this->name(),
            $files,
            $bytes,
            round(microtime(true) - $started, 3),
            $failures,
            ''
        );
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['transport' => 'local', 'max_asset_bytes' => $this->maxAssetBytes];
    }
}
