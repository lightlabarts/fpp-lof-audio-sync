<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Config\Settings;
use LofAudioSupply\IntegrityException;
use LofAudioSupply\LockException;
use LofAudioSupply\LofAudioException;
use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Lock;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\PublishException;
use LofAudioSupply\Status\Health;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Transport\Transport;

/**
 * Delivers the verified current viewer publication to the lof-core side
 * (contract V1 sections 2 and 5), in the contract's commit order:
 *
 *   1. renditions   <viewer_destination>/generations/<gen>/<rid>.m4a
 *   2. manifest     <viewer_destination>/manifests/<gen>.json
 *   3. source map   <private_destination>/source-maps/<gen>.json
 *   4. read back the destination and run the consumer pipeline against it
 *      with the pointer that is about to be written
 *   5. health.json  <viewer_destination>/health.json   - last
 *
 * Until step 5 the destination's old health.json is authoritative, and the
 * generation it names is never overwritten or removed (a new generation is a
 * new directory; nothing is ever deleted at the destination). A failure at any
 * step leaves the destination serving what it served before.
 *
 * The file list is derived from the verified local manifest - never a glob or
 * directory listing - so only renditions, the manifest and the health pointer
 * reach the viewer destination, only the source map reaches the private
 * destination, and no master, source path, source digest or rid key can be
 * shipped. The media-supply `distribute` leg is untouched.
 *
 * Health-last is only real if step 4 can read the destination. Without a
 * DestinationVerifier (today: any remote mode) the run is refused before a
 * single byte moves.
 */
final class ViewerDistributor
{
    public const LAST_RUN_FILE = 'viewer-distribute-last-run.json';

    private ViewerLayout $local;

    public function __construct(
        private DistributionConfig $config,
        private ViewerConfig $viewer,
        private Generations $supply,
        private ?Settings $settings,
        private Transport $transport,
        private ?DestinationVerifier $verifier
    ) {
        $this->local = new ViewerLayout($viewer->viewerRoot, $viewer->privateRoot);
    }

    /** @return array<string,mixed> */
    public function distribute(): array
    {
        $started = microtime(true);
        $stages = [];
        try {
            $this->config->assertDestinations();
            $this->viewer->assertSeparated();
            if ($this->verifier === null) {
                throw new PolicyViolationException(
                    'viewer_distribute.remote_unverifiable',
                    'This destination cannot be read back, so health-last delivery cannot be proved; remote mode stays disarmed.'
                );
            }
        } catch (LofAudioException $e) {
            $this->recordLastRun(['outcome' => 'refused', 'error_code' => $e->code(), 'destination_disturbed' => false]);

            throw $e;
        }

        $lock = new Lock($this->supply->lockPath());
        $lock->acquire();
        try {
            // Only a fully verified local current is ever shipped.
            $healthBytes = $this->readLocal($this->local->healthPath());
            $health = $healthBytes === null ? null : Json::decode($healthBytes, 'health');
            $verdict = $this->localVerdict($health);
            if ($verdict !== ['ok', 'ok']) {
                throw new IntegrityException('viewer_distribute.source_unverified', 'The local viewer publication does not verify.', ['reason' => $verdict[1]]);
            }
            $generation = $health['current']['generation'];
            $manifestBytes = (string) $this->readLocal($this->local->manifestPath($generation));
            $mapBytes = (string) $this->readLocal($this->local->sourceMapPath($generation));
            $manifest = Json::decode($manifestBytes, 'manifest');

            $this->verifier->assertNoSymlinkedDirectories($generation);
            if ($this->verifier->healthBytes() === $healthBytes && $this->verifier->verdict($health) === ['ok', 'ok']) {
                return $this->finish(['outcome' => 'unchanged', 'generation' => $generation], $started);
            }

            $rids = array_map('strval', array_keys($manifest['renditions']));
            sort($rids, SORT_STRING);
            $renditions = array_map(static fn (string $rid): string => 'generations/' . $generation . '/' . $rid . Contract::RENDITION_EXT, $rids);

            $this->send('renditions', $this->viewer->viewerRoot, $this->config->viewerDestination, $renditions, $stages);
            $this->send('manifest', $this->viewer->viewerRoot, $this->config->viewerDestination, ['manifests/' . $generation . '.json'], $stages);
            $this->send('source_map', $this->viewer->privateRoot, $this->config->privateDestination, ['source-maps/' . $generation . '.json'], $stages);

            // Read back and prove, with the pointer about to be written.
            $delivered = $this->verifier->verdict($health);
            if ($delivered !== ['ok', 'ok']
                || $this->verifier->fileBytes('viewer', 'manifests/' . $generation . '.json') !== $manifestBytes
                || $this->verifier->fileBytes('private', 'source-maps/' . $generation . '.json') !== $mapBytes) {
                throw new IntegrityException('viewer_distribute.destination_unverified', 'The delivered publication does not verify; the destination pointer was not moved.', [
                    'generation' => $generation, 'reason' => $delivered[1] === 'ok' ? 'document_bytes' : $delivered[1],
                ]);
            }
            $stages[] = 'verified';

            // The commit point, and only then.
            $this->send('health', $this->viewer->viewerRoot, $this->config->viewerDestination, ['health.json'], $stages);
            if ($this->verifier->healthBytes() !== $healthBytes || $this->verifier->verdict($health) !== ['ok', 'ok']) {
                throw new IntegrityException('viewer_distribute.post_commit_unverified', 'The destination changed underneath the commit.', ['generation' => $generation]);
            }

            return $this->finish([
                'outcome' => 'distributed',
                'generation' => $generation,
                'previous_generation' => $health['previous_generation'],
                'asset_count' => count($rids),
                'stages' => $stages,
            ], $started);
        } catch (\Throwable $e) {
            $safe = self::sanitize($e);
            $context = $safe->context();
            $this->recordLastRun([
                'outcome' => 'failed',
                'error_code' => $safe->code(),
                'reason' => is_string($context['reason'] ?? null) ? $context['reason'] : null,
                'completed_stages' => $stages,
                'destination_pointer_moved' => in_array('health', $stages, true),
                'duration_seconds' => round(microtime(true) - $started, 3),
            ]);

            throw $safe;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $paths
     * @param list<string> $stages
     */
    private function send(string $stage, string $from, string $to, array $paths, array &$stages): void
    {
        $report = $this->transport->transfer($from, $to, $paths);
        if (!$report->ok() || $report->filesTransferred !== count($paths)) {
            throw new PublishException('viewer_distribute.transfer_incomplete', 'A delivery stage did not complete.', ['reason' => $stage]);
        }
        $stages[] = $stage;
    }

    /** @param array<string,mixed>|null $health */
    private function localVerdict(?array $health): array
    {
        return (new LocalDestinationVerifier($this->viewer->viewerRoot, $this->viewer->privateRoot, $this->viewer->rootsPublic()))->verdict($health);
    }

    private function readLocal(string $path): ?string
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
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

    /** @param array<string,mixed> $record */
    private function recordLastRun(array $record): void
    {
        try {
            $record = ['viewer_distribute_last_run_version' => 1, 'finished_utc' => Manifest::nowUtc(), 'mode' => $this->config->mode] + $record;
            Fs::writeFileAtomic($this->supply->root() . '/' . self::LAST_RUN_FILE, Json::pretty($record), 0644);
            if ($this->settings !== null && is_file($this->supply->healthPath())) {
                Health::write($this->supply, Health::current($this->supply, $this->settings));
            }
        } catch (\Throwable $e) {
            // Reporting must never mask the real outcome.
        }
    }

    /** Only `viewer_distribute.*` and lock errors carry source-free context by construction. */
    private static function sanitize(\Throwable $e): LofAudioException
    {
        if ($e instanceof LockException || ($e instanceof LofAudioException && strncmp($e->code(), 'viewer_distribute.', 18) === 0)) {
            return $e;
        }
        $cause = $e instanceof LofAudioException ? $e->code() : 'internal';

        return new PublishException('viewer_distribute.failed', 'Viewer distribution failed; the destination pointer was not moved.', ['cause' => $cause]);
    }
}
