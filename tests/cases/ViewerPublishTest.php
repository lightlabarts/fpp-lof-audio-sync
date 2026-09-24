<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\LofAudioException;
use LofAudioSupply\Publish\Lock;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Viewer\Contract;
use LofAudioSupply\Viewer\ViewerPublisher;
use LofTest\ContractFixture;
use LofTest\FixedInspector;
use LofTest\FixedMasterSource;
use LofTest\StubEncoder;
use LofTest\TestCase;
use LofTest\ViewerEstate;

/**
 * The viewer-rendition publication, driven through a stub encoder.
 *
 * Proves the Phase 1A gate: the contract fixture's manifest, source map and
 * health pointer are reproduced byte-for-byte from its synthetic masters, and
 * every failure mode fails closed with health.json - the commit point -
 * untouched.
 */
final class ViewerPublishTest extends TestCase
{
    private const GEN = '20260923T020000Z-5e1f0a3c';
    private const GEN_PREV = '20260922T020000Z-0b7d3e21';

    private ViewerEstate $v;

    public function setUp(): void
    {
        $this->v = new ViewerEstate($this->makeTempRoot('viewer'));
    }

    /** @param array<string,mixed> $extra */
    private function opts(string $gen, array $extra = []): array
    {
        return $extra + ['generation' => $gen, 'allow_fixture_key' => true];
    }

    /** Publish the contract fixture: GEN_PREV (master A), then GEN (A and B). */
    private function publishFixture(?StubEncoder $encoder = null): array
    {
        [$files, $map] = ViewerEstate::fixtureAssets();
        $encoder ??= new StubEncoder($map);
        $first = array_slice($files, 0, 1, true);
        $this->v->publisher(new FixedMasterSource($this->v->masters($first)), $encoder)
            ->publish($this->opts(self::GEN_PREV, ['created_utc' => '2026-09-22T02:00:00Z', 'generated_utc' => '2026-09-22T02:00:07Z']));

        return $this->v->publisher(new FixedMasterSource($this->v->masters($files)), $encoder)
            ->publish($this->opts(self::GEN, ['created_utc' => '2026-09-23T02:00:00Z', 'generated_utc' => '2026-09-23T02:00:07Z']));
    }

    private function publisher(array $files, StubEncoder $encoder, ?FixedInspector $inspector = null): ViewerPublisher
    {
        return $this->v->publisher(new FixedMasterSource($this->v->masters($files)), $encoder, $inspector);
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function publishedState(): array
    {
        return [ViewerEstate::snapshot($this->v->viewerRoot . '/generations'), ViewerEstate::snapshot($this->v->privateRoot . '/source-maps')];
    }

    private function assertCommitPointUnchanged(string $healthBefore, string $message): void
    {
        $this->assertSame($healthBefore, (string) file_get_contents($this->v->viewerRoot . '/health.json'), $message . ': health.json moved');
        $verify = $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder())->verify();
        $this->assertTrue($verify['ok'], $message . ': current no longer verifies (' . $verify['reason'] . ')');
        $this->assertSame([], glob($this->v->viewerRoot . '/staging/*'), $message . ': staging left behind');
    }

    // ---- the gate: reproduce the frozen fixture ---------------------------

    public function testReproducesTheFrozenFixturePublicationByteForByte(): void
    {
        $result = $this->publishFixture();
        $this->assertSame('published', $result['outcome']);
        $this->assertSame(self::GEN_PREV, $result['previous_generation']);

        $fixture = ContractFixture::dir() . '/fixtures/publication';
        $pairs = [
            'viewer-root/health.json' => $this->v->viewerRoot . '/health.json',
            'viewer-root/manifests/' . self::GEN . '.json' => $this->v->viewerRoot . '/manifests/' . self::GEN . '.json',
            'private-root/source-maps/' . self::GEN . '.json' => $this->v->privateRoot . '/source-maps/' . self::GEN . '.json',
        ];
        $c = ContractFixture::constants();
        foreach ($c['assets'] as $asset) {
            $rel = 'generations/' . self::GEN . '/' . $asset['rid'] . '.m4a';
            $pairs['viewer-root/' . $rel] = $this->v->viewerRoot . '/' . $rel;
        }
        $digests = ContractFixture::digests();
        foreach ($pairs as $fixtureRel => $ours) {
            $this->assertSame((string) file_get_contents($fixture . '/' . $fixtureRel), (string) file_get_contents($ours), $fixtureRel);
            $this->assertSame($digests['files']['fixtures/publication/' . $fixtureRel], hash_file('sha256', $ours), $fixtureRel);
        }
        $this->assertSame($digests['viewer_manifest_sha256'], $result['manifest_sha256']);
        $map = Json::readFile($this->v->privateRoot . '/source-maps/' . self::GEN . '.json');
        $this->assertSame($digests['source_map_sha256'], $map['map_sha256']);

        // Nothing else was published for that generation.
        $this->assertSame(
            [$c['assets']['A']['rid'] . '.m4a', $c['assets']['B']['rid'] . '.m4a'],
            array_values(array_diff(scandir($this->v->viewerRoot . '/generations/' . self::GEN), ['.', '..']))
        );
    }

    public function testTheFixtureRidKeyIsRefusedOutsideTheFixtureTest(): void
    {
        [$files, $map] = ViewerEstate::fixtureAssets();
        $publisher = $this->publisher($files, new StubEncoder($map));
        $this->assertRefused('viewer.key_fixture', static fn () => $publisher->publish());
        $this->assertFalse(is_file($this->v->viewerRoot . '/health.json'));
    }

    public function testUnchangedMastersEarnNoNewGeneration(): void
    {
        $encoder = new StubEncoder(ViewerEstate::fixtureAssets()[1]);
        $this->publishFixture($encoder);
        $calls = $encoder->calls;
        $health = (string) file_get_contents($this->v->viewerRoot . '/health.json');
        [$files] = ViewerEstate::fixtureAssets();
        $again = $this->publisher($files, $encoder)->publish($this->opts('20260924T020000Z-77c0d9aa'));
        $this->assertSame('unchanged', $again['outcome']);
        $this->assertSame(self::GEN, $again['generation']);
        $this->assertSame($calls, $encoder->calls, 'Nothing was re-encoded.');
        $this->assertSame($health, (string) file_get_contents($this->v->viewerRoot . '/health.json'));
    }

    // ---- opaque ids and separation ----------------------------------------

    public function testIdsAreOpaqueKeyedAndGenerationScoped(): void
    {
        $this->publishFixture();
        $prev = Json::readFile($this->v->viewerRoot . '/manifests/' . self::GEN_PREV . '.json');
        $cur = Json::readFile($this->v->viewerRoot . '/manifests/' . self::GEN . '.json');
        $prevRids = array_keys($prev['renditions']);
        $curRids = array_keys($cur['renditions']);
        $this->assertSame([], array_values(array_intersect($prevRids, $curRids)), 'The same master gets a new id in a new generation.');

        $map = Json::readFile($this->v->privateRoot . '/source-maps/' . self::GEN . '.json');
        foreach ($map['entries'] as $rid => $entry) {
            $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', (string) $rid));
            $this->assertFalse(Contract::ridDerivable((string) $rid, $entry));
            foreach (['sha256', 'sha1', 'md5'] as $algo) {
                $this->assertStringNotContains((string) $rid, hash($algo, $entry['source_rel']));
                $this->assertStringNotContains((string) $rid, hash($algo, basename($entry['source_rel'])));
            }
            $this->assertStringNotContains((string) $rid, $entry['source_sha256']);
        }

        // A different key gives different ids for the same generation and masters.
        $other = new ViewerEstate($this->makeTempRoot('viewer-k2'), [], str_repeat('ab', 32));
        [$files, $encMap] = ViewerEstate::fixtureAssets();
        $other->publisher(new FixedMasterSource($other->masters($files)), new StubEncoder($encMap))->publish(['generation' => self::GEN]);
        $otherRids = array_keys(Json::readFile($other->viewerRoot . '/manifests/' . self::GEN . '.json')['renditions']);
        $this->assertSame([], array_values(array_intersect($curRids, $otherRids)));
    }

    public function testVisitorDocumentsCarryNoSourceFactAndTheMapStaysPrivate(): void
    {
        $this->publishFixture();
        $viewerBytes = ViewerEstate::treeBytes($this->v->viewerRoot);
        $c = ContractFixture::constants();
        foreach ($c['leak_denylist'] as $needle) {
            $this->assertStringNotContains($needle, $viewerBytes, 'viewer root leaks');
        }
        $this->assertStringNotContains($this->v->estate->musicRoot, $viewerBytes);
        $this->assertStringNotContains($this->v->privateRoot, $viewerBytes);
        $this->assertStringNotContains('source_rel', $viewerBytes);
        $this->assertStringNotContains('supply_generation', $viewerBytes);
        $this->assertSame([], glob($this->v->viewerRoot . '/*/*.flac') ?: []);
        foreach (['health.json', 'manifests/' . self::GEN . '.json'] as $doc) {
            $this->assertSame(null, Contract::forbiddenKey(Json::readFile($this->v->viewerRoot . '/' . $doc)), $doc);
        }

        // The private map is where the source facts live - and only there.
        $mapBytes = (string) file_get_contents($this->v->privateRoot . '/source-maps/' . self::GEN . '.json');
        $this->assertStringContains($c['assets']['A']['source_rel'], $mapBytes);
        $this->assertSame([], glob($this->v->viewerRoot . '/source-maps') ?: []);
        $this->assertSame(0640, fileperms($this->v->privateRoot . '/source-maps/' . self::GEN . '.json') & 0777);
        $this->assertSame(0644, fileperms($this->v->viewerRoot . '/health.json') & 0777);
        $this->assertSame(0755, fileperms($this->v->viewerRoot . '/generations/' . self::GEN) & 0777);
        foreach (glob($this->v->viewerRoot . '/generations/' . self::GEN . '/*') as $file) {
            $this->assertSame(0644, fileperms($file) & 0777);
        }
        $this->assertSame(0750, fileperms($this->v->privateRoot) & 0777);

        // Operator evidence in the media-supply publication stays source-free too.
        $lastRun = (string) file_get_contents($this->v->estate->publicationRoot . '/' . ViewerPublisher::LAST_RUN_FILE);
        foreach ($c['leak_denylist'] as $needle) {
            $this->assertStringNotContains($needle, $lastRun, 'last-run leaks');
        }
    }

    public function testRootsMustBeSeparatedFromMastersSupplyKeysAndWebRoots(): void
    {
        $e = $this->v->estate;
        $cases = [
            'viewer inside music' => [['viewer_root' => $e->musicRoot . '/viewer'], 'viewer.root_overlap'],
            'viewer contains music' => [['viewer_root' => $e->mediaRoot], 'viewer.root_overlap'],
            'viewer inside supply' => [['viewer_root' => $e->publicationRoot . '/viewer'], 'viewer.root_overlap'],
            'private inside viewer' => [['private_root' => $this->v->viewerRoot . '/private'], 'viewer.root_overlap'],
            'private is viewer' => [['private_root' => $this->v->viewerRoot], 'viewer.root_overlap'],
            'private inside keys' => [['private_root' => $e->configRoot . '/private'], 'viewer.root_overlap'],
            'viewer under web root' => [['viewer_root' => $this->v->webRoot . '/lof'], 'viewer.root_public'],
            'private under web root' => [['private_root' => $this->v->webRoot . '/lof-private'], 'viewer.root_public'],
        ];
        foreach ($cases as $label => [$override, $code]) {
            $policy = ViewerEstate::policyFor($e, $override + $this->v->viewerBlock());
            $config = \LofAudioSupply\Viewer\ViewerConfig::fromPolicy($policy, $e->settings());
            $this->assertRefused($code, static fn () => $config->assertSeparated(), $label);
        }

        // A symlinked root that resolves into a web root is refused on its real path.
        $link = $e->mediaRoot . '/lof-viewer-link';
        mkdir($this->v->webRoot . '/exposed', 0755, true);
        symlink($this->v->webRoot . '/exposed', $link);
        $config = \LofAudioSupply\Viewer\ViewerConfig::fromPolicy(ViewerEstate::policyFor($e, ['viewer_root' => $link] + $this->v->viewerBlock()), $e->settings());
        $this->assertRefused('viewer.root_symlink', static fn () => $config->assertSeparated());
        $nested = $e->mediaRoot . '/lof-viewer-link/inner';
        $config = \LofAudioSupply\Viewer\ViewerConfig::fromPolicy(ViewerEstate::policyFor($e, ['viewer_root' => $nested] + $this->v->viewerBlock()), $e->settings());
        $this->assertRefused('viewer.root_public', static fn () => $config->assertSeparated(), 'resolved path inside a web root');

        // A misspelt or malformed key is refused, never defaulted.
        foreach ([['enable' => true], ['rid_key_id' => 'Bad Id'], ['retain_generations' => '3'], ['enabled' => 'yes']] as $bad) {
            $block = $bad + $this->v->viewerBlock();
            $this->assertRefused('viewer.bad_config', static fn () => \LofAudioSupply\Viewer\ViewerConfig::fromPolicy(ViewerEstate::policyFor($e, $block), $e->settings()), json_encode($bad));
        }
        $policy = new \LofAudioSupply\Config\Policy(\LofAudioSupply\Support\Json::readFile(LOF_AUDIO_SUPPLY_ROOT . '/policy.example.json'));
        $this->assertFalse($policy->viewerRendition['enabled'], 'The shipped example keeps the lane off...');
        $this->assertNotContainsValue('/usr/bin/ffmpeg', $policy->allowedBinaries, '...and the encoder off the allowlist,');
        $this->assertRefused('viewer.encoder_not_allowed', static fn () => \LofAudioSupply\Viewer\ViewerConfig::fromPolicy($policy, $e->settings()), '...so enabling it is two deliberate edits.');

        // The encoder must be allowlisted by policy.
        $policy = ViewerEstate::policyFor($e, $this->v->viewerBlock());
        $policy = new \LofAudioSupply\Config\Policy(['allowed_binaries' => ['/usr/bin/rsync'], 'viewer_rendition' => $this->v->viewerBlock()] + $policy->toArray());
        $this->assertRefused('viewer.encoder_not_allowed', static fn () => \LofAudioSupply\Viewer\ViewerConfig::fromPolicy($policy, $e->settings()));
    }

    public function testRidKeyMustBePrivateWellFormedAndInsideAKeyRoot(): void
    {
        [$files, $map] = ViewerEstate::fixtureAssets();
        $publisher = $this->publisher($files, new StubEncoder($map));
        chmod($this->v->keyPath, 0644);
        $this->assertRefused('viewer.key_permissive', static fn () => $publisher->publish(['allow_fixture_key' => true]));
        chmod($this->v->keyPath, 0600);
        file_put_contents($this->v->keyPath, 'not-a-key');
        $this->assertRefused('viewer.key_malformed', static fn () => $publisher->publish(['allow_fixture_key' => true]));
        unlink($this->v->keyPath);
        $this->assertRefused('viewer.key_missing', static fn () => $publisher->publish(['allow_fixture_key' => true]));
        $outside = new ViewerEstate($this->makeTempRoot('viewer-key'), ['rid_key_path' => $this->v->estate->musicRoot . '/rid.key']);
        $p2 = $outside->publisher(new FixedMasterSource($outside->masters($files)), new StubEncoder($map));
        $this->assertRefused('viewer.key_outside_roots', static fn () => $p2->publish(['allow_fixture_key' => true]));
        $this->assertFalse(is_file($this->v->viewerRoot . '/health.json'));
    }

    // ---- fail closed ------------------------------------------------------

    public function testEveryEncodeFailureFailsClosedWithTheCommitPointUntouched(): void
    {
        $this->publishFixture();
        $health = (string) file_get_contents($this->v->viewerRoot . '/health.json');
        [$files, $map] = ViewerEstate::fixtureAssets();
        $files['Extra Master.wav'] = ContractFixture::synth('source-extra', 4096);

        $cases = [
            'encoder refuses (unsupported or undecodable input)' => [new StubEncoder($map, StubEncoder::FAIL, 3), null, 'viewer.encode_failed'],
            'partial rendition' => [new StubEncoder($map, StubEncoder::EMPTY, 2), null, 'viewer.partial_rendition'],
            'rendition is a symlink' => [new StubEncoder($map, StubEncoder::SYMLINK, 1), null, 'viewer.partial_rendition'],
            'master copied, not transcoded' => [new StubEncoder($map, StubEncoder::COPY, 1), null, 'viewer.not_transcoded'],
            'source name in the bytes' => [new StubEncoder($map, StubEncoder::LEAK_NAME, 1), null, 'viewer.metadata_leak'],
            'tag atoms found' => [new StubEncoder($map), new FixedInspector(['metadata_box']), 'viewer.metadata_leak'],
            'unexpected box' => [new StubEncoder($map), new FixedInspector(['unexpected_box']), 'viewer.metadata_leak'],
            'wrong profile' => [new StubEncoder($map), new FixedInspector(['codec']), 'viewer.profile_mismatch'],
            'master changed during encode' => [new StubEncoder($map, StubEncoder::DRIFT, 1), null, 'viewer.source_drift'],
        ];
        foreach ($cases as $label => [$encoder, $inspector, $code]) {
            $publisher = $this->publisher($files, $encoder, $inspector);
            $e = $this->assertRefused($code, static fn () => $publisher->publish(['allow_fixture_key' => true]), $label);
            $this->assertCommitPointUnchanged($health, $label);
            $this->assertNoSourceFactIn($e, $label);
        }
        // Each failed generation was quarantined whole, never deleted.
        $this->assertTrue(count(glob($this->v->viewerRoot . '/quarantine/*/staging/*')) >= count($cases) - 1);
    }

    public function testSupplyDriftMissingMastersAndEmptyMastersFailClosed(): void
    {
        $this->publishFixture();
        $health = (string) file_get_contents($this->v->viewerRoot . '/health.json');
        [$files, $map] = ViewerEstate::fixtureAssets();
        $files['New.wav'] = 'new master';

        $source = new FixedMasterSource($this->v->masters($files));
        $source->current = false;
        $publisher = $this->v->publisher($source, new StubEncoder($map));
        $this->assertRefused('viewer.supply_drift', static fn () => $publisher->publish(['allow_fixture_key' => true]));
        $this->assertCommitPointUnchanged($health, 'supply drift');

        $none = $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder());
        $this->assertRefused('viewer.no_masters', static fn () => $none->publish(['allow_fixture_key' => true]));
        $empty = $this->publisher(['Silent.wav' => ''], new StubEncoder());
        $this->assertRefused('viewer.unsupported_input', static fn () => $empty->publish(['allow_fixture_key' => true]));
        $this->assertCommitPointUnchanged($health, 'no masters');
    }

    public function testIdCollisionAndReusedGenerationFailClosed(): void
    {
        $this->publishFixture();
        [$files, $map] = ViewerEstate::fixtureAssets();
        $this->assertRefused('viewer.generation_exists', fn () => $this->publisher(array_slice($files, 0, 1, true), new StubEncoder($map))
            ->publish($this->opts(self::GEN_PREV)));
        $this->assertTrue(is_dir($this->v->viewerRoot . '/generations/' . self::GEN_PREV), 'An existing generation is never quarantined by a clash.');

        // Plant the id the next generation would derive into the current manifest.
        $next = '20260924T020000Z-77c0d9aa';
        $key = (string) hex2bin(ContractFixture::constants()['rid_key_hex']);
        $rel = array_key_first($files);
        $rid = Contract::deriveRid($key, $next, $rel, hash('sha256', $files[$rel]));
        $path = $this->v->viewerRoot . '/manifests/' . self::GEN . '.json';
        $m = Json::readFile($path);
        $m['renditions'][$rid] = ['size' => 1, 'sha256' => str_repeat('0', 64)];
        file_put_contents($path, Json::pretty($m));
        $files['Changed.wav'] = 'forces a new generation';
        $e = $this->assertRefused('viewer.rid_collision', fn () => $this->publisher($files, new StubEncoder($map))->publish($this->opts($next)));
        $this->assertSame($rid, $e->context()['rid']);
    }

    // ---- tamper, drift, rollback ------------------------------------------

    public function testTamperIsDetectedAndHealsOnlyThroughANewVerifiedGeneration(): void
    {
        $this->publishFixture();
        $c = ContractFixture::constants();
        $rendition = $this->v->viewerRoot . '/generations/' . self::GEN . '/' . $c['assets']['A']['rid'] . '.m4a';
        $bytes = (string) file_get_contents($rendition);
        file_put_contents($rendition, 'X' . substr($bytes, 1));
        $verify = $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder())->verify();
        $this->assertFalse($verify['ok']);
        $this->assertSame('file_digest_drift', $verify['reason']);

        // A failing run while the current is broken marks health failed so lof-core refuses it.
        [$files, $map] = ViewerEstate::fixtureAssets();
        $this->assertRefused('viewer.encode_failed', fn () => $this->publisher($files, new StubEncoder($map, StubEncoder::FAIL, 1))->publish($this->opts('20260924T020000Z-77c0d9aa')));
        $health = Json::readFile($this->v->viewerRoot . '/health.json');
        $this->assertSame('failed', $health['state']);
        $this->assertSame(self::GEN, $health['current']['generation']);

        // A good run publishes a fresh, verified generation.
        $result = $this->publisher($files, new StubEncoder($map))->publish($this->opts('20260924T030000Z-11111111'));
        $this->assertSame('published', $result['outcome']);
        $health = Json::readFile($this->v->viewerRoot . '/health.json');
        $this->assertSame('ok', $health['state']);
        $this->assertSame('20260924T030000Z-11111111', $health['current']['generation']);
        $this->assertTrue($this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder())->verify()['ok']);
    }

    public function testEveryObservedTamperMapsToItsContractReason(): void
    {
        $this->publishFixture();
        $c = ContractFixture::constants();
        $gen = $this->v->viewerRoot . '/generations/' . self::GEN;
        $a = $gen . '/' . $c['assets']['A']['rid'] . '.m4a';
        $verify = fn (): string => $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder())->verify()['reason'];
        $original = (string) file_get_contents($a);

        file_put_contents($gen . '/Midnight Overture (MASTER).wav', 'an original master under the viewer root');
        $this->assertSame('file_unexpected', $verify());
        unlink($gen . '/Midnight Overture (MASTER).wav');
        touch($gen . '/.incomplete');
        $this->assertSame('file_unexpected', $verify());
        unlink($gen . '/.incomplete');

        file_put_contents($a, $original . 'x');
        $this->assertSame('file_size_drift', $verify());
        unlink($a);
        $this->assertSame('file_missing', $verify());
        file_put_contents($this->v->estate->root . '/outside.m4a', $original);
        symlink($this->v->estate->root . '/outside.m4a', $a);
        $this->assertSame('file_symlink', $verify());
        unlink($a);
        file_put_contents($a, $original);
        $this->assertSame('ok', $verify());

        $mPath = $this->v->viewerRoot . '/manifests/' . self::GEN . '.json';
        $manifest = (string) file_get_contents($mPath);
        copy($this->v->viewerRoot . '/manifests/' . self::GEN_PREV . '.json', $mPath);
        $this->assertSame('publication_generation_drift', $verify());
        file_put_contents($mPath, $manifest);
        $this->assertSame('ok', $verify());

        $hPath = $this->v->viewerRoot . '/health.json';
        $h = Json::readFile($hPath);
        $h['current']['manifest_sha256'] = str_repeat('0', 64);
        file_put_contents($hPath, Json::pretty($h));
        $this->assertSame('publication_health_digest', $verify());

        $sPath = $this->v->privateRoot . '/source-maps/' . self::GEN . '.json';
        $h['current']['manifest_sha256'] = ContractFixture::digests()['viewer_manifest_sha256'];
        file_put_contents($hPath, Json::pretty($h));
        rename($sPath, $sPath . '.moved');
        $this->assertSame('source_map_missing', $verify());
    }

    public function testRollbackReturnsToAVerifiedPreviousAndIsReversible(): void
    {
        $this->publishFixture();
        $viewer = $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder());
        $result = $viewer->rollback();
        $this->assertSame(self::GEN_PREV, $result['generation']);
        $this->assertSame(self::GEN, $result['previous_generation']);
        $this->assertTrue($viewer->verify()['ok']);
        $this->assertSame(self::GEN_PREV, Json::readFile($this->v->viewerRoot . '/health.json')['current']['generation']);

        $this->assertSame(self::GEN, $viewer->rollback()['generation'], 'Rolling back again returns to the outgoing generation.');

        // A previous generation that no longer verifies is refused, and nothing moves.
        $prevDir = $this->v->viewerRoot . '/generations/' . self::GEN_PREV;
        $file = glob($prevDir . '/*.m4a')[0];
        file_put_contents($file, 'tampered');
        $before = (string) file_get_contents($this->v->viewerRoot . '/health.json');
        $e = $this->assertRefused('viewer.rollback_unverified', static fn () => $viewer->rollback());
        $this->assertSame('file_size_drift', $e->context()['reason']);
        $this->assertSame($before, (string) file_get_contents($this->v->viewerRoot . '/health.json'));

        $fresh = new ViewerEstate($this->makeTempRoot('viewer-norb'));
        [$files, $map] = ViewerEstate::fixtureAssets();
        $p = $fresh->publisher(new FixedMasterSource($fresh->masters($files)), new StubEncoder($map));
        $p->publish(['allow_fixture_key' => true]);
        $this->assertRefused('viewer.rollback_no_previous', static fn () => $p->rollback());
    }

    // ---- quarantine and interrupted recovery -----------------------------

    public function testInterruptedStateIsQuarantinedNeverDeletedAndNamedGenerationsAreNeverTouched(): void
    {
        $this->publishFixture();
        $root = $this->v->viewerRoot;
        $before = $this->publishedState();

        // A killed encode: staging with its marker and a partial file.
        mkdir($root . '/staging/20260924T020000Z-aaaaaaaa', 0750);
        file_put_contents($root . '/staging/20260924T020000Z-aaaaaaaa/.incomplete', '{}');
        file_put_contents($root . '/staging/20260924T020000Z-aaaaaaaa/0123456789abcdef0123456789abcdef.m4a.part', 'partial');
        // A crash after promotion, manifest and source map, but before health.json.
        mkdir($root . '/generations/20260924T030000Z-bbbbbbbb', 0755);
        file_put_contents($root . '/generations/20260924T030000Z-bbbbbbbb/fedcba9876543210fedcba9876543210.m4a', 'uncommitted');
        file_put_contents($root . '/manifests/20260924T030000Z-bbbbbbbb.json', '{}');
        file_put_contents($this->v->privateRoot . '/source-maps/20260924T030000Z-bbbbbbbb.json', '{"entries":{}}');
        // A temp file from an interrupted atomic write.
        file_put_contents($root . '/.health.json.tmp.0011223344556677', 'half');
        // health.json committed but the ledger append lost.
        file_put_contents($this->v->privateRoot . '/viewer-ledger.json', Json::pretty(['ledger_version' => 1, 'generations' => []]));

        $viewer = $this->v->publisher(new FixedMasterSource($this->v->masters([])), new StubEncoder());
        $recovered = $viewer->recover()['recovered'];
        $kinds = array_count_values(array_column($recovered, 'kind'));
        ksort($kinds);
        $this->assertSame(['generations' => 1, 'manifests' => 1, 'source-maps' => 1, 'staging' => 1, 'stray' => 1], $kinds, 'recovered kinds');

        // Named generations are intact and still verify; the ledger is repaired.
        $this->assertSame($before, $this->publishedState());
        $this->assertTrue($viewer->verify()['ok']);
        $this->assertSame([self::GEN_PREV, self::GEN], Json::readFile($this->v->privateRoot . '/viewer-ledger.json')['generations']);

        // Everything moved is still on disk, in the right quarantine.
        $this->assertCount(1, glob($root . '/quarantine/*/staging/20260924T020000Z-aaaaaaaa/*.part'));
        $this->assertCount(1, glob($root . '/quarantine/*/generations/20260924T030000Z-bbbbbbbb/*.m4a'));
        $this->assertCount(1, glob($root . '/quarantine/*/manifests/20260924T030000Z-bbbbbbbb.json'));
        $this->assertCount(1, glob($this->v->privateRoot . '/quarantine/*/source-maps/20260924T030000Z-bbbbbbbb.json'));
        $this->assertSame([], glob($root . '/quarantine/*/source-maps') ?: [], 'A source map never lands under the viewer root.');
        $this->assertCount(1, glob($root . '/quarantine/*/stray/.health.json.tmp.*'));
        $this->assertSame([], glob($root . '/staging/*'));
    }

    public function testAKilledPublishIsRecoveredByTheNextRun(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->skip('pcntl/posix are not available on this PHP build');
        }
        $this->publishFixture();
        $health = (string) file_get_contents($this->v->viewerRoot . '/health.json');
        [$files, $map] = ViewerEstate::fixtureAssets();
        $files['Third.wav'] = ContractFixture::synth('source-third', 8192);
        $masters = $this->v->masters($files);
        $ready = $this->v->estate->root . '/child-ready';

        $pid = pcntl_fork();
        if ($pid === 0) {
            $encoder = new class ($map, $ready) extends \LofTest\Cases\HangingEncoderBase {
            };
            try {
                $this->v->publisher(new FixedMasterSource($masters), $encoder)->publish(['allow_fixture_key' => true]);
            } catch (\Throwable $e) {
            }
            exit(0);
        }
        $deadline = microtime(true) + 10;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertTrue(is_file($ready), 'The child reached the middle of encoding.');
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        $this->assertSame($health, (string) file_get_contents($this->v->viewerRoot . '/health.json'), 'A killed run never moves the commit point.');
        $this->assertCount(1, glob($this->v->viewerRoot . '/staging/*'));

        $result = $this->v->publisher(new FixedMasterSource($masters), new StubEncoder($map))->publish(['allow_fixture_key' => true]);
        $this->assertSame('published', $result['outcome']);
        $this->assertSame('staging', $result['recovered'][0]['kind']);
        $this->assertSame([], glob($this->v->viewerRoot . '/staging/*'));
        $this->assertCount(1, glob($this->v->viewerRoot . '/quarantine/*/staging/*/.incomplete'));
    }

    public function testPruningKeepsCurrentPreviousAndTheRetainedWindow(): void
    {
        $v = new ViewerEstate($this->makeTempRoot('viewer-prune'), ['retain_generations' => 1]);
        $gens = ['20260901T000000Z-00000001', '20260902T000000Z-00000002', '20260903T000000Z-00000003', '20260904T000000Z-00000004', '20260905T000000Z-00000005'];
        foreach ($gens as $i => $gen) {
            $v->publisher(new FixedMasterSource($v->masters(['m.wav' => 'master ' . $i])), new StubEncoder())->publish(['generation' => $gen, 'allow_fixture_key' => true]);
        }
        $left = array_map('basename', glob($v->viewerRoot . '/generations/*'));
        $this->assertSame(['20260903T000000Z-00000003', '20260904T000000Z-00000004', '20260905T000000Z-00000005'], $left);
        $this->assertCount(3, glob($v->privateRoot . '/source-maps/*.json'));
        $this->assertCount(3, glob($v->viewerRoot . '/manifests/*.json'));
    }

    public function testTheLaneSharesTheSupplyLock(): void
    {
        [$files, $map] = ViewerEstate::fixtureAssets();
        $lock = new Lock($this->v->supply()->lockPath());
        $lock->acquire();
        try {
            $this->assertRefused('lock.busy', fn () => $this->publisher($files, new StubEncoder($map))->publish(['allow_fixture_key' => true]));
        } finally {
            $lock->release();
        }
        $this->assertFalse(is_file($this->v->viewerRoot . '/health.json'));
    }

    public function testFailuresAreReportedInTheMediaSupplyHealthWithoutSourceFacts(): void
    {
        $this->v->estate->seedMusic(['a.mp3' => 'one']);
        $this->v->estate->publisher()->publish();
        [$files, $map] = ViewerEstate::fixtureAssets();
        $this->assertRefused('viewer.not_transcoded', fn () => $this->publisher($files, new StubEncoder($map, StubEncoder::COPY))->publish(['allow_fixture_key' => true]));
        $health = Json::readFile($this->v->estate->publicationRoot . '/health.json');
        $this->assertSame('media-supply', $health['role']);
        $this->assertSame('ok', $health['state'], 'The supply outcome is not disturbed.');
        $this->assertSame('failed', $health['viewer_rendition']['outcome']);
        $this->assertSame('viewer.not_transcoded', $health['viewer_rendition']['error_code']);
        // The supply record is private operator evidence; the viewer block in it is source-free.
        $bytes = Json::canonical($health['viewer_rendition']);
        foreach (ContractFixture::constants()['leak_denylist'] as $needle) {
            $this->assertStringNotContains($needle, $bytes);
        }
        $this->assertStringNotContains($this->v->estate->root, $bytes);
    }

    private function assertNoSourceFactIn(\Throwable $e, string $label): void
    {
        $text = $e->getMessage() . json_encode($e instanceof LofAudioException ? $e->context() : []);
        foreach (ContractFixture::constants()['leak_denylist'] as $needle) {
            $this->assertStringNotContains($needle, $text, $label . ': error leaks');
        }
        $this->assertStringNotContains('Extra Master', $text, $label);
    }
}

/** Writes a partial rendition, signals the parent, then hangs until killed. */
abstract class HangingEncoderBase implements \LofAudioSupply\Viewer\RenditionEncoder
{
    private int $calls = 0;

    /** @param array<string,string> $map */
    public function __construct(private array $map, private string $ready)
    {
    }

    public function name(): string
    {
        return 'hanging';
    }

    public function encode(string $source, string $output): void
    {
        $this->calls++;
        $bytes = $this->map[hash_file('sha256', $source)] ?? str_repeat('h', 4096);
        if ($this->calls < 2) {
            file_put_contents($output, $bytes);

            return;
        }
        file_put_contents($output, substr($bytes, 0, 10));
        touch($this->ready);
        while (true) {
            sleep(1);
        }
    }
}
