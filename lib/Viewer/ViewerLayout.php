<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\PublishException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;

/**
 * On-disk layout of the viewer publication (contract V1 section 2).
 *
 *   <viewer_root>/health.json                    commit point, written last
 *   <viewer_root>/manifests/<gen>.json           visitor-safe manifest
 *   <viewer_root>/generations/<gen>/<rid>.m4a    renditions only
 *   <viewer_root>/staging/<gen>/                 in progress; .incomplete until verified
 *   <viewer_root>/quarantine/<stamp>/            interrupted or unreferenced renditions
 *   <private_root>/source-maps/<gen>.json        private source map
 *   <private_root>/quarantine/<stamp>/           unreferenced source maps
 *   <private_root>/viewer-ledger.json            generations health.json has named
 *
 * Staging and quarantine sit beside `generations/` so every promotion and
 * every quarantine move is a same-filesystem rename(). Nothing here ever
 * holds a master: the encoder writes renditions and nothing else.
 */
final class ViewerLayout
{
    public const INCOMPLETE_MARKER = '.incomplete';

    public function __construct(public readonly string $viewerRoot, public readonly string $privateRoot)
    {
    }

    public function initialise(): void
    {
        foreach ([$this->viewerRoot, $this->generationsRoot(), $this->manifestsRoot()] as $dir) {
            Fs::ensureDir($dir, 0755);
        }
        foreach ([$this->stagingRoot(), $this->quarantineRoot(), $this->privateRoot, $this->sourceMapsRoot(), $this->privateQuarantineRoot()] as $dir) {
            Fs::ensureDir($dir, 0750);
        }
    }

    public function healthPath(): string
    {
        return $this->viewerRoot . '/health.json';
    }

    public function generationsRoot(): string
    {
        return $this->viewerRoot . '/generations';
    }

    public function manifestsRoot(): string
    {
        return $this->viewerRoot . '/manifests';
    }

    public function stagingRoot(): string
    {
        return $this->viewerRoot . '/staging';
    }

    public function quarantineRoot(): string
    {
        return $this->viewerRoot . '/quarantine';
    }

    public function sourceMapsRoot(): string
    {
        return $this->privateRoot . '/source-maps';
    }

    public function privateQuarantineRoot(): string
    {
        return $this->privateRoot . '/quarantine';
    }

    public function ledgerPath(): string
    {
        return $this->privateRoot . '/viewer-ledger.json';
    }

    private static function gen(string $generation): string
    {
        if (!Generations::isValidGenerationId($generation)) {
            throw new PublishException('generation.bad_id', 'Generation id has an unexpected shape.');
        }

        return $generation;
    }

    public function generationDir(string $generation): string
    {
        return $this->generationsRoot() . '/' . self::gen($generation);
    }

    public function stagingDir(string $generation): string
    {
        return $this->stagingRoot() . '/' . self::gen($generation);
    }

    public function manifestPath(string $generation): string
    {
        return $this->manifestsRoot() . '/' . self::gen($generation) . '.json';
    }

    public function sourceMapPath(string $generation): string
    {
        return $this->sourceMapsRoot() . '/' . self::gen($generation) . '.json';
    }

    /**
     * A JSON document, or null when it is absent, a symlink, or unparseable.
     * Null is "absent" to the contract pipeline, which fails closed on it.
     *
     * @return array<string,mixed>|null
     */
    public static function readDocument(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        try {
            return Json::readFile($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return list<string> */
    public function ledger(): array
    {
        $doc = self::readDocument($this->ledgerPath());
        $out = [];
        foreach ((is_array($doc['generations'] ?? null) ? $doc['generations'] : []) as $g) {
            if (is_string($g) && Generations::isValidGenerationId($g)) {
                $out[] = $g;
            }
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);

        return $out;
    }

    /** @param list<string> $generations */
    public function writeLedger(array $generations): void
    {
        $generations = array_values(array_unique($generations));
        sort($generations, SORT_STRING);
        Fs::writeFileAtomic($this->ledgerPath(), Json::pretty(['ledger_version' => 1, 'generations' => $generations]), 0640);
    }

    /** @return list<string> entry names, dotfiles included */
    public static function entries(string $dir): array
    {
        if (is_link($dir) || !is_dir($dir)) {
            return [];
        }
        $names = @scandir($dir);
        if ($names === false) {
            throw new PublishException('viewer.unreadable', 'A viewer publication directory could not be listed.');
        }
        $out = array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..'));
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Move (never delete) entries into a fresh quarantine generation.
     *
     * $viewerItems and $privateItems are [kind, absolute path] pairs. Viewer
     * items go under the viewer quarantine; private items (source maps) under
     * the private quarantine, so a source map can never land in the viewer
     * tree. Each side records what it holds by entry name only.
     *
     * @param list<array{0:string,1:string}> $viewerItems
     * @param list<array{0:string,1:string}> $privateItems
     * @return list<array{kind:string,name:string}>
     */
    public function quarantine(array $viewerItems, array $privateItems, string $reason): array
    {
        if ($viewerItems === [] && $privateItems === []) {
            return [];
        }
        $stamp = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $moved = [];
        foreach ([[$this->quarantineRoot(), $viewerItems], [$this->privateQuarantineRoot(), $privateItems]] as [$root, $items]) {
            if ($items === []) {
                continue;
            }
            $dir = $root . '/' . $stamp;
            $record = [];
            foreach ($items as [$kind, $path]) {
                $name = basename($path);
                Fs::ensureDir($dir . '/' . $kind, 0750);
                if (!@rename($path, $dir . '/' . $kind . '/' . $name)) {
                    throw new PublishException('viewer.quarantine_failed', 'An entry could not be moved into quarantine.', ['kind' => $kind]);
                }
                $record[] = ['kind' => $kind, 'name' => $name];
                $moved[] = ['kind' => $kind, 'name' => $name];
            }
            Fs::fsyncDir($dir);
            Fs::writeFileAtomic($dir . '/quarantine.json', Json::pretty([
                'quarantine_version' => 1,
                'created_utc' => Manifest::nowUtc(),
                'reason' => $reason,
                'items' => $record,
                'deletion_policy' => 'retention-only; recovery never deletes',
            ]), 0640);
        }

        return $moved;
    }
}
