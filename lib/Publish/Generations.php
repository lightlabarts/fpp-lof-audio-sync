<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\PublishException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Support\SafePath;

/**
 * On-disk layout of the publication root, and the only code that moves a
 * generation between states.
 *
 *   <root>/staging/<gen>/         in progress; holds .incomplete until verified
 *   <root>/generations/<gen>/     verified and immutable
 *   <root>/manifests/<gen>.json   the proof for that generation
 *   <root>/current -> generations/<gen>    what a consumer reads
 *   <root>/previous -> generations/<gen>   last known good
 *   <root>/quarantine/<stamp>/    assets that vanished upstream
 *   <root>/history.json           bounded activation history
 *   <root>/health.json            machine-readable status
 *   <root>/locks/publish.lock
 *
 * `current` and `previous` are relative symlinks swapped by rename(), which is
 * atomic: a reader either sees the whole old generation or the whole new one.
 */
final class Generations
{
    public const INCOMPLETE_MARKER = '.incomplete';
    public const HISTORY_VERSION = 1;
    public const MAX_HISTORY_ENTRIES = 50;

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(SafePath::normalizeAbsolute($root, 'publication_root'), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function initialise(): void
    {
        foreach ([$this->root, $this->stagingRoot(), $this->generationsRoot(), $this->manifestsRoot(), $this->quarantineRoot(), $this->locksRoot()] as $dir) {
            Fs::ensureDir($dir);
        }
    }

    public function stagingRoot(): string
    {
        return $this->root . '/staging';
    }

    public function generationsRoot(): string
    {
        return $this->root . '/generations';
    }

    public function manifestsRoot(): string
    {
        return $this->root . '/manifests';
    }

    public function quarantineRoot(): string
    {
        return $this->root . '/quarantine';
    }

    public function locksRoot(): string
    {
        return $this->root . '/locks';
    }

    public function lockPath(): string
    {
        return $this->locksRoot() . '/publish.lock';
    }

    public function historyPath(): string
    {
        return $this->root . '/history.json';
    }

    public function healthPath(): string
    {
        return $this->root . '/health.json';
    }

    public function currentLink(): string
    {
        return $this->root . '/current';
    }

    public function previousLink(): string
    {
        return $this->root . '/previous';
    }

    /**
     * A generation id is a UTC stamp plus random suffix. The grammar is
     * enforced everywhere an id is turned into a path, so an id can never carry
     * a traversal.
     */
    public static function newGenerationId(?string $nowUtc = null): string
    {
        $stamp = $nowUtc ?? gmdate('Ymd\THis\Z');

        return $stamp . '-' . bin2hex(random_bytes(4));
    }

    public static function isValidGenerationId(string $id): bool
    {
        return preg_match('/^\d{8}T\d{6}Z-[0-9a-f]{8}$/', $id) === 1;
    }

    public function assertValidGenerationId(string $id): void
    {
        if (!self::isValidGenerationId($id)) {
            throw new PublishException('generation.bad_id', 'Generation id has an unexpected shape.');
        }
    }

    public function stagingDir(string $generation): string
    {
        $this->assertValidGenerationId($generation);

        return $this->stagingRoot() . '/' . $generation;
    }

    public function generationDir(string $generation): string
    {
        $this->assertValidGenerationId($generation);

        return $this->generationsRoot() . '/' . $generation;
    }

    public function manifestPath(string $generation): string
    {
        $this->assertValidGenerationId($generation);

        return $this->manifestsRoot() . '/' . $generation . '.json';
    }

    public function markIncomplete(string $generation): void
    {
        Fs::writeFileAtomic(
            $this->stagingDir($generation) . '/' . self::INCOMPLETE_MARKER,
            Json::pretty(['generation' => $generation, 'started_utc' => Manifest::nowUtc(), 'pid' => getmypid()]),
            0640
        );
    }

    public function clearIncomplete(string $generation): void
    {
        @unlink($this->stagingDir($generation) . '/' . self::INCOMPLETE_MARKER);
    }

    public function isIncomplete(string $generation): bool
    {
        return is_file($this->stagingDir($generation) . '/' . self::INCOMPLETE_MARKER);
    }

    /** @return list<string> */
    public function listStaging(): array
    {
        return $this->listChildDirectories($this->stagingRoot());
    }

    /** @return list<string> */
    public function listGenerations(): array
    {
        return $this->listChildDirectories($this->generationsRoot());
    }

    /** @return list<string> */
    private function listChildDirectories(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !self::isValidGenerationId($entry)) {
                continue;
            }
            if (is_dir($dir . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    private function generationFromLink(string $linkPath): ?string
    {
        $target = Fs::readSymlink($linkPath);
        if ($target === null) {
            return null;
        }
        $id = basename($target);

        return self::isValidGenerationId($id) ? $id : null;
    }

    public function currentGeneration(): ?string
    {
        return $this->generationFromLink($this->currentLink());
    }

    public function previousGeneration(): ?string
    {
        return $this->generationFromLink($this->previousLink());
    }

    /**
     * Move a fully verified staging directory into the immutable generation
     * store. rename() within one filesystem is atomic, so the generation
     * appears complete or not at all.
     */
    public function promoteStaging(string $generation): string
    {
        $staging = $this->stagingDir($generation);
        $target = $this->generationDir($generation);
        if (!is_dir($staging)) {
            throw new PublishException('generation.staging_missing', 'Staging directory does not exist.');
        }
        if ($this->isIncomplete($generation)) {
            throw new PublishException('generation.staging_incomplete', 'Refusing to promote an incomplete staging directory.');
        }
        if (file_exists($target)) {
            throw new PublishException('generation.already_exists', 'Generation directory already exists.');
        }
        Fs::ensureDir($this->generationsRoot());
        if (!@rename($staging, $target)) {
            throw new PublishException('generation.promote_failed', 'Staging directory could not be promoted.');
        }
        Fs::fsyncDir($this->generationsRoot());

        return $target;
    }

    /**
     * Point `current` at $generation, keeping the outgoing one as `previous`.
     *
     * @return string|null the generation that was current before this call
     */
    public function activate(string $generation): ?string
    {
        $this->assertValidGenerationId($generation);
        if (!is_dir($this->generationDir($generation))) {
            throw new PublishException('activate.generation_missing', 'Generation directory does not exist.');
        }
        if (!is_file($this->manifestPath($generation))) {
            throw new PublishException('activate.manifest_missing', 'Generation has no manifest.');
        }

        $outgoing = $this->currentGeneration();
        if ($outgoing !== null && $outgoing !== $generation) {
            Fs::atomicSymlink('generations/' . $outgoing, $this->previousLink());
        }
        Fs::atomicSymlink('generations/' . $generation, $this->currentLink());

        return $outgoing;
    }

    /**
     * Swap back to `previous`, after re-proving it.
     */
    public function rollback(): string
    {
        $target = $this->previousGeneration();
        if ($target === null) {
            throw new PublishException('rollback.no_previous', 'There is no previous generation to roll back to.');
        }
        $dir = $this->generationDir($target);
        if (!is_dir($dir) || !is_file($this->manifestPath($target))) {
            throw new PublishException('rollback.previous_missing', 'The previous generation is no longer on disk.');
        }
        $manifest = Manifest::readFrom($this->manifestPath($target));
        $problems = $manifest->verifyAgainst($dir);
        if ($problems !== []) {
            throw new PublishException(
                'rollback.previous_unverified',
                'The previous generation failed verification; refusing to roll back to it.',
                ['problem_count' => count($problems)]
            );
        }

        $outgoing = $this->currentGeneration();
        if ($outgoing !== null && $outgoing !== $target) {
            Fs::atomicSymlink('generations/' . $outgoing, $this->previousLink());
        }
        Fs::atomicSymlink('generations/' . $target, $this->currentLink());

        return $target;
    }

    /** @return array<string,mixed> */
    public function history(): array
    {
        if (!is_file($this->historyPath())) {
            return ['history_version' => self::HISTORY_VERSION, 'entries' => []];
        }
        try {
            $raw = Json::readFile($this->historyPath());
        } catch (\Throwable $e) {
            return ['history_version' => self::HISTORY_VERSION, 'entries' => []];
        }
        if (!isset($raw['entries']) || !is_array($raw['entries'])) {
            return ['history_version' => self::HISTORY_VERSION, 'entries' => []];
        }

        return ['history_version' => self::HISTORY_VERSION, 'entries' => array_values($raw['entries'])];
    }

    /** @param array<string,mixed> $entry */
    public function recordActivation(array $entry, int $bound = self::MAX_HISTORY_ENTRIES): void
    {
        $history = $this->history();
        array_unshift($history['entries'], $entry);
        $history['entries'] = array_slice($history['entries'], 0, max(1, min($bound, self::MAX_HISTORY_ENTRIES)));
        Fs::writeFileAtomic($this->historyPath(), Json::pretty($history), 0640);
    }

    /**
     * Copy vanished assets into a timestamped quarantine generation.
     *
     * Nothing is deleted here. The assets keep existing in the generation they
     * came from until retention decides otherwise, and the quarantine copy is
     * what survives once that generation is pruned.
     *
     * @param list<string> $relativePaths
     * @return array{directory:string,count:int}|null
     */
    public function quarantine(array $relativePaths, string $fromGenerationDir, string $reason, ?string $nowUtc = null): ?array
    {
        if ($relativePaths === []) {
            return null;
        }
        $stamp = ($nowUtc ?? gmdate('Ymd\THis\Z')) . '-' . bin2hex(random_bytes(4));
        $dir = $this->quarantineRoot() . '/' . $stamp;
        Fs::ensureDir($dir . '/assets');

        $recorded = [];
        foreach ($relativePaths as $relative) {
            SafePath::assertRelative($relative);
            $from = SafePath::join($fromGenerationDir, $relative);
            if (!is_file($from) || is_link($from)) {
                $recorded[$relative] = ['status' => 'source_unavailable'];
                continue;
            }
            $to = SafePath::join($dir . '/assets', $relative);
            SafePath::assertWithin($dir . '/assets', $to, 'quarantine_asset');
            Fs::copyFileDurable($from, $to);
            $recorded[$relative] = [
                'status' => 'quarantined',
                'size' => (int) filesize($to),
                'sha256' => Fs::sha256File($to),
            ];
        }

        Fs::writeFileAtomic($dir . '/quarantine.json', Json::pretty([
            'quarantine_version' => 1,
            'created_utc' => Manifest::nowUtc(),
            'reason' => $reason,
            'origin_generation' => basename($fromGenerationDir),
            'asset_count' => count($recorded),
            'assets' => $recorded,
            'deletion_policy' => 'retention-only; synchronisation never deletes',
        ]), 0640);

        return ['directory' => $dir, 'count' => count($recorded)];
    }

    /** @return list<string> */
    public function listQuarantine(): array
    {
        if (!is_dir($this->quarantineRoot())) {
            return [];
        }
        $entries = @scandir($this->quarantineRoot());
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^\d{8}T\d{6}Z-[0-9a-f]{8}$/', $entry) === 1 && is_dir($this->quarantineRoot() . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Drop generations that are neither current, nor previous, nor inside the
     * retained window.
     *
     * @return list<string> generations that were removed
     */
    public function pruneGenerations(int $retain): array
    {
        $retain = max(1, $retain);
        $keep = [];
        foreach ([$this->currentGeneration(), $this->previousGeneration()] as $pinned) {
            if ($pinned !== null) {
                $keep[$pinned] = true;
            }
        }
        $all = $this->listGenerations();
        rsort($all, SORT_STRING);
        $kept = 0;
        foreach ($all as $generation) {
            if (isset($keep[$generation])) {
                continue;
            }
            if ($kept < $retain) {
                $keep[$generation] = true;
                $kept++;
            }
        }

        $removed = [];
        foreach ($all as $generation) {
            if (isset($keep[$generation])) {
                continue;
            }
            Fs::removeTree($this->generationDir($generation), $this->root);
            @unlink($this->manifestPath($generation));
            $removed[] = $generation;
        }

        return $removed;
    }

    /**
     * Remove staging directories left behind by an interrupted run.
     *
     * Only directories older than $olderThanSeconds are touched, so a run that
     * is still in flight in another process is never swept out from under it.
     *
     * @return list<string>
     */
    public function reclaimStaging(int $olderThanSeconds = 3600): array
    {
        $reclaimed = [];
        $cutoff = time() - max(0, $olderThanSeconds);
        foreach ($this->listStaging() as $generation) {
            $dir = $this->stagingDir($generation);
            $mtime = @filemtime($dir);
            if ($mtime !== false && $mtime > $cutoff) {
                continue;
            }
            Fs::removeTree($dir, $this->root);
            $reclaimed[] = $generation;
        }

        return $reclaimed;
    }

    /**
     * Delete quarantine generations older than the retention window.
     *
     * This is the *only* place assets are ever deleted, and it is driven by an
     * explicit retention policy plus an explicit confirmation, never by a
     * synchronisation request.
     *
     * @return list<string>
     */
    public function pruneQuarantine(int $retentionDays, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new PublishException('quarantine.not_confirmed', 'Quarantine pruning requires explicit confirmation.');
        }
        if ($retentionDays < 1) {
            throw new PublishException('quarantine.bad_retention', 'Retention must be at least one day.');
        }
        $cutoff = time() - ($retentionDays * 86400);
        $removed = [];
        foreach ($this->listQuarantine() as $entry) {
            $dir = $this->quarantineRoot() . '/' . $entry;
            $mtime = @filemtime($dir);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }
            Fs::removeTree($dir, $this->root);
            $removed[] = $entry;
        }

        return $removed;
    }
}
