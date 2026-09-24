<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Support\Json;
use LofAudioSupply\TransportException;
use LofAudioSupply\Transport\LocalTransport;
use LofAudioSupply\Transport\TransferReport;
use LofAudioSupply\Transport\Transport;
use LofAudioSupply\Viewer\Contract;
use LofAudioSupply\Viewer\DistributionConfig;
use LofAudioSupply\Viewer\LocalDestinationVerifier;
use LofAudioSupply\Viewer\ViewerDistributor;
use LofTest\ContractFixture;
use LofTest\FixedMasterSource;
use LofTest\StubEncoder;
use LofTest\TestCase;
use LofTest\ViewerEstate;

/**
 * Viewer-publication distribution: renditions, manifest, private source map,
 * read-back verification, then health.json last - to two separately
 * configured destination roots, with the old destination pointer
 * authoritative through every failure.
 *
 * LocalTransport and a deterministic fault-injecting wrapper only. No SSH, no
 * network, no live destination.
 */
final class ViewerDistributeTest extends TestCase
{
    private const GEN = '20260923T020000Z-5e1f0a3c';
    private const GEN_PREV = '20260922T020000Z-0b7d3e21';

    private ViewerEstate $v;
    private string $viewerDest;
    private string $privateDest;

    public function setUp(): void
    {
        $this->v = new ViewerEstate($this->makeTempRoot('vdist'));
        $this->viewerDest = $this->v->estate->root . '/srv/lof-core/viewer';
        $this->privateDest = $this->v->estate->root . '/srv/lof-core/private';
    }

    /** @param array<string,mixed> $block */
    private function policy(array $block = []): Policy
    {
        $base = ViewerEstate::policyFor($this->v->estate, $this->v->viewerBlock())->toArray();
        $base['viewer_distribution'] = $block + ['enabled' => true, 'mode' => 'local', 'viewer_destination' => $this->viewerDest, 'private_destination' => $this->privateDest];

        return new Policy($base);
    }

    /** @param array<string,mixed> $block */
    private function distributor(?FaultTransport $transport = null, array $block = []): ViewerDistributor
    {
        $settings = $this->v->estate->settings();
        $viewer = \LofAudioSupply\Viewer\ViewerConfig::fromPolicy($this->policy($block), $settings);
        $config = DistributionConfig::fromPolicy($this->policy($block), $settings, $viewer);
        $verifier = $config->mode === 'local' ? new LocalDestinationVerifier($config->viewerDestination, $config->privateDestination) : null;

        return new ViewerDistributor($config, $viewer, $this->v->supply(), $settings, $transport ?? new FaultTransport(), $verifier);
    }

    private function publish(string $gen, int $count): void
    {
        [$files, $map] = ViewerEstate::fixtureAssets();
        $created = substr($gen, 0, 4) . '-' . substr($gen, 4, 2) . '-' . substr($gen, 6, 2) . 'T02:00:00Z';
        $this->v->publisher(new FixedMasterSource($this->v->masters(array_slice($files, 0, $count, true))), new StubEncoder($map))
            ->publish(['generation' => $gen, 'allow_fixture_key' => true, 'created_utc' => $created, 'generated_utc' => substr($created, 0, 17) . '07Z']);
    }

    private function destHealth(): ?string
    {
        return is_file($this->viewerDest . '/health.json') ? (string) file_get_contents($this->viewerDest . '/health.json') : null;
    }

    /** The contract's own normative reader over the destination roots. */
    private function referenceVerdict(): array
    {
        require_once ContractFixture::dir() . '/tools/model.php';
        $schemas = [];
        foreach (['viewer-rendition-manifest', 'publication-health-pointer', 'private-source-map'] as $n) {
            $schemas[$n] = json_decode((string) file_get_contents(ContractFixture::dir() . "/schemas/$n.v1.schema.json"), false, 64, JSON_THROW_ON_ERROR);
        }
        $h = Json::readFile($this->viewerDest . '/health.json');
        $g = $h['current']['generation'];

        return \LofPhoneAudioContractV1\Model::pipeline(
            $h,
            Json::readFile($this->viewerDest . '/manifests/' . $g . '.json'),
            Json::readFile($this->privateDest . '/source-maps/' . $g . '.json'),
            Contract::observe($this->viewerDest . '/generations/' . $g, false),
            $schemas
        );
    }

    // ---- success ------------------------------------------------------------

    public function testDeliversInContractOrderToSeparateRootsAndLofCoreReadsIt(): void
    {
        $this->publish(self::GEN_PREV, 1);
        $this->publish(self::GEN, 2);
        $transport = new FaultTransport();
        $result = $this->distributor($transport)->distribute();

        $this->assertSame('distributed', $result['outcome']);
        $this->assertSame(['renditions', 'manifest', 'source_map', 'verified', 'health'], $result['stages']);
        $c = ContractFixture::constants();
        $rids = [$c['assets']['A']['rid'], $c['assets']['B']['rid']];
        sort($rids);
        $this->assertSame([
            [$this->v->viewerRoot, $this->viewerDest, array_map(static fn ($r) => 'generations/' . self::GEN . '/' . $r . '.m4a', $rids)],
            [$this->v->viewerRoot, $this->viewerDest, ['manifests/' . self::GEN . '.json']],
            [$this->v->privateRoot, $this->privateDest, ['source-maps/' . self::GEN . '.json']],
            [$this->v->viewerRoot, $this->viewerDest, ['health.json']],
        ], $transport->calls, 'Explicit manifest-derived file lists, health last.');

        // Each destination holds exactly its half, byte-identical to the frozen fixture.
        $fixture = ContractFixture::dir() . '/fixtures/publication';
        $viewerFiles = ViewerEstate::snapshot($this->viewerDest);
        $expected = ['health.json' => hash_file('sha256', $fixture . '/viewer-root/health.json'),
                     'manifests/' . self::GEN . '.json' => hash_file('sha256', $fixture . '/viewer-root/manifests/' . self::GEN . '.json')];
        foreach ($rids as $rid) {
            $rel = 'generations/' . self::GEN . '/' . $rid . '.m4a';
            $expected[$rel] = hash_file('sha256', $fixture . '/viewer-root/' . $rel);
        }
        ksort($expected);
        $this->assertSame($expected, $viewerFiles);
        $this->assertSame(['source-maps/' . self::GEN . '.json' => hash_file('sha256', $fixture . '/private-root/source-maps/' . self::GEN . '.json')], ViewerEstate::snapshot($this->privateDest));

        // lof-core's reader, as the contract defines it, accepts the destination unchanged.
        $this->assertSame(['ok', 'ok'], $this->referenceVerdict());

        // Nothing source-side reached the viewer destination.
        $bytes = ViewerEstate::treeBytes($this->viewerDest);
        foreach (array_merge($c['leak_denylist'], ['source_rel', 'supply_generation', $c['rid_key_hex'], $this->v->estate->root]) as $needle) {
            $this->assertStringNotContains($needle, $bytes, 'viewer destination leaks');
        }
        [$files] = ViewerEstate::fixtureAssets();
        foreach ($files as $master) {
            $this->assertStringNotContains(substr($master, 0, 64), $bytes, 'no master bytes');
        }
        $this->assertStringNotContains($c['rid_key_hex'], ViewerEstate::treeBytes($this->privateDest));

        $supplyHealth = Json::readFile($this->v->estate->publicationRoot . '/' . ViewerDistributor::LAST_RUN_FILE);
        $this->assertSame('distributed', $supplyHealth['outcome']);
    }

    public function testAnUnchangedDestinationMovesNothing(): void
    {
        $this->publish(self::GEN, 2);
        $this->distributor()->distribute();
        $transport = new FaultTransport();
        $again = $this->distributor($transport)->distribute();
        $this->assertSame('unchanged', $again['outcome']);
        $this->assertSame([], $transport->calls);
    }

    // ---- failure --------------------------------------------------------------

    public function testFailureAtEveryStageLeavesTheOldPointerAuthoritative(): void
    {
        $this->publish(self::GEN_PREV, 1);
        $this->distributor()->distribute();
        $served = $this->destHealth();
        $this->publish(self::GEN, 2);

        $faults = [
            'renditions throw' => [FaultTransport::THROW, 1, 'viewer_distribute.failed'],
            'renditions partial' => [FaultTransport::PARTIAL, 1, 'viewer_distribute.transfer_incomplete'],
            'manifest throw' => [FaultTransport::THROW, 2, 'viewer_distribute.failed'],
            'manifest reported failure' => [FaultTransport::REPORT, 2, 'viewer_distribute.transfer_incomplete'],
            'source map throw' => [FaultTransport::THROW, 3, 'viewer_distribute.failed'],
            'rendition corrupted in flight' => [FaultTransport::CORRUPT, 1, 'viewer_distribute.destination_unverified'],
            'manifest corrupted in flight' => [FaultTransport::CORRUPT, 2, 'viewer_distribute.destination_unverified'],
            'source map corrupted in flight' => [FaultTransport::CORRUPT, 3, 'viewer_distribute.destination_unverified'],
            'health throw' => [FaultTransport::THROW, 4, 'viewer_distribute.failed'],
        ];
        foreach ($faults as $label => [$mode, $call, $code]) {
            $e = $this->assertRefused($code, fn () => $this->distributor(new FaultTransport($mode, $call))->distribute(), $label);
            $this->assertSame($served, $this->destHealth(), $label . ': the old pointer moved');
            $this->assertSame(['ok', 'ok'], $this->referenceVerdict(), $label . ': the old generation is no longer servable');
            $this->assertStringNotContains('Midnight', json_encode($e->context()) . $e->getMessage());
            $last = Json::readFile($this->v->estate->publicationRoot . '/' . ViewerDistributor::LAST_RUN_FILE);
            $this->assertFalse($last['destination_pointer_moved'], $label);
            $this->assertSame('unchanged', $last['destination_pointer_observed'], $label . ': observed by read-back');
            // Repair the destination copy the fault left behind, as a retry would.
            $this->distributor()->distribute();
            $this->assertSame(self::GEN, Json::decode((string) $this->destHealth())['current']['generation'], $label . ': retry delivers');
            file_put_contents($this->viewerDest . '/health.json', $served);
        }
    }

    public function testAHealthCopyThatReportsFailureIsReportedAsAMovedPointer(): void
    {
        $this->v->estate->seedMusic(['a.mp3' => 'one']);
        $this->v->estate->publisher()->publish();
        $this->publish(self::GEN_PREV, 1);
        $this->distributor()->distribute();
        $old = $this->destHealth();
        $this->publish(self::GEN, 2);

        $e = $this->assertRefused('viewer_distribute.pointer_moved_unverified', fn () => $this->distributor(new FaultTransport(FaultTransport::COPY_REPORT, 4))->distribute());
        $this->assertSame('viewer_distribute.transfer_incomplete', $e->context()['reason']);
        $this->assertSame('ok', $e->context()['destination_verdict'], 'The bytes it moved to happen to verify.');
        // The truth: the new pointer IS live, and it is reported that way.
        $this->assertNotSame($old, $this->destHealth());
        $this->assertSame((string) file_get_contents($this->v->viewerRoot . '/health.json'), $this->destHealth());
        $last = Json::readFile($this->v->estate->publicationRoot . '/' . ViewerDistributor::LAST_RUN_FILE);
        $this->assertTrue($last['destination_pointer_moved']);
        $this->assertSame('changed', $last['destination_pointer_observed']);
        $this->assertSame('ok', $last['destination_verdict']);
        $this->assertSame(['renditions', 'manifest', 'source_map', 'verified'], $last['completed_stages']);
        $status = Json::readFile($this->v->estate->publicationRoot . '/health.json');
        $this->assertTrue($status['viewer_distribution']['destination_pointer_moved'], 'Supply health reports it too.');
        // A clean retry then completes normally.
        $this->assertSame('unchanged', $this->distributor()->distribute()['outcome']);
    }

    public function testPostCommitTamperIsReportedAsAMovedUnverifiedPointer(): void
    {
        $this->publish(self::GEN_PREV, 1);
        $this->distributor()->distribute();
        $old = $this->destHealth();
        $this->publish(self::GEN, 2);

        $e = $this->assertRefused('viewer_distribute.pointer_moved_unverified', fn () => $this->distributor(new FaultTransport(FaultTransport::TAMPER_AFTER, 4))->distribute());
        $this->assertSame('viewer_distribute.post_commit_unverified', $e->context()['reason']);
        $this->assertSame('file_size_drift', $e->context()['destination_verdict'], 'lof-core would refuse what is now live.');
        $this->assertNotSame($old, $this->destHealth(), 'The pointer moved; the outcome must not claim otherwise.');
        $last = Json::readFile($this->v->estate->publicationRoot . '/' . ViewerDistributor::LAST_RUN_FILE);
        $this->assertTrue($last['destination_pointer_moved']);
        $this->assertSame('file_size_drift', $last['destination_verdict']);
        $this->assertSame(['renditions', 'manifest', 'source_map', 'verified', 'health'], $last['completed_stages']);
        $this->assertStringNotContains('not moved', $e->getMessage());
        // Redelivery repairs the tampered rendition and re-proves the live pointer.
        $this->assertSame('distributed', $this->distributor()->distribute()['outcome']);
        $this->assertSame(['ok', 'ok'], $this->referenceVerdict());
    }

    public function testRefusesAnUnverifiedLocalPublication(): void
    {
        $this->publish(self::GEN, 2);
        $file = glob($this->v->viewerRoot . '/generations/' . self::GEN . '/*.m4a')[0];
        file_put_contents($file, 'tampered', FILE_APPEND);
        $transport = new FaultTransport();
        $e = $this->assertRefused('viewer_distribute.source_unverified', fn () => $this->distributor($transport)->distribute());
        $this->assertSame('file_size_drift', $e->context()['reason']);
        $this->assertSame([], $transport->calls);
        $this->assertSame(null, $this->destHealth());
    }

    public function testDestinationTamperExtraFilesAndSymlinks(): void
    {
        $this->publish(self::GEN, 2);
        $this->distributor()->distribute();
        $served = $this->destHealth();
        $genDir = $this->viewerDest . '/generations/' . self::GEN;
        $rendition = glob($genDir . '/*.m4a')[0];
        $good = (string) file_get_contents($rendition);

        // A tampered delivered rendition is repaired by redelivery and re-proved.
        file_put_contents($rendition, 'X' . substr($good, 1));
        $this->assertSame('distributed', $this->distributor()->distribute()['outcome']);
        $this->assertSame($good, (string) file_get_contents($rendition));

        // A symlinked delivered file is replaced by a real file, never followed.
        $outside = $this->v->estate->root . '/outside.m4a';
        file_put_contents($outside, 'must never be served or overwritten');
        unlink($rendition);
        symlink($outside, $rendition);
        $this->assertSame('distributed', $this->distributor()->distribute()['outcome']);
        $this->assertFalse(is_link($rendition));
        $this->assertSame('must never be served or overwritten', (string) file_get_contents($outside));

        // An extra file (an original master) in the delivered generation is refused, never deleted.
        file_put_contents($genDir . '/Midnight Overture (MASTER).wav', 'planted');
        $e = $this->assertRefused('viewer_distribute.destination_unverified', fn () => $this->distributor()->distribute());
        $this->assertSame('file_unexpected', $e->context()['reason']);
        $this->assertTrue(is_file($genDir . '/Midnight Overture (MASTER).wav'), 'Distribution never deletes at the destination.');
        $this->assertSame($served, $this->destHealth());
        unlink($genDir . '/Midnight Overture (MASTER).wav');

        // A symlinked destination directory is refused before any transfer.
        $fresh = $this->v->estate->root . '/srv/elsewhere';
        mkdir($fresh, 0755, true);
        rename($this->viewerDest . '/generations', $fresh . '/generations');
        symlink($fresh . '/generations', $this->viewerDest . '/generations');
        $transport = new FaultTransport();
        $this->assertRefused('viewer_distribute.destination_symlink', fn () => $this->distributor($transport)->distribute());
        $this->assertSame([], $transport->calls);
    }

    public function testRollbackAndRetryKeepCurrentAndPreviousRecoverable(): void
    {
        $this->publish(self::GEN_PREV, 1);
        $this->distributor()->distribute();
        $this->publish(self::GEN, 2);
        $this->distributor()->distribute();
        $this->assertTrue(is_dir($this->viewerDest . '/generations/' . self::GEN_PREV), 'The previous generation stays at the destination.');
        $this->assertTrue(is_file($this->privateDest . '/source-maps/' . self::GEN_PREV . '.json'));

        $rolled = $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder())->rollback();
        $this->assertSame(self::GEN_PREV, $rolled['generation']);
        $result = $this->distributor()->distribute();
        $this->assertSame('distributed', $result['outcome']);
        $h = Json::decode((string) $this->destHealth());
        $this->assertSame(self::GEN_PREV, $h['current']['generation']);
        $this->assertSame(self::GEN, $h['previous_generation']);
        $this->assertSame(['ok', 'ok'], $this->referenceVerdict());
        $this->assertTrue(is_dir($this->viewerDest . '/generations/' . self::GEN));
    }

    // ---- configuration ----------------------------------------------------------

    public function testDestinationsMustBeArmedConfiguredAbsoluteSeparateAndPrivate(): void
    {
        $this->publish(self::GEN, 2);
        $this->assertSame([], Policy::default()->viewerDistribution, 'Shipped default: nothing configured.');
        $example = new Policy(Json::readFile(LOF_AUDIO_SUPPLY_ROOT . '/policy.example.json'));
        $this->assertFalse($example->viewerDistribution['enabled']);
        $this->assertSame('', $example->viewerDistribution['viewer_destination']);

        $e = $this->v->estate;
        $cases = [
            'default disarmed' => [['enabled' => false], 'viewer_distribute.disarmed'],
            'unset' => [['viewer_destination' => ''], 'viewer_distribute.unconfigured'],
            'relative' => [['viewer_destination' => 'srv/viewer'], 'viewer_distribute.not_absolute'],
            'not normalised' => [['viewer_destination' => $this->viewerDest . '/../viewer2'], 'viewer_distribute.not_normal'],
            'same root' => [['private_destination' => $this->viewerDest], 'viewer_distribute.overlap'],
            'nested roots' => [['private_destination' => $this->viewerDest . '/private'], 'viewer_distribute.overlap'],
            'inside local viewer root' => [['viewer_destination' => $this->v->viewerRoot . '/out'], 'viewer_distribute.overlap'],
            'inside local private root' => [['private_destination' => $this->v->privateRoot . '/out'], 'viewer_distribute.overlap'],
            'inside masters' => [['viewer_destination' => $e->musicRoot . '/out'], 'viewer_distribute.overlap'],
            'inside media supply' => [['private_destination' => $e->publicationRoot . '/out'], 'viewer_distribute.overlap'],
            'inside keys' => [['private_destination' => $e->configRoot . '/out'], 'viewer_distribute.overlap'],
            'inside supply destination' => [['viewer_destination' => '/srv/lof-audio/viewer'], 'viewer_distribute.overlap'],
            'viewer under web root' => [['viewer_destination' => $this->v->webRoot . '/viewer'], 'viewer_distribute.public'],
            'private under web root' => [['private_destination' => $this->v->webRoot . '/private'], 'viewer_distribute.public'],
            'unknown key' => [['destination_host' => 'x'], 'viewer_distribute.bad_config'],
            'bad mode' => [['mode' => 'ftp'], 'viewer_distribute.bad_config'],
        ];
        foreach ($cases as $label => [$block, $code]) {
            $transport = new FaultTransport();
            $this->assertRefused($code, fn () => $this->distributor($transport, $block)->distribute(), $label);
            $this->assertSame([], $transport->calls, $label . ': nothing moved');
        }

        // A destination root that resolves into a web root through a symlinked parent.
        mkdir($this->v->webRoot . '/exposed', 0755, true);
        @mkdir($e->root . '/srv', 0755, true);
        symlink($this->v->webRoot . '/exposed', $e->root . '/srv/linked');
        $this->assertRefused('viewer_distribute.public', fn () => $this->distributor(null, ['viewer_destination' => $e->root . '/srv/linked/viewer'])->distribute());

        // Remote mode cannot read the destination back, so it never starts.
        $transport = new FaultTransport();
        $this->assertRefused('viewer_distribute.remote_unverifiable', fn () => $this->distributor($transport, ['mode' => 'remote'])->distribute());
        $this->assertSame([], $transport->calls);
        $this->assertSame(null, $this->destHealth());
    }

    public function testTheCliDeliversRealPublisherOutputEndToEnd(): void
    {
        $ffmpeg = null;
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $candidate) {
            if (is_executable($candidate)) {
                $ffmpeg = $candidate;
                break;
            }
        }
        if ($ffmpeg === null) {
            $this->skip('no ffmpeg on this host; the real-encoder delivery proof needs one');
        }
        $v = new ViewerEstate($this->makeTempRoot('vdist-cli'), ['encoder' => $ffmpeg], bin2hex(random_bytes(32)));
        $e = $v->estate;
        $runner = new \LofAudioSupply\Process\ProcessRunner([$ffmpeg]);
        foreach (['SECRETNAME Overture.wav' => ['-ac', '1', '-f', 'wav'], 'SECRETNAME Theme.mp3' => ['-c:a', 'libmp3lame', '-f', 'mp3']] as $name => $args) {
            $argv = array_merge([$ffmpeg, '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=duration=1', '-metadata', 'title=SECRETTITLE'], $args, ['-y', $e->musicRoot . '/' . $name]);
            $this->assertTrue($runner->run($argv, 60)->succeeded());
        }
        $viewerDest = $e->root . '/srv/lof-core/viewer';
        $privateDest = $e->root . '/srv/lof-core/private';
        $policy = ViewerEstate::policyFor($e, $v->viewerBlock($ffmpeg))->toArray();
        $policy['viewer_distribution'] = ['enabled' => true, 'mode' => 'local', 'viewer_destination' => $viewerDest, 'private_destination' => $privateDest];
        $policyFile = $e->pluginDir . '/policy.json';
        file_put_contents($policyFile, Json::pretty($policy));
        $settingsFile = $e->pluginDir . '/settings.json';
        $run = function (array $args) use ($policyFile, $settingsFile): array {
            $process = proc_open(array_merge([PHP_BINARY, LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply'], $args),
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                ['PATH' => '/usr/bin:/bin', 'LOF_AUDIO_POLICY' => $policyFile, 'LOF_AUDIO_SETTINGS' => $settingsFile]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['code' => proc_close($process), 'stdout' => $out, 'stderr' => $err];
        };
        $this->assertSame(0, $run(['init', '--json'])['code']);
        $settings = Json::readFile($settingsFile);
        $settings['enabled'] = true;
        file_put_contents($settingsFile, Json::pretty($settings));

        $published = $run(['publish', '--json', '--force']);
        $this->assertSame(0, $published['code'], $published['stderr']);
        $this->assertFalse(is_dir($viewerDest), 'The timer publish never delivers.');

        $delivered = $run(['viewer-distribute', '--json']);
        $this->assertSame(0, $delivered['code'], $delivered['stderr']);
        $out = Json::decode($delivered['stdout']);
        $this->assertSame('distributed', $out['outcome']);
        $this->assertSame(Json::decode($published['stdout'])['viewer_rendition']['generation'], $out['generation']);
        $this->assertSame((string) file_get_contents($v->viewerRoot . '/health.json'), (string) file_get_contents($viewerDest . '/health.json'));
        $this->assertSame(['ok', 'ok'], (new LocalDestinationVerifier($viewerDest, $privateDest))->verdict(Json::readFile($viewerDest . '/health.json')));
        $bytes = ViewerEstate::treeBytes($viewerDest);
        foreach (['SECRETNAME', 'SECRETTITLE', 'Overture', '.wav', '.mp3', 'source_rel', $e->root] as $needle) {
            $this->assertStringNotContains($needle, $bytes);
        }
        $this->assertSame('unchanged', Json::decode($run(['viewer-distribute', '--json'])['stdout'])['outcome']);
        $status = Json::decode($run(['status', '--json'])['stdout']);
        $this->assertSame('unchanged', $status['viewer_distribution']['outcome']);

        $policy['viewer_distribution']['enabled'] = false;
        file_put_contents($policyFile, Json::pretty($policy));
        $disarmed = $run(['viewer-distribute', '--json']);
        $this->assertSame(2, $disarmed['code']);
        $this->assertStringContains('viewer_distribute.disarmed', $disarmed['stderr']);
    }

    public function testTheCliKeepsDeliveryOperatorOnlyAndDisarmedByDefault(): void
    {
        $source = (string) file_get_contents(LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply');
        $this->assertStringContains("'viewer-distribute' => ['json']", $source);
        $publishCase = substr($source, (int) strpos($source, "case 'publish':"), 2500);
        $this->assertStringNotContains('viewerDistributor', $publishCase, 'The timer publish never distributes.');
        $supply = (string) file_get_contents(LOF_AUDIO_SUPPLY_ROOT . '/lib/Publish/Publisher.php');
        $this->assertStringNotContains('Viewer', $supply, 'Supply distribution is not repurposed.');
    }
}

/**
 * LocalTransport with one deterministic fault on the Nth call. Records every
 * call so a test can prove order and that a refusal moved nothing.
 */
final class FaultTransport implements Transport
{
    public const NONE = 'none';
    public const THROW = 'throw';
    public const PARTIAL = 'partial';
    public const REPORT = 'report';
    public const CORRUPT = 'corrupt';
    /** Copies the files, then reports failure anyway (the pointer can move). */
    public const COPY_REPORT = 'copy-report';
    /** Copies the files, then tampers a delivered rendition (post-commit drift). */
    public const TAMPER_AFTER = 'tamper-after';

    /** @var list<array{0:string,1:string,2:list<string>}> */
    public array $calls = [];
    private LocalTransport $inner;

    public function __construct(private string $mode = self::NONE, private int $onCall = 0)
    {
        $this->inner = new LocalTransport();
    }

    public function name(): string
    {
        return 'fault-' . $this->mode;
    }

    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        $this->calls[] = [$sourceRoot, $destinationRoot, $relativePaths];
        if (count($this->calls) !== $this->onCall) {
            return $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
        }
        switch ($this->mode) {
            case self::THROW:
                throw new TransportException('transport.interrupted', 'Transfer was interrupted.');
            case self::PARTIAL:
                $this->inner->transfer($sourceRoot, $destinationRoot, array_slice($relativePaths, 0, 1));

                return new TransferReport($this->name(), 1, 0, 0.0, [], '');
            case self::REPORT:
                return new TransferReport($this->name(), 0, 0, 0.0, ['asset: refused'], '');
            case self::COPY_REPORT:
                $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);

                return new TransferReport($this->name(), 0, 0, 0.0, ['health.json: reported failed after copy'], '');
            case self::TAMPER_AFTER:
                $report = $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
                $victim = glob($destinationRoot . '/generations/*/*.m4a');
                rsort($victim, SORT_STRING);
                file_put_contents($victim[0], 'tampered after commit', FILE_APPEND);

                return $report;
            case self::CORRUPT:
                $report = $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
                $target = $destinationRoot . '/' . $relativePaths[0];
                file_put_contents($target, 'Y' . substr((string) file_get_contents($target), 1));

                return $report;
        }

        return $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
    }

    public function describe(): array
    {
        return ['transport' => $this->name()];
    }
}
