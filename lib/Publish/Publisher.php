<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\IntegrityException;
use LofAudioSupply\LockException;
use LofAudioSupply\LofAudioException;
use LofAudioSupply\PublishException;
use LofAudioSupply\Status\Health;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\Transport\Transport;

/**
 * Staged publication, start to finish.
 *
 * The invariant every step is written around: **the active generation is never
 * disturbed by a failure.** Nothing touches `current` until a complete,
 * hash-verified, immutable generation exists on disk beside it, and the switch
 * itself is a single rename. Interrupted transfer, hash mismatch, missing
 * source, full disk, permission failure, lost SSH, a killed process, or a
 * second concurrent run all land in the same place: staging is abandoned,
 * `current` still points at the last good generation, and the health record
 * says why.
 */
final class Publisher
{
    /** Refuse to stage unless this much headroom remains after the copy. */
    public const FREE_SPACE_SLACK_BYTES = 67108864; // 64 MiB

    private Settings $settings;
    private Policy $policy;
    private Generations $generations;
    private Transport $transport;

    public function __construct(Settings $settings, Policy $policy, Transport $transport, ?Generations $generations = null)
    {
        $this->settings = $settings;
        $this->policy = $policy;
        $this->transport = $transport;
        $this->generations = $generations ?? new Generations($settings->publicationRoot);
    }

    public function generations(): Generations
    {
        return $this->generations;
    }

    /**
     * Select the assets this component is willing to supply.
     *
     * @param list<string> $skipped
     * @return list<string>
     */
    public function selectAssets(string $sourceRoot, array &$skipped = []): array
    {
        $candidates = Fs::walkFiles($sourceRoot, $skipped);
        $selected = [];
        foreach ($candidates as $relative) {
            $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
            if ($extension === '' || !in_array($extension, $this->policy->assetExtensions, true)) {
                $skipped[] = $relative . ' (extension not approved)';
                continue;
            }
            $selected[] = $relative;
        }

        return $selected;
    }

    /**
     * Run one publish cycle.
     *
     * @throws LofAudioException with the active generation left intact
     */
    public function publish(): PublishResult
    {
        $started = microtime(true);
        $this->generations->initialise();

        $lock = new Lock($this->generations->lockPath());
        $lock->acquire();

        $generation = null;
        try {
            // Under the lock, any staging directory this old belongs to a run
            // that is long gone. Sweeping it here keeps a box that has been
            // failing for a while from filling its disk with abandoned copies.
            $reclaimed = $this->generations->reclaimStaging(3600);

            $sourceRoot = $this->settings->sourcePath;
            if (!is_dir($sourceRoot)) {
                throw new PublishException('publish.source_missing', 'Source directory does not exist.');
            }
            if (!is_readable($sourceRoot)) {
                throw new PublishException('publish.source_unreadable', 'Source directory is not readable.');
            }

            $skipped = [];
            $assets = $this->selectAssets($sourceRoot, $skipped);

            // Hash the source first. Verifying the staged copy against a
            // source-derived manifest is what proves the transfer was faithful;
            // hashing the copy against itself would prove nothing.
            $generation = Generations::newGenerationId();
            $manifest = Manifest::build($sourceRoot, $assets, $generation, $sourceRoot);

            $currentGeneration = $this->generations->currentGeneration();
            $currentManifest = $this->readManifestIfPresent($currentGeneration);

            // Idempotency: an unchanged asset set does not earn a new
            // generation, a new copy, or a symlink swap.
            if ($currentManifest !== null && $currentManifest->equals($manifest)) {
                $result = new PublishResult(
                    PublishResult::OUTCOME_UNCHANGED,
                    $currentGeneration,
                    $this->generations->previousGeneration(),
                    $currentManifest->assetCount(),
                    $currentManifest->totalBytes(),
                    [],
                    [],
                    round(microtime(true) - $started, 3),
                    ['skipped' => array_slice($skipped, 0, 20), 'transport' => $this->transport->name()]
                );
                $this->writeHealth(Health::STATE_OK, $result->toArray());

                return $result;
            }

            $this->assertFreeSpace($manifest->totalBytes());

            $staging = $this->generations->stagingDir($generation);
            Fs::ensureDir($staging);
            // Written before any asset lands, removed only after verification.
            // A process that dies anywhere in between leaves this marker, and
            // promotion refuses a marked directory.
            $this->generations->markIncomplete($generation);

            $transfer = $this->transport->transfer($sourceRoot, $staging, $manifest->paths());
            if (!$transfer->ok()) {
                throw new PublishException(
                    'publish.transfer_incomplete',
                    'Transfer reported per-asset failures.',
                    ['failure_count' => count($transfer->failures)]
                );
            }

            $problems = $manifest->verifyAgainst($staging);
            // The incomplete marker is expected to still be present here.
            $problems = array_values(array_filter(
                $problems,
                static fn (array $p): bool => $p['asset'] !== Generations::INCOMPLETE_MARKER
            ));
            if ($problems !== []) {
                throw new IntegrityException(
                    'publish.staging_unverified',
                    'Staged generation does not match its manifest.',
                    ['problem_count' => count($problems), 'first' => $problems[0]['problem']]
                );
            }

            $this->generations->clearIncomplete($generation);
            $generationDir = $this->generations->promoteStaging($generation);
            $manifest->writeTo($this->generations->manifestPath($generation));

            // Re-prove the promoted, immutable copy before anything switches.
            $postPromotion = $manifest->verifyAgainst($generationDir);
            if ($postPromotion !== []) {
                throw new IntegrityException(
                    'publish.promoted_unverified',
                    'Promoted generation failed its pre-activation check.',
                    ['problem_count' => count($postPromotion)]
                );
            }

            // Assets that vanished upstream are preserved, not deleted.
            $quarantined = [];
            if ($currentManifest !== null && $currentGeneration !== null) {
                $removed = $manifest->removedSince($currentManifest);
                if ($removed !== []) {
                    $this->generations->quarantine(
                        $removed,
                        $this->generations->generationDir($currentGeneration),
                        'absent-upstream'
                    );
                    $quarantined = $removed;
                }
            }

            $previous = $this->generations->activate($generation);

            $this->generations->recordActivation([
                'generation' => $generation,
                'activated_utc' => Manifest::nowUtc(),
                'asset_count' => $manifest->assetCount(),
                'total_bytes' => $manifest->totalBytes(),
                'manifest_sha256' => $manifest->digest(),
                'replaced' => $previous,
                'quarantined_count' => count($quarantined),
            ], $this->settings->retainGenerations + 2);

            $pruned = $this->generations->pruneGenerations($this->settings->retainGenerations);

            $result = new PublishResult(
                PublishResult::OUTCOME_PUBLISHED,
                $generation,
                $previous,
                $manifest->assetCount(),
                $manifest->totalBytes(),
                $quarantined,
                $pruned,
                round(microtime(true) - $started, 3),
                [
                    'transport' => $this->transport->name(),
                    'transfer' => $transfer->toArray(),
                    'skipped' => array_slice($skipped, 0, 20),
                    'reclaimed_staging' => $reclaimed,
                    'added' => $currentManifest === null ? [] : array_slice($manifest->addedSince($currentManifest), 0, 50),
                    'changed' => $currentManifest === null ? [] : array_slice($manifest->changedSince($currentManifest), 0, 50),
                ]
            );
            $this->writeHealth(Health::STATE_OK, $result->toArray());

            return $result;
        } catch (LofAudioException $e) {
            $this->writeHealth(Health::STATE_FAILED, [
                'outcome' => PublishResult::OUTCOME_FAILED,
                'generation' => $generation,
                'error_code' => $e->code(),
                'error_context' => $e->context(),
                'failed_utc' => Manifest::nowUtc(),
                'active_generation_disturbed' => false,
                'duration_seconds' => round(microtime(true) - $started, 3),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function readManifestIfPresent(?string $generation): ?Manifest
    {
        if ($generation === null) {
            return null;
        }
        $path = $this->generations->manifestPath($generation);
        if (!is_file($path)) {
            return null;
        }
        try {
            return Manifest::readFrom($path);
        } catch (\Throwable $e) {
            // A damaged manifest for the *current* generation must not stop a
            // fresh, provable generation from being published beside it.
            return null;
        }
    }

    /**
     * Refuse to stage when the copy would not leave usable headroom.
     *
     * Public so a test can prove the refusal without having to fill a disk.
     */
    public function assertFreeSpace(int $requiredBytes): void
    {
        $free = Fs::freeBytes($this->generations->root());
        if ($free === null) {
            return;
        }
        if ($free < (float) $requiredBytes + self::FREE_SPACE_SLACK_BYTES) {
            throw new PublishException(
                'publish.insufficient_space',
                'Not enough free space to stage this generation.',
                ['required_bytes' => $requiredBytes, 'free_bytes' => (int) $free]
            );
        }
    }

    /**
     * Verify what is currently active against its own manifest.
     *
     * @return list<array{asset:string,problem:string}>
     */
    public function verifyCurrent(): array
    {
        $current = $this->generations->currentGeneration();
        if ($current === null) {
            throw new PublishException('verify.no_current', 'No generation is currently active.');
        }
        $manifest = Manifest::readFrom($this->generations->manifestPath($current));

        return $manifest->verifyAgainst($this->generations->generationDir($current));
    }

    public function rollback(): string
    {
        $lock = new Lock($this->generations->lockPath());
        $lock->acquire();
        try {
            $target = $this->generations->rollback();
            $this->generations->recordActivation([
                'generation' => $target,
                'activated_utc' => Manifest::nowUtc(),
                'reason' => 'rollback',
            ], $this->settings->retainGenerations + 2);
            $this->writeHealth(Health::STATE_OK, [
                'outcome' => 'rolled_back',
                'generation' => $target,
                'rolled_back_utc' => Manifest::nowUtc(),
            ]);

            return $target;
        } finally {
            $lock->release();
        }
    }

    /**
     * Distribute the active generation to the approved remote root.
     *
     * Kept separate from publish() on purpose: local publication must succeed
     * or fail on its own terms, and a remote that is down is not a reason for
     * the FPP-side generation to be anything other than current.
     *
     * @return array<string,mixed>
     */
    public function distribute(Transport $remote): array
    {
        if (!$this->settings->distributionEnabled) {
            throw new PublishException('distribute.disarmed', 'Distribution is not enabled in settings.');
        }
        $current = $this->generations->currentGeneration();
        if ($current === null) {
            throw new PublishException('distribute.no_current', 'There is no active generation to distribute.');
        }
        $generationDir = $this->generations->generationDir($current);
        $manifest = Manifest::readFrom($this->generations->manifestPath($current));
        $problems = $manifest->verifyAgainst($generationDir);
        if ($problems !== []) {
            throw new IntegrityException(
                'distribute.unverified_source',
                'Refusing to distribute a generation that does not match its manifest.',
                ['problem_count' => count($problems)]
            );
        }

        $destination = SafePath::join($this->settings->destinationPath, $current);
        $report = $remote->transfer($generationDir, $destination, $manifest->paths());

        return [
            'generation' => $current,
            'destination' => $destination,
            'report' => $report->toArray(),
        ];
    }

    public const SUPPLY_DELIVERY_LAST_RUN_FILE = 'supply-deliver-last-run.json';

    /**
     * Deliver the verified current generation as a complete supply root:
     * masters, the byte-identical manifest, read-back, then the pointers.
     *
     * Operator-only and default-disarmed; the timer never calls it, and the
     * legacy masters-only distribute() is untouched. The destination is an
     * ordinary supply root, so it is read back with Manifest::verifyAgainst
     * and switched with Generations::activate - which writes `previous`
     * before it atomically swaps `current`. The pair is therefore not atomic,
     * and nothing here claims it is: on every failure both links are read
     * back and reported as observed. Nothing at the destination is deleted and
     * no pointer is restored automatically.
     *
     * $destination is null for any mode that cannot read back (remote), and
     * such a run is refused before any lock or transfer.
     *
     * @return array<string,mixed>
     */
    public function deliverSupply(SupplyDeliveryConfig $config, Transport $transport, ?SupplyDestination $destination): array
    {
        $started = microtime(true);
        try {
            $config->assertDestination();
            if ($destination === null) {
                throw new \LofAudioSupply\PolicyViolationException('supply_deliver.remote_unverifiable', 'This destination cannot be read back; delivery stays disarmed.');
            }
        } catch (LofAudioException $e) {
            $this->recordSupplyDelivery(['outcome' => 'refused', 'error_code' => $e->code(), 'destination_disturbed' => false]);

            throw $e;
        }

        // Fixed lock order: source, then destination.
        $sourceLock = new Lock($this->generations->lockPath());
        $sourceLock->acquire();
        $destinationLock = null;
        $stages = [];
        $generation = null;
        $before = null;
        try {
            $generation = $this->generations->currentGeneration();
            if ($generation === null) {
                throw new PublishException('supply_deliver.no_current', 'There is no current supply generation.');
            }
            $manifestPath = $this->generations->manifestPath($generation);
            $manifestBytes = is_file($manifestPath) && !is_link($manifestPath) ? (string) file_get_contents($manifestPath) : '';
            try {
                $manifest = Manifest::fromArray(Json::decode($manifestBytes, 'manifest'));
            } catch (\Throwable $e) {
                throw new IntegrityException('supply_deliver.source_unverified', 'The source manifest does not verify.', ['reason' => 'manifest']);
            }
            if ($manifest->generation !== $generation) {
                throw new IntegrityException('supply_deliver.source_unverified', 'The source manifest names another generation.', ['reason' => 'stale_manifest']);
            }
            $problems = $manifest->verifyAgainst($this->generations->generationDir($generation));
            if ($problems !== []) {
                throw new IntegrityException('supply_deliver.source_unverified', 'The source generation does not match its manifest.', ['reason' => 'files', 'problem_count' => count($problems)]);
            }

            $destinationLock = new Lock($destination->lockPath());
            $destination->prepare($generation);
            $destinationLock->acquire();
            $before = $destination->pointers();

            if ($destination->currentGeneration() === $generation && $destination->manifestBytes($generation) === $manifestBytes
                && $destination->verifyGeneration($manifest, $generation) === []) {
                return $this->finishSupplyDelivery(['outcome' => 'unchanged', 'generation' => $generation], $started);
            }

            // Masters, one manifest-listed file per transfer, containment re-proved before each.
            $sourceRoot = $this->generations->root();
            $destinationRoot = $config->destinationRoot;
            foreach ($manifest->paths() as $relative) {
                $rel = 'generations/' . $generation . '/' . $relative;
                $destination->assertRealContainment($rel);
                $this->sendSupply($transport, $sourceRoot, $destinationRoot, $rel);
            }
            $stages[] = 'masters';
            $rel = 'manifests/' . $generation . '.json';
            $destination->assertRealContainment($rel);
            $this->sendSupply($transport, $sourceRoot, $destinationRoot, $rel);
            $stages[] = 'manifest';

            // Read back: exact file set, sizes, digests; manifest bytes identical.
            $readBack = $destination->verifyGeneration($manifest, $generation);
            if ($readBack !== [] || $destination->manifestBytes($generation) !== $manifestBytes) {
                throw new IntegrityException('supply_deliver.destination_unverified', 'The delivered generation does not verify; no pointer was moved by this stage.', [
                    'reason' => $readBack === [] ? 'manifest_bytes' : 'files', 'problem_count' => count($readBack),
                ]);
            }
            $stages[] = 'verified';

            // Pointers last: previous, then the live current (not atomic as a pair).
            $destination->activate($generation);
            $stages[] = 'activated';
            if ($destination->currentGeneration() !== $generation || $destination->verifyGeneration($manifest, $generation) !== []
                || $destination->manifestBytes($generation) !== $manifestBytes) {
                throw new IntegrityException('supply_deliver.post_activate_unverified', 'The destination does not verify after activation.', ['reason' => 'post_activate']);
            }

            return $this->finishSupplyDelivery([
                'outcome' => 'delivered',
                'generation' => $generation,
                'asset_count' => $manifest->assetCount(),
                'total_bytes' => $manifest->totalBytes(),
                'manifest_sha256' => $manifest->digest(),
                'stages' => $stages,
            ], $started);
        } catch (\Throwable $e) {
            $safe = self::sanitizeSupplyDelivery($e);
            $after = $destination->pointers();
            $currentMoved = $before !== null && $after['current'] !== $before['current'];
            $previousMoved = $before !== null && $after['previous'] !== $before['previous'];
            if ($currentMoved) {
                $safe = new IntegrityException('supply_deliver.pointer_moved_unverified', 'The destination current pointer moved but delivery did not verify.', ['reason' => $safe->code()]);
            } elseif ($previousMoved) {
                $safe = new IntegrityException('supply_deliver.previous_moved_current_unchanged', 'The destination previous pointer moved; current still names the old generation.', ['reason' => $safe->code()]);
            }
            $context = $safe->context();
            $this->recordSupplyDelivery([
                'outcome' => 'failed',
                'error_code' => $safe->code(),
                'reason' => is_string($context['reason'] ?? null) ? $context['reason'] : null,
                'problem_count' => is_int($context['problem_count'] ?? null) ? $context['problem_count'] : null,
                'completed_stages' => $stages,
                'pointers_observed' => $before === null ? 'not_read' : 'read_back',
                'current_moved' => $currentMoved,
                'previous_moved' => $previousMoved,
                'current_before' => self::pointerId($before['current'] ?? null),
                'current_after' => self::pointerId($after['current']),
                'previous_before' => self::pointerId($before['previous'] ?? null),
                'previous_after' => self::pointerId($after['previous']),
                'duration_seconds' => round(microtime(true) - $started, 3),
            ]);

            throw $safe;
        } finally {
            if ($destinationLock !== null) {
                $destinationLock->release();
            }
            $sourceLock->release();
        }
    }

    private function sendSupply(Transport $transport, string $from, string $to, string $relative): void
    {
        $report = $transport->transfer($from, $to, [$relative]);
        if (!$report->ok() || $report->filesTransferred !== 1) {
            // Counts only: a transport's failure lines can name a master.
            throw new PublishException('supply_deliver.transfer_incomplete', 'A delivery transfer did not complete.', ['problem_count' => max(1, count($report->failures))]);
        }
    }

    /** A link target reduced to a generation id, never a path. */
    private static function pointerId(?string $target): ?string
    {
        if ($target === null) {
            return null;
        }
        if ($target === '!not-a-link') {
            return 'not-a-link';
        }
        $id = basename($target);

        return Generations::isValidGenerationId($id) ? $id : 'unrecognised';
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function finishSupplyDelivery(array $result, float $started): array
    {
        $result['duration_seconds'] = round(microtime(true) - $started, 3);
        $this->recordSupplyDelivery($result);

        return $result;
    }

    /** @param array<string,mixed> $record */
    private function recordSupplyDelivery(array $record): void
    {
        try {
            $record = ['supply_delivery_last_run_version' => 1, 'finished_utc' => Manifest::nowUtc()] + $record;
            Fs::writeFileAtomic($this->generations->root() . '/' . self::SUPPLY_DELIVERY_LAST_RUN_FILE, Json::pretty($record), 0644);
            if (is_file($this->generations->healthPath())) {
                Health::write($this->generations, Health::current($this->generations, $this->settings));
            }
        } catch (\Throwable $e) {
            // Reporting must never mask the real outcome.
        }
    }

    /** Only `supply_deliver.*` and lock errors carry source-free context by construction. */
    private static function sanitizeSupplyDelivery(\Throwable $e): LofAudioException
    {
        if ($e instanceof LockException || ($e instanceof LofAudioException && strncmp($e->code(), 'supply_deliver.', 15) === 0)) {
            return $e;
        }
        $cause = $e instanceof LofAudioException ? $e->code() : 'internal';

        return new PublishException('supply_deliver.failed', 'Supply delivery failed.', ['reason' => $cause]);
    }

    /** @param array<string,mixed> $lastRun */
    public function writeHealth(string $state, array $lastRun): void
    {
        try {
            Health::write($this->generations, Health::build($this->generations, $this->settings, $state, $lastRun));
        } catch (\Throwable $e) {
            // A health record that cannot be written must never mask the real
            // outcome of a publish.
        }
    }
}
