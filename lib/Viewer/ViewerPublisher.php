<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Config\Settings;
use LofAudioSupply\IntegrityException;
use LofAudioSupply\LockException;
use LofAudioSupply\LofAudioException;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Lock;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\PublishException;
use LofAudioSupply\Status\Health;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;

/**
 * The viewer-rendition lane: verified masters in, one visitor-safe rendition
 * publication out (contract V1 section 5).
 *
 * The media-supply invariant carries over unchanged: **a failure never
 * disturbs the generation health.json names.** A new generation is encoded,
 * probed, hashed and verified in staging, promoted by one rename, described by
 * its manifest and then its private source map, re-verified from disk exactly
 * as lof-core will verify it, and only then made to exist by writing
 * health.json last. Every other outcome leaves health.json as it was, or - if
 * what it names no longer verifies - marks it failed so lof-core refuses it.
 *
 * The lane runs under the media-supply publish lock, so the supply generation
 * it reads cannot be replaced, pruned or rolled back while it encodes.
 *
 * Nothing this class returns, records or throws names a master: results carry
 * generation ids, rendition ids, counts and reason codes only.
 */
final class ViewerPublisher
{
    /** sha256 of the contract's fixture rid key; refused outside the fixture test. */
    public const FIXTURE_RID_KEY_SHA256 = 'fed1d454f6515ea63b1ec12f7829c79643ac822b8fd2e7ed80406632aeaefd47';

    public const LAST_RUN_FILE = 'viewer-last-run.json';

    private ViewerLayout $layout;

    public function __construct(
        private ViewerConfig $config,
        private Generations $supply,
        private ?Settings $settings,
        private MasterSource $masters,
        private RenditionEncoder $encoder,
        private RenditionInspector $inspector
    ) {
        $this->layout = new ViewerLayout($config->viewerRoot, $config->privateRoot);
    }

    public function layout(): ViewerLayout
    {
        return $this->layout;
    }

    /**
     * One publication cycle.
     *
     * $options are for deterministic tests only (the CLI never passes any):
     * generation, created_utc, generated_utc, allow_fixture_key.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function publish(array $options = []): array
    {
        $started = microtime(true);
        $this->config->assertSeparated();
        $this->layout->initialise();
        $lock = new Lock($this->supply->lockPath());
        $lock->acquire();

        $generation = null;
        $committed = false;
        $recovered = [];
        try {
            $recovered = $this->recoverLocked();
            $set = $this->masters->load();
            if ($set->masters === []) {
                throw new IntegrityException('viewer.no_masters', 'The supply generation holds no audio master.');
            }
            foreach ($set->masters as $m) {
                if ($m['size'] < 1) {
                    throw new IntegrityException('viewer.unsupported_input', 'A master is empty.');
                }
            }
            $key = $this->config->loadRidKey();
            if (hash_equals(self::FIXTURE_RID_KEY_SHA256, hash('sha256', $key)) && ($options['allow_fixture_key'] ?? false) !== true) {
                throw new IntegrityException('viewer.key_fixture', 'The contract fixture rid key must never be deployed.');
            }

            $state = $this->currentState();
            if ($state['verdict'] === ['ok', 'ok'] && $this->unchanged($state, $set, $key)) {
                return $this->finish([
                    'outcome' => 'unchanged',
                    'generation' => $state['current'],
                    'previous_generation' => $state['previous'],
                    'asset_count' => count($set->masters),
                    'recovered' => $recovered,
                ], $started);
            }

            $generation = $this->option($options, 'generation', Contract::GEN_RE) ?? Generations::newGenerationId();
            $createdUtc = $this->option($options, 'created_utc', Contract::UTC_RE) ?? Manifest::nowUtc();
            foreach ([$this->layout->generationDir($generation), $this->layout->stagingDir($generation), $this->layout->manifestPath($generation), $this->layout->sourceMapPath($generation)] as $path) {
                if (file_exists($path) || is_link($path)) {
                    $generation = null;
                    throw new PublishException('viewer.generation_exists', 'That generation id is already in use.');
                }
            }

            $staging = $this->layout->stagingDir($generation);
            Fs::ensureDir($staging, 0750);
            // Written before any rendition lands; promotion refuses a marked directory.
            Fs::writeFileAtomic($staging . '/' . ViewerLayout::INCOMPLETE_MARKER, Json::pretty(['generation' => $generation, 'pid' => getmypid()]), 0640);

            $currentRids = $state['manifest'] !== null && is_array($state['manifest']['renditions'] ?? null) ? $state['manifest']['renditions'] : [];
            $renditions = [];
            $entries = [];
            foreach ($set->masters as $relative => $master) {
                $rid = Contract::deriveRid($key, $generation, $relative, $master['sha256']);
                if (isset($renditions[$rid]) || isset($currentRids[$rid])) {
                    throw new IntegrityException('viewer.rid_collision', 'Two renditions would share an id.', ['rid' => $rid]);
                }
                [$size, $sha] = $this->encodeOne($staging, $rid, $relative, $master, $set);
                $entry = ['source_rel' => $relative, 'source_size' => $master['size'], 'source_sha256' => $master['sha256'],
                          'rendition_size' => $size, 'rendition_sha256' => $sha];
                if (Contract::ridDerivable($rid, $entry)) {
                    throw new IntegrityException('viewer.rid_derivable', 'A rendition id is derivable from its source.', ['rid' => $rid]);
                }
                $renditions[$rid] = ['size' => $size, 'sha256' => $sha];
                $entries[$rid] = $entry;
            }

            $manifest = Contract::buildManifest($generation, $createdUtc, $renditions);

            // Staging must hold exactly the manifest: no missing, extra,
            // symlinked, resized or altered file. The marker is the one
            // expected extra and is removed only after this passes.
            $observed = Contract::observe($staging, false);
            if ($observed === null || ($observed['entries'][ViewerLayout::INCOMPLETE_MARKER]['type'] ?? '') !== 'file') {
                throw new IntegrityException('viewer.staging_unverified', 'Staging lost its incomplete marker.', ['generation' => $generation]);
            }
            unset($observed['entries'][ViewerLayout::INCOMPLETE_MARKER]);
            $reason = Contract::checkObservation($manifest, $observed);
            if ($reason !== 'ok') {
                throw new IntegrityException('viewer.staging_unverified', 'Staging does not match its manifest.', ['generation' => $generation, 'reason' => $reason]);
            }
            if (!$this->masters->stillCurrent($set)) {
                throw new IntegrityException('viewer.supply_drift', 'The media-supply generation changed during encoding.', ['generation' => $generation]);
            }

            // Commit order: generation -> manifest -> source map -> health.json.
            if (!@unlink($staging . '/' . ViewerLayout::INCOMPLETE_MARKER)) {
                throw new PublishException('viewer.promote_failed', 'Staging marker could not be cleared.', ['generation' => $generation]);
            }
            if (!@rename($staging, $this->layout->generationDir($generation))) {
                throw new PublishException('viewer.promote_failed', 'Staging could not be promoted.', ['generation' => $generation]);
            }
            // Staging is private while it fills; the promoted generation is read-only for lof-core.
            @chmod($this->layout->generationDir($generation), 0755);
            Fs::fsyncDir($this->layout->generationsRoot());
            Fs::writeFileAtomic($this->layout->manifestPath($generation), Json::pretty($manifest), 0644);
            $sourceMap = Contract::buildSourceMap($manifest, $createdUtc, $this->config->ridKeyId, $set->supplyGeneration, $set->supplyManifestSha256, $entries);
            Fs::writeFileAtomic($this->layout->sourceMapPath($generation), Json::pretty($sourceMap), 0640);

            $previous = $state['current'];
            $generatedUtc = $this->option($options, 'generated_utc', Contract::UTC_RE) ?? Manifest::nowUtc();
            $health = Contract::buildHealth($generatedUtc, 'ok', $manifest, $previous !== $generation ? $previous : null);

            // Re-read everything from disk and run lof-core's own pipeline
            // before the commit point. What is committed is what was proved.
            $verdict = $this->verdict($health);
            if ($verdict !== ['ok', 'ok']) {
                throw new IntegrityException('viewer.self_verify_failed', 'The written publication does not verify.', ['generation' => $generation, 'reason' => $verdict[1]]);
            }
            $healthBytes = Json::pretty($health);
            $this->assertVisitorSafe([$healthBytes, (string) file_get_contents($this->layout->manifestPath($generation))], $set);

            Fs::writeFileAtomic($this->layout->healthPath(), $healthBytes, 0644);
            $committed = true;

            $this->layout->writeLedger(array_merge($this->layout->ledger(), array_filter([$generation, $previous])));
            $pruned = $this->prune();

            return $this->finish([
                'outcome' => 'published',
                'generation' => $generation,
                'previous_generation' => $health['previous_generation'],
                'asset_count' => $manifest['asset_count'],
                'total_bytes' => $manifest['total_bytes'],
                'manifest_sha256' => $manifest['manifest_sha256'],
                'recovered' => $recovered,
                'pruned' => $pruned,
            ], $started);
        } catch (\Throwable $e) {
            $safe = self::sanitize($e);
            if (!$committed && $generation !== null) {
                try {
                    $this->quarantineGeneration($generation, 'failed-run');
                } catch (\Throwable $ignored) {
                }
            }
            try {
                $this->failHealthIfBroken();
            } catch (\Throwable $ignored) {
            }
            $context = $safe->context();
            $this->recordLastRun([
                'outcome' => 'failed',
                'generation' => $committed ? $generation : null,
                'error_code' => $safe->code(),
                'reason' => is_string($context['reason'] ?? null) ? $context['reason'] : null,
                'current_disturbed' => false,
                'recovered' => $recovered,
                'duration_seconds' => round(microtime(true) - $started, 3),
            ]);

            throw $safe;
        } finally {
            $lock->release();
        }
    }

    /**
     * Encode, neutralise, inspect and hash one master into staging.
     *
     * @param array{path:string,size:int,sha256:string} $master
     * @return array{0:int,1:string}
     */
    private function encodeOne(string $staging, string $rid, string $relative, array $master, MasterSet $set): array
    {
        $part = $staging . '/' . $rid . Contract::RENDITION_EXT . '.part';
        $final = $staging . '/' . $rid . Contract::RENDITION_EXT;
        $this->encoder->encode($master['path'], $part);

        clearstatcache(true, $part);
        if (is_link($part) || !is_file($part) || (int) filesize($part) < 1) {
            throw new IntegrityException('viewer.partial_rendition', 'The encoder left no complete rendition.', ['rid' => $rid]);
        }
        $size = (int) filesize($part);
        if ($size > Contract::MAX_RENDITION_BYTES) {
            throw new IntegrityException('viewer.rendition_too_large', 'A rendition exceeds the contract size limit.', ['rid' => $rid]);
        }
        foreach ($this->inspector->inspect($part) as $problem) {
            if (strncmp($problem, 'metadata', 8) === 0 || $problem === 'unexpected_box' || $problem === 'external_reference') {
                throw new IntegrityException('viewer.metadata_leak', 'A rendition carries metadata.', ['rid' => $rid, 'reason' => $problem]);
            }
            throw new IntegrityException('viewer.profile_mismatch', 'A rendition is not the V1 profile.', ['rid' => $rid, 'reason' => $problem]);
        }
        if (self::fileContainsAny($part, self::sourceNeedles($relative, true))) {
            throw new IntegrityException('viewer.metadata_leak', 'A rendition carries its source name.', ['rid' => $rid, 'reason' => 'source_name']);
        }
        $sha = Fs::sha256File($part);
        if (in_array($sha, $set->supplyDigests, true)) {
            throw new IntegrityException('viewer.not_transcoded', 'A rendition is byte-identical to a master.', ['rid' => $rid]);
        }
        if (!hash_equals($master['sha256'], Fs::sha256File($master['path']))) {
            throw new IntegrityException('viewer.source_drift', 'A master changed while it was being encoded.', ['rid' => $rid]);
        }
        if (!@rename($part, $final)) {
            throw new PublishException('viewer.stage_failed', 'A rendition could not be staged.', ['rid' => $rid]);
        }
        @chmod($final, 0644);

        return [$size, $sha];
    }

    /**
     * Roll back to health.json's previous_generation, after proving it with
     * the full consumer pipeline. The outgoing generation becomes previous,
     * so it stays retained and the rollback is itself reversible.
     *
     * @return array<string,mixed>
     */
    public function rollback(): array
    {
        $started = microtime(true);
        $this->config->assertSeparated();
        $this->layout->initialise();
        $lock = new Lock($this->supply->lockPath());
        $lock->acquire();
        try {
            $health = ViewerLayout::readDocument($this->layout->healthPath());
            $target = is_array($health) ? ($health['previous_generation'] ?? null) : null;
            if (!is_string($target) || !Generations::isValidGenerationId($target)) {
                throw new PublishException('viewer.rollback_no_previous', 'There is no previous viewer generation to roll back to.');
            }
            $outgoing = is_array($health['current'] ?? null) && is_string($health['current']['generation'] ?? null)
                && Generations::isValidGenerationId($health['current']['generation']) ? $health['current']['generation'] : null;
            $manifest = ViewerLayout::readDocument($this->layout->manifestPath($target));
            $reason = $manifest === null ? 'manifest_missing' : Contract::validateManifest($manifest);
            if ($reason !== 'ok') {
                throw new IntegrityException('viewer.rollback_unverified', 'The previous generation does not verify.', ['generation' => $target, 'reason' => $reason]);
            }
            $candidate = Contract::buildHealth(Manifest::nowUtc(), 'ok', $manifest, $outgoing !== $target ? $outgoing : null);
            $verdict = $this->verdict($candidate);
            if ($verdict !== ['ok', 'ok']) {
                throw new IntegrityException('viewer.rollback_unverified', 'The previous generation does not verify.', ['generation' => $target, 'reason' => $verdict[1]]);
            }
            Fs::writeFileAtomic($this->layout->healthPath(), Json::pretty($candidate), 0644);
            $this->layout->writeLedger(array_merge($this->layout->ledger(), array_filter([$target, $outgoing])));

            return $this->finish(['outcome' => 'rolled_back', 'generation' => $target, 'previous_generation' => $candidate['previous_generation']], $started);
        } catch (\Throwable $e) {
            $safe = self::sanitize($e);
            $context = $safe->context();
            $this->recordLastRun(['outcome' => 'rollback_failed', 'error_code' => $safe->code(),
                'reason' => is_string($context['reason'] ?? null) ? $context['reason'] : null, 'current_disturbed' => false]);

            throw $safe;
        } finally {
            $lock->release();
        }
    }

    /**
     * Quarantine interrupted and unreferenced viewer state on demand.
     *
     * @return array<string,mixed>
     */
    public function recover(): array
    {
        $started = microtime(true);
        $this->config->assertSeparated();
        $this->layout->initialise();
        $lock = new Lock($this->supply->lockPath());
        $lock->acquire();
        try {
            return $this->finish(['outcome' => 'recovered', 'recovered' => $this->recoverLocked()], $started);
        } catch (\Throwable $e) {
            throw self::sanitize($e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Read-only: prove what health.json names, exactly as lof-core would.
     *
     * @return array<string,mixed>
     */
    public function verify(): array
    {
        $this->config->assertSeparated();
        $state = $this->currentState();

        return [
            'ok' => $state['verdict'] === ['ok', 'ok'],
            'stage' => $state['verdict'][0],
            'reason' => $state['verdict'][1],
            'generation' => $state['current'],
            'previous_generation' => $state['previous'],
            'state' => $state['health']['state'] ?? null,
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Run under the lock: anything health.json has never named is an
     * interrupted or failed publication and is quarantined, never deleted.
     * health.json's current and previous generations are never touched.
     *
     * @return list<array{kind:string,name:string}>
     */
    private function recoverLocked(): array
    {
        $health = ViewerLayout::readDocument($this->layout->healthPath());
        $named = [];
        if (is_array($health)) {
            foreach ([$health['current']['generation'] ?? null, $health['previous_generation'] ?? null] as $g) {
                if (is_string($g) && Generations::isValidGenerationId($g)) {
                    $named[] = $g;
                }
            }
        }
        $ledger = $this->layout->ledger();
        if (array_diff($named, $ledger) !== []) {
            // health.json was committed but the ledger append was interrupted.
            $ledger = array_values(array_unique(array_merge($ledger, $named)));
            $this->layout->writeLedger($ledger);
        }
        $keep = array_fill_keys($ledger, true);

        $viewer = [];
        $private = [];
        foreach (ViewerLayout::entries($this->layout->stagingRoot()) as $name) {
            $viewer[] = ['staging', $this->layout->stagingRoot() . '/' . $name];
        }
        foreach (ViewerLayout::entries($this->layout->generationsRoot()) as $name) {
            if (!isset($keep[$name])) {
                $viewer[] = ['generations', $this->layout->generationsRoot() . '/' . $name];
            }
        }
        foreach (ViewerLayout::entries($this->layout->manifestsRoot()) as $name) {
            if (!isset($keep[substr($name, 0, -5)]) || substr($name, -5) !== '.json') {
                $viewer[] = ['manifests', $this->layout->manifestsRoot() . '/' . $name];
            }
        }
        foreach (ViewerLayout::entries($this->layout->viewerRoot) as $name) {
            if (!in_array($name, ['health.json', 'generations', 'manifests', 'staging', 'quarantine'], true)) {
                $viewer[] = ['stray', $this->layout->viewerRoot . '/' . $name];
            }
        }
        foreach (ViewerLayout::entries($this->layout->sourceMapsRoot()) as $name) {
            if (!isset($keep[substr($name, 0, -5)]) || substr($name, -5) !== '.json') {
                $private[] = ['source-maps', $this->layout->sourceMapsRoot() . '/' . $name];
            }
        }

        return $this->layout->quarantine($viewer, $private, 'interrupted-or-unreferenced');
    }

    /** Move an uncommitted generation's every trace into quarantine. */
    private function quarantineGeneration(string $generation, string $reason): void
    {
        $viewer = [];
        foreach ([['staging', $this->layout->stagingDir($generation)], ['generations', $this->layout->generationDir($generation)], ['manifests', $this->layout->manifestPath($generation)]] as $item) {
            if (file_exists($item[1]) || is_link($item[1])) {
                $viewer[] = $item;
            }
        }
        $private = [];
        if (file_exists($this->layout->sourceMapPath($generation))) {
            $private[] = ['source-maps', $this->layout->sourceMapPath($generation)];
        }
        $this->layout->quarantine($viewer, $private, $reason);
    }

    /**
     * @return array{health:?array,current:?string,previous:?string,manifest:?array,source_map:?array,verdict:array{0:string,1:string}}
     */
    private function currentState(): array
    {
        $health = ViewerLayout::readDocument($this->layout->healthPath());
        $current = is_array($health) && is_array($health['current'] ?? null) && is_string($health['current']['generation'] ?? null)
            && Generations::isValidGenerationId($health['current']['generation']) ? $health['current']['generation'] : null;
        $previous = is_array($health) && is_string($health['previous_generation'] ?? null)
            && Generations::isValidGenerationId($health['previous_generation']) ? $health['previous_generation'] : null;

        return [
            'health' => $health,
            'current' => $current,
            'previous' => $previous,
            'manifest' => $current === null ? null : ViewerLayout::readDocument($this->layout->manifestPath($current)),
            'source_map' => $current === null ? null : ViewerLayout::readDocument($this->layout->sourceMapPath($current)),
            'verdict' => $this->verdict($health),
        ];
    }

    /**
     * The contract pipeline over what is on disk for the generation $health names.
     *
     * @param array<string,mixed>|null $health
     * @return array{0:string,1:string}
     */
    private function verdict(?array $health): array
    {
        $generation = is_array($health) && is_array($health['current'] ?? null) && is_string($health['current']['generation'] ?? null)
            && Generations::isValidGenerationId($health['current']['generation']) ? $health['current']['generation'] : null;
        $manifest = $generation === null ? null : ViewerLayout::readDocument($this->layout->manifestPath($generation));
        $sourceMap = $generation === null ? null : ViewerLayout::readDocument($this->layout->sourceMapPath($generation));
        $observed = $generation === null ? null : Contract::observe($this->layout->generationDir($generation), $this->config->rootsPublic());

        return Contract::pipeline($health, $manifest, $sourceMap, $observed);
    }

    /**
     * Idempotency: the verified current generation already renders exactly
     * these masters under this key, so it earns no new generation.
     *
     * @param array<string,mixed> $state
     */
    private function unchanged(array $state, MasterSet $set, string $key): bool
    {
        $map = $state['source_map'];
        if (!is_array($map) || ($map['rid_key_id'] ?? null) !== $this->config->ridKeyId || count($map['entries']) !== count($set->masters)) {
            return false;
        }
        foreach ($map['entries'] as $rid => $entry) {
            $master = $set->masters[$entry['source_rel']] ?? null;
            if ($master === null || $master['sha256'] !== $entry['source_sha256']
                || Contract::deriveRid($key, (string) $state['current'], $entry['source_rel'], $entry['source_sha256']) !== (string) $rid) {
                return false;
            }
        }

        return true;
    }

    /**
     * If health.json says ok but what it names no longer verifies, say so:
     * state becomes failed, which lof-core refuses. Nothing else changes.
     */
    private function failHealthIfBroken(): void
    {
        $state = $this->currentState();
        $health = $state['health'];
        if (!is_array($health) || ($health['state'] ?? null) !== 'ok' || $state['verdict'] === ['ok', 'ok']
            || Contract::validateHealth($health) !== 'ok') {
            return;
        }
        $health['state'] = 'failed';
        $health['generated_utc'] = Manifest::nowUtc();
        Fs::writeFileAtomic($this->layout->healthPath(), Json::pretty($health), 0644);
    }

    /**
     * Retain health.json's current and previous generations plus the newest
     * `retain_generations` others; remove older ones with their documents.
     *
     * @return list<string>
     */
    private function prune(): array
    {
        $health = ViewerLayout::readDocument($this->layout->healthPath());
        $pinned = array_filter([$health['current']['generation'] ?? null, $health['previous_generation'] ?? null], 'is_string');
        $ledger = $this->layout->ledger();
        $others = array_values(array_diff($ledger, $pinned));
        rsort($others, SORT_STRING);
        $drop = array_slice($others, $this->config->retainGenerations);
        foreach ($drop as $generation) {
            Fs::removeTree($this->layout->generationDir($generation), $this->layout->viewerRoot);
            @unlink($this->layout->manifestPath($generation));
            @unlink($this->layout->sourceMapPath($generation));
        }
        if ($drop !== []) {
            $this->layout->writeLedger(array_values(array_diff($ledger, $drop)));
        }

        return $drop;
    }

    /**
     * Defence in depth over the two visitor-readable documents: no source
     * path, basename, stem or digest, and no configured root, in any byte.
     *
     * @param list<string> $documents
     */
    private function assertVisitorSafe(array $documents, MasterSet $set): void
    {
        $needles = array_merge($set->supplyDigests, [$this->config->viewerRoot, $this->config->privateRoot]);
        foreach (array_keys($set->masters) as $relative) {
            $needles = array_merge($needles, self::sourceNeedles((string) $relative, false));
        }
        foreach ($documents as $bytes) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($bytes, $needle)) {
                    throw new IntegrityException('viewer.manifest_leak', 'A visitor document would carry a source fact.');
                }
            }
        }
    }

    /**
     * Source path and basename; for binary scans also the stem when it is
     * long enough (8+ bytes) not to occur in coded audio by chance. Stems are
     * not searched in the JSON documents, whose fixed vocabulary would
     * collide with ordinary words; the forbidden-key rule covers those.
     *
     * @return list<string>
     */
    private static function sourceNeedles(string $relative, bool $withStem): array
    {
        $base = basename($relative);
        $stem = (string) pathinfo($base, PATHINFO_FILENAME);
        $out = [$relative, $base];
        if ($withStem && strlen($stem) >= 8) {
            $out[] = $stem;
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $needles */
    private static function fileContainsAny(string $path, array $needles): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return true;
        }
        $overlap = max(array_map('strlen', $needles)) - 1;
        $tail = '';
        try {
            while (!feof($h)) {
                $chunk = fread($h, 1048576);
                if ($chunk === false) {
                    return true;
                }
                $window = $tail . $chunk;
                foreach ($needles as $needle) {
                    if (str_contains($window, $needle)) {
                        return true;
                    }
                }
                $tail = $overlap > 0 ? substr($window, -$overlap) : '';
            }
        } finally {
            fclose($h);
        }

        return false;
    }

    /** @param array<string,mixed> $options */
    private function option(array $options, string $name, string $pattern): ?string
    {
        if (!array_key_exists($name, $options)) {
            return null;
        }
        if (!is_string($options[$name]) || preg_match($pattern, $options[$name]) !== 1) {
            throw new PublishException('viewer.bad_option', 'A publish option has an unexpected shape.', ['option' => $name]);
        }

        return $options[$name];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function finish(array $result, float $started): array
    {
        $result['duration_seconds'] = round(microtime(true) - $started, 3);
        $this->recordLastRun($result);

        return $result;
    }

    /**
     * The lane's outcome, for the media-supply health record (contract V1
     * section 5.6: a failed run is reported there, not in health.json).
     *
     * @param array<string,mixed> $record
     */
    private function recordLastRun(array $record): void
    {
        try {
            $record = ['viewer_last_run_version' => 1, 'finished_utc' => Manifest::nowUtc()] + $record;
            Fs::writeFileAtomic($this->supply->root() . '/' . self::LAST_RUN_FILE, Json::pretty($record), 0644);
            if ($this->settings !== null && is_file($this->supply->healthPath())) {
                Health::write($this->supply, Health::current($this->supply, $this->settings));
            }
        } catch (\Throwable $e) {
            // Reporting must never mask the real outcome.
        }
    }

    /**
     * Only `viewer.*` exceptions are built with source-free context. Anything
     * else (a filesystem or JSON error naming a file) is re-raised as a
     * generic lane failure carrying the inner code alone.
     */
    private static function sanitize(\Throwable $e): LofAudioException
    {
        if ($e instanceof LockException || ($e instanceof LofAudioException && strncmp($e->code(), 'viewer.', 7) === 0)) {
            return $e;
        }
        $cause = $e instanceof LofAudioException ? $e->code() : 'internal';
        if ($e instanceof IntegrityException) {
            return new IntegrityException('viewer.failed', 'Viewer rendition publication failed.', ['cause' => $cause]);
        }

        return new PublishException('viewer.failed', 'Viewer rendition publication failed.', ['cause' => $cause]);
    }
}
