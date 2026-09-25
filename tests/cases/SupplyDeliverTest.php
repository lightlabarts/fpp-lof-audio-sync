<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\LocalSupplyDestination;
use LofAudioSupply\Publish\Lock;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\Publish\Publisher;
use LofAudioSupply\Publish\SupplyDeliveryConfig;
use LofAudioSupply\Publish\SupplyDestination;
use LofAudioSupply\PublishException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;
use LofAudioSupply\TransportException;
use LofAudioSupply\Transport\LocalTransport;
use LofAudioSupply\Transport\TransferReport;
use LofAudioSupply\Transport\Transport;
use LofAudioSupply\Viewer\SupplyMasterSource;
use LofTest\Estate;
use LofTest\TestCase;

/**
 * Local verified master delivery: masters, byte-identical manifest, read-back,
 * then Generations::activate() (previous, then current - not atomic as a
 * pair). Every failure reports both pointers as read back.
 *
 * LocalTransport and deterministic fault doubles only; synthetic masters; no
 * SSH, network or live destination.
 */
final class SupplyDeliverTest extends TestCase
{
    private const MASTERS = [
        'Masters/SECRETNAME Overture (MASTER).wav' => "RIFF\0synthetic overture master",
        'SECRETNAME Theme.mp3' => "ID3\0synthetic theme master",
        'Encore/SECRETNAME Finale.flac' => "fLaC\0synthetic finale master",
    ];

    private Estate $e;
    private string $dest;

    public function setUp(): void
    {
        $this->e = new Estate($this->makeTempRoot('sdeliv'));
        $this->dest = $this->e->root . '/srv/lof-audio-masters';
        @mkdir($this->e->root . '/srv', 0755, true);
        @mkdir($this->e->root . '/var/www', 0755, true);
    }

    /** @param array<string,mixed> $block */
    private function config(array $block = []): SupplyDeliveryConfig
    {
        $policy = $this->e->policy->toArray();
        $policy['supply_delivery'] = $block + [
            'enabled' => true, 'mode' => 'local', 'destination_root' => $this->dest,
            'public_web_roots' => [$this->e->root . '/var/www'],
        ];

        return SupplyDeliveryConfig::fromPolicy(new Policy($policy), $this->e->settings());
    }

    private function publisher(): Publisher
    {
        return $this->e->publisher();
    }

    /** @param array<string,string> $files */
    private function publish(array $files = self::MASTERS): string
    {
        foreach (glob($this->e->musicRoot . '/*') ?: [] as $old) {
            TestCase::removeTree($old);
        }
        $this->e->seedMusic($files);
        $result = $this->publisher()->publish();

        return (string) $result->generation;
    }

    /** @param array<string,mixed> $block */
    private function deliver(?Transport $t = null, ?SupplyDestination $d = null, array $block = []): array
    {
        $config = $this->config($block);

        $root = $config->destinationRoot;
        $local = $config->mode === 'local' && $root !== '' && $root[0] === '/' ? new LocalSupplyDestination(rtrim($root, '/')) : null;

        return $this->publisher()->deliverSupply($config, $t ?? new SupplyFaultTransport(), $d ?? $local);
    }

    private function lastRun(): array
    {
        return Json::readFile($this->e->publicationRoot . '/' . Publisher::SUPPLY_DELIVERY_LAST_RUN_FILE);
    }

    /** @return array{current:?string,previous:?string} */
    private function links(): array
    {
        return (new LocalSupplyDestination($this->dest))->pointers();
    }

    private function assertNoSourceFacts(string $text, string $label): void
    {
        foreach (['SECRETNAME', 'Overture', 'Theme', 'Finale', 'Masters', '.wav', '.mp3', '.flac', $this->e->root] as $needle) {
            $this->assertStringNotContains($needle, $text, $label . ' leaks');
        }
        foreach (self::MASTERS as $bytes) {
            $this->assertStringNotContains(hash('sha256', $bytes), $text, $label . ' leaks a source digest');
        }
    }

    // ---- success ------------------------------------------------------------

    public function testDeliversMastersManifestThenPointerAndTheServerLaneAcceptsIt(): void
    {
        $gen = $this->publish();
        $t = new SupplyFaultTransport();
        $result = $this->deliver($t);

        $this->assertSame('delivered', $result['outcome']);
        $this->assertSame(['masters', 'manifest', 'verified', 'activated'], $result['stages']);
        $rels = array_keys(self::MASTERS);
        sort($rels, SORT_STRING);
        $expected = array_map(static fn ($r) => ['generations/' . $gen . '/' . $r], $rels);
        $expected[] = ['manifests/' . $gen . '.json'];
        $this->assertSame($expected, array_map(static fn ($c) => $c[2], $t->calls), 'one manifest-listed file per call, manifest last');

        // Exact layout, byte-identical manifest, relative current, no previous yet.
        $src = new Generations($this->e->publicationRoot);
        $this->assertSame((string) file_get_contents($src->manifestPath($gen)), (string) file_get_contents($this->dest . '/manifests/' . $gen . '.json'));
        $this->assertSame(['current' => 'generations/' . $gen, 'previous' => null], $this->links());
        $this->assertSame([], Manifest::readFrom($src->manifestPath($gen))->verifyAgainst($this->dest . '/generations/' . $gen));

        // The accepted server-side master source reads the delivery unchanged and binds the digest.
        $set = (new SupplyMasterSource(new Generations($this->dest)))->load();
        $this->assertSame($gen, $set->supplyGeneration);
        $this->assertSame(Manifest::readFrom($src->manifestPath($gen))->digest(), $set->supplyManifestSha256);
        $this->assertCount(3, $set->masters);

        $this->assertNoSourceFacts(json_encode($result) . json_encode($this->lastRun()), 'result');
        $health = Json::readFile($this->e->publicationRoot . '/health.json');
        $this->assertSame('delivered', $health['supply_delivery']['outcome']);
        $this->assertNoSourceFacts(Json::canonical($health['supply_delivery']), 'supply health block');
    }

    public function testAnUnchangedRerunMovesNothingAndANewGenerationKeepsTheOld(): void
    {
        $g1 = $this->publish();
        $this->deliver();
        $t = new SupplyFaultTransport();
        $this->assertSame('unchanged', $this->deliver($t)['outcome']);
        $this->assertSame([], $t->calls);

        $g2 = $this->publish(['New Song.mp3' => 'second generation'] + self::MASTERS);
        $this->assertSame('delivered', $this->deliver()['outcome']);
        $this->assertSame(['current' => 'generations/' . $g2, 'previous' => 'generations/' . $g1], $this->links());
        $this->assertTrue(is_dir($this->dest . '/generations/' . $g1), 'Delivery never deletes a generation.');
        $this->assertTrue(is_file($this->dest . '/manifests/' . $g1 . '.json'));
    }

    // ---- read-back and source refusals ----------------------------------------

    public function testReadBackRefusesMissingExtraTamperedResizedAndManifestDrift(): void
    {
        $g1 = $this->publish();
        $this->deliver();
        $g2 = $this->publish(['New Song.mp3' => 'second generation'] + self::MASTERS);
        $pointers = $this->links();

        $cases = [
            'missing (claimed success, nothing copied)' => [new SupplyFaultTransport(SupplyFaultTransport::SILENT, 2), null, 'supply_deliver.destination_unverified', 'files'],
            'tampered master' => [new SupplyFaultTransport(SupplyFaultTransport::CORRUPT, 1), null, 'supply_deliver.destination_unverified', 'files'],
            'size drift' => [new SupplyFaultTransport(SupplyFaultTransport::GROW, 3), null, 'supply_deliver.destination_unverified', 'files'],
            'manifest altered in flight' => [new SupplyFaultTransport(SupplyFaultTransport::CORRUPT, 5), null, 'supply_deliver.destination_unverified', 'manifest_bytes'],
            'reported failure' => [new SupplyFaultTransport(SupplyFaultTransport::REPORT, 2), null, 'supply_deliver.transfer_incomplete', null],
            'transport exception' => [new SupplyFaultTransport(SupplyFaultTransport::THROW, 4), null, 'supply_deliver.failed', 'transport.interrupted'],
        ];
        foreach ($cases as $label => [$t, $d, $code, $reason]) {
            $e = $this->assertRefused($code, fn () => $this->deliver($t, $d), $label);
            $this->assertSame($pointers, $this->links(), $label . ': a pointer moved');
            $last = $this->lastRun();
            $this->assertSame('read_back', $last['pointers_observed'], $label);
            $this->assertFalse($last['current_moved'], $label);
            $this->assertFalse($last['previous_moved'], $label);
            $this->assertSame($g1, $last['current_after'], $label);
            if ($reason !== null) {
                $this->assertSame($reason, $last['reason'], $label);
            }
            $this->assertNoSourceFacts($e->getMessage() . json_encode($e->context()) . json_encode($last), $label);
            // The server lane still reads the old delivery.
            $this->assertSame($g1, (new SupplyMasterSource(new Generations($this->dest)))->load()->supplyGeneration, $label);
        }

        // An extra file planted in the new generation is refused and never deleted.
        TestCase::removeTree($this->dest . '/generations/' . $g2);
        @mkdir($this->dest . '/generations/' . $g2, 0750, true);
        file_put_contents($this->dest . '/generations/' . $g2 . '/planted.wav', 'extra');
        $this->assertRefused('supply_deliver.destination_unverified', fn () => $this->deliver());
        $this->assertTrue(is_file($this->dest . '/generations/' . $g2 . '/planted.wav'));
        $this->assertSame($pointers, $this->links());
        unlink($this->dest . '/generations/' . $g2 . '/planted.wav');

        // A stale manifest left at the destination is replaced and re-proved; retry succeeds.
        file_put_contents($this->dest . '/manifests/' . $g2 . '.json', '{"stale":true}');
        $this->assertSame('delivered', $this->deliver()['outcome']);
        $this->assertSame('generations/' . $g2, $this->links()['current']);
    }

    public function testAnUnverifiedOrStaleSourceIsRefusedBeforeAnyTransfer(): void
    {
        $gen = $this->publish();
        $src = new Generations($this->e->publicationRoot);
        $file = $src->generationDir($gen) . '/SECRETNAME Theme.mp3';
        $good = (string) file_get_contents($file);
        file_put_contents($file, 'tampered');
        $t = new SupplyFaultTransport();
        $this->assertRefused('supply_deliver.source_unverified', fn () => $this->deliver($t));
        $this->assertSame([], $t->calls);
        file_put_contents($file, $good);

        // A manifest that names another generation (stale) is refused.
        $m = Json::readFile($src->manifestPath($gen));
        $m['generation'] = '20200101T000000Z-00000000';
        $m['manifest_sha256'] = hash('sha256', Json::canonical(array_diff_key($m, ['manifest_sha256' => 1])));
        file_put_contents($src->manifestPath($gen), Json::pretty($m));
        $e = $this->assertRefused('supply_deliver.source_unverified', fn () => $this->deliver($t));
        $this->assertSame('stale_manifest', $e->context()['reason']);
        $this->assertSame([], $t->calls);
        $this->assertFalse(is_dir($this->dest . '/generations'), 'Nothing was created at the destination.');
    }

    // ---- paths, symlinks, configuration, locks ------------------------------------

    public function testSymlinksAndUnsafeLayoutsAreRefusedBeforeAnyTransfer(): void
    {
        $gen = $this->publish();
        $outside = $this->e->root . '/outside';
        mkdir($outside, 0755);

        // Destination root is a symlink; an ancestor is a symlink.
        symlink($outside, $this->dest);
        $t = new SupplyFaultTransport();
        $this->assertRefused('supply_deliver.destination_symlink', fn () => $this->deliver($t));
        unlink($this->dest);
        symlink($outside, $this->e->root . '/srv/link');
        $this->assertRefused('supply_deliver.destination_symlink', fn () => $this->deliver($t, null, ['destination_root' => $this->e->root . '/srv/link/masters']));

        // A symlinked layout directory; a non-link pointer.
        mkdir($this->dest, 0750);
        symlink($outside, $this->dest . '/generations');
        $this->assertRefused('supply_deliver.destination_symlink', fn () => $this->deliver($t));
        unlink($this->dest . '/generations');
        mkdir($this->dest . '/current', 0750);
        $this->assertRefused('supply_deliver.destination_layout', fn () => $this->deliver($t));
        rmdir($this->dest . '/current');
        $this->assertSame([], $t->calls, 'Every refusal happened before any transfer.');

        // A planted link on a master's path that leaves the root is refused before its copy.
        mkdir($this->dest . '/generations/' . $gen . '/Masters', 0750, true);
        TestCase::removeTree($this->dest . '/generations/' . $gen . '/Masters');
        symlink($outside, $this->dest . '/generations/' . $gen . '/Masters');
        $e = $this->assertRefused('supply_deliver.failed', fn () => $this->deliver($t));
        $this->assertSame('path.symlink_escape', $e->context()['reason']);
        $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])), 'Nothing was written through the link.');
        $this->assertSame(['current' => null, 'previous' => null], $this->links());
    }

    public function testConfigurationIsDisarmedByDefaultAndRefusesUnsafeDestinations(): void
    {
        $this->publish();
        $this->assertSame([], Policy::default()->supplyDelivery);
        $example = new Policy(Json::readFile(LOF_AUDIO_SUPPLY_ROOT . '/policy.example.json'));
        $this->assertFalse($example->supplyDelivery['enabled']);
        $this->assertSame('', $example->supplyDelivery['destination_root']);

        $e = $this->e;
        $cases = [
            'disarmed' => [['enabled' => false], 'supply_deliver.disarmed'],
            'remote' => [['mode' => 'remote'], 'supply_deliver.remote_unverifiable'],
            'unset' => [['destination_root' => ''], 'supply_deliver.unconfigured'],
            'relative' => [['destination_root' => 'srv/masters'], 'supply_deliver.not_absolute'],
            'not normalised' => [['destination_root' => $this->dest . '/../x'], 'supply_deliver.not_normal'],
            'inside music' => [['destination_root' => $e->musicRoot . '/out'], 'supply_deliver.overlap'],
            'contains media' => [['destination_root' => $e->root . '/home'], 'supply_deliver.overlap'],
            'inside supply' => [['destination_root' => $e->publicationRoot . '/out'], 'supply_deliver.overlap'],
            'inside keys' => [['destination_root' => $e->configRoot . '/out'], 'supply_deliver.overlap'],
            'web root' => [['destination_root' => $e->root . '/var/www/masters'], 'supply_deliver.public'],
            'unknown key' => [['host' => 'x'], 'supply_deliver.bad_config'],
            'bad mode' => [['mode' => 'ssh'], 'supply_deliver.bad_config'],
        ];
        foreach ($cases as $label => [$block, $code]) {
            $t = new SupplyFaultTransport();
            $this->assertRefused($code, fn () => $this->deliver($t, null, $block), $label);
            $this->assertSame([], $t->calls, $label . ': nothing moved');
        }
        // Remote with an explicit null destination is refused before any lock or transfer.
        $t = new SupplyFaultTransport();
        $this->assertRefused('supply_deliver.remote_unverifiable', fn () => $this->publisher()->deliverSupply($this->config(['mode' => 'remote']), $t, null));
        $this->assertSame([], $t->calls);
        $this->assertFalse(is_dir($this->dest));

        // Viewer roots are never a delivery target.
        $policy = $this->e->policy->toArray();
        $policy['viewer_rendition'] = ['viewer_root' => $this->dest . '/viewer'];
        $policy['supply_delivery'] = ['enabled' => true, 'destination_root' => $this->dest, 'public_web_roots' => [$e->root . '/var/www']];
        $cfg = SupplyDeliveryConfig::fromPolicy(new Policy($policy), $e->settings());
        $this->assertRefused('supply_deliver.overlap', static fn () => $cfg->assertDestination());
    }

    public function testLockContentionOnEitherSideMovesNothing(): void
    {
        $this->publish();
        foreach (['source' => (new Generations($this->e->publicationRoot))->lockPath(), 'destination' => $this->dest . '/locks/publish.lock'] as $label => $path) {
            $lock = new Lock($path);
            $lock->acquire();
            try {
                $t = new SupplyFaultTransport();
                $this->assertRefused('lock.busy', fn () => $this->deliver($t), $label);
                $this->assertSame([], $t->calls, $label);
            } finally {
                $lock->release();
            }
        }
        $this->assertSame(['current' => null, 'previous' => null], $this->links());
    }

    // ---- pointer semantics ----------------------------------------------------

    public function testAFailureBetweenThePreviousAndCurrentSwapsIsReportedAsObserved(): void
    {
        $g1 = $this->publish();
        $this->deliver();
        $g2 = $this->publish(['Two.mp3' => 'two'] + self::MASTERS);
        $this->deliver();
        $g3 = $this->publish(['Three.mp3' => 'three'] + self::MASTERS);
        $before = $this->links();
        $this->assertSame(['current' => 'generations/' . $g2, 'previous' => 'generations/' . $g1], $before);

        $d = new FaultSupplyDestination(new LocalSupplyDestination($this->dest), $this->dest, FaultSupplyDestination::HALF_ACTIVATE);
        $e = $this->assertRefused('supply_deliver.previous_moved_current_unchanged', fn () => $this->deliver(null, $d));
        $this->assertSame('supply_deliver.failed', $e->context()['reason']);
        // current still old; previous changed: exactly what activate() order allows.
        $this->assertSame(['current' => 'generations/' . $g2, 'previous' => 'generations/' . $g2], $this->links());
        $last = $this->lastRun();
        $this->assertFalse($last['current_moved']);
        $this->assertTrue($last['previous_moved']);
        $this->assertSame([$g2, $g2, $g1, $g2], [$last['current_before'], $last['current_after'], $last['previous_before'], $last['previous_after']]);
        $this->assertSame(['masters', 'manifest', 'verified'], $last['completed_stages']);
        // No automatic rollback: the links stay as observed; the server lane still reads g2.
        $this->assertSame($g2, (new SupplyMasterSource(new Generations($this->dest)))->load()->supplyGeneration);
        // A clean retry completes; g3 is live.
        $this->assertSame('delivered', $this->deliver()['outcome']);
        $this->assertSame('generations/' . $g3, $this->links()['current']);
    }

    public function testAPostSwapFailureIsReportedAsAMovedUnverifiedPointer(): void
    {
        $g1 = $this->publish();
        $this->deliver();
        $g2 = $this->publish(['Two.mp3' => 'two'] + self::MASTERS);
        $d = new FaultSupplyDestination(new LocalSupplyDestination($this->dest), $this->dest, FaultSupplyDestination::POST_TAMPER);
        $e = $this->assertRefused('supply_deliver.pointer_moved_unverified', fn () => $this->deliver(null, $d));
        $this->assertSame('supply_deliver.post_activate_unverified', $e->context()['reason']);
        $this->assertSame(['current' => 'generations/' . $g2, 'previous' => 'generations/' . $g1], $this->links(), 'The pointer moved; nothing claims otherwise.');
        $last = $this->lastRun();
        $this->assertTrue($last['current_moved']);
        $this->assertSame($g2, $last['current_after']);
        $this->assertSame(['masters', 'manifest', 'verified', 'activated'], $last['completed_stages']);
        // No automatic restore; the server lane refuses the tampered live generation.
        $this->assertRefused('viewer.supply_unverified', fn () => (new SupplyMasterSource(new Generations($this->dest)))->load());
        // Redelivery repairs the file and re-proves the live pointer.
        $this->assertSame('delivered', $this->deliver()['outcome']);
        $this->assertSame($g2, (new SupplyMasterSource(new Generations($this->dest)))->load()->supplyGeneration);
    }

    public function testAnActivationFailureBeforeAnySwapLeavesBothPointers(): void
    {
        $g1 = $this->publish();
        $this->deliver();
        $this->publish(['Two.mp3' => 'two'] + self::MASTERS);
        $before = $this->links();
        $d = new FaultSupplyDestination(new LocalSupplyDestination($this->dest), $this->dest, FaultSupplyDestination::ACTIVATE_THROW);
        $this->assertRefused('supply_deliver.failed', fn () => $this->deliver(null, $d));
        $this->assertSame($before, $this->links());
        $this->assertSame($g1, $this->lastRun()['current_after']);
    }

    // ---- operator surface ---------------------------------------------------------

    public function testTheCliDeliversOperatorOnlyAndTheLegacyLegIsUntouched(): void
    {
        $policyFile = $this->e->pluginDir . '/policy.json';
        $settingsFile = $this->e->pluginDir . '/settings.json';
        $policy = $this->e->policy->toArray();
        $policy['supply_delivery'] = ['enabled' => true, 'mode' => 'local', 'destination_root' => $this->dest, 'public_web_roots' => [$this->e->root . '/var/www']];
        file_put_contents($policyFile, Json::pretty($policy));
        $run = function (array $args) use ($policyFile, $settingsFile): array {
            $p = proc_open(array_merge([PHP_BINARY, LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply'], $args),
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                ['PATH' => '/usr/bin:/bin', 'LOF_AUDIO_POLICY' => $policyFile, 'LOF_AUDIO_SETTINGS' => $settingsFile]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['code' => proc_close($p), 'stdout' => $out, 'stderr' => $err];
        };
        $this->assertSame(0, $run(['init', '--json'])['code']);
        $settings = Json::readFile($settingsFile);
        $settings['enabled'] = true;
        file_put_contents($settingsFile, Json::pretty($settings));
        $this->e->seedMusic(self::MASTERS);

        $published = $run(['publish', '--json', '--force']);
        $this->assertSame(0, $published['code'], $published['stderr']);
        $this->assertFalse(is_dir($this->dest), 'The timer publish never delivers.');

        $delivered = $run(['supply-deliver', '--json']);
        $this->assertSame(0, $delivered['code'], $delivered['stderr']);
        $this->assertSame('delivered', Json::decode($delivered['stdout'])['outcome']);
        $this->assertNoSourceFacts($delivered['stdout'] . $delivered['stderr'], 'CLI output');
        $this->assertSame('unchanged', Json::decode($run(['supply-deliver', '--json'])['stdout'])['outcome']);
        $status = $run(['status', '--json']);
        $this->assertSame('unchanged', Json::decode($status['stdout'])['supply_delivery']['outcome']);

        // The legacy masters-only leg keeps its behaviour.
        $legacy = $run(['distribute', '--json']);
        $this->assertSame(1, $legacy['code']);
        $this->assertStringContains('distribute.disarmed', $legacy['stderr']);

        $policy['supply_delivery']['enabled'] = false;
        file_put_contents($policyFile, Json::pretty($policy));
        $disarmed = $run(['supply-deliver', '--json']);
        $this->assertSame(2, $disarmed['code']);
        $this->assertStringContains('supply_deliver.disarmed', $disarmed['stderr']);

        $source = (string) file_get_contents(LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply');
        $start = (int) strpos($source, "case 'publish':");
        $end = (int) strpos($source, "\n        case '", $start + 10);
        $publishCase = substr($source, $start, $end - $start);
        $this->assertStringContains('->publish()', $publishCase);
        $this->assertStringNotContains('deliverSupply', $publishCase, 'The timer publish never delivers.');
    }
}

/** LocalTransport with one deterministic fault on the Nth call; records calls. */
final class SupplyFaultTransport implements Transport
{
    public const NONE = 'none';
    public const THROW = 'throw';
    public const REPORT = 'report';
    public const SILENT = 'silent';
    public const CORRUPT = 'corrupt';
    public const GROW = 'grow';

    /** @var list<array{0:string,1:string,2:list<string>}> */
    public array $calls = [];
    private LocalTransport $inner;

    public function __construct(private string $mode = self::NONE, private int $onCall = 0)
    {
        $this->inner = new LocalTransport();
    }

    public function name(): string
    {
        return 'supply-fault-' . $this->mode;
    }

    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        $this->calls[] = [$sourceRoot, $destinationRoot, $relativePaths];
        if (count($this->calls) !== $this->onCall) {
            return $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
        }
        $target = $destinationRoot . '/' . $relativePaths[0];
        switch ($this->mode) {
            case self::THROW:
                throw new TransportException('transport.interrupted', 'Transfer was interrupted.');
            case self::REPORT:
                return new TransferReport($this->name(), 0, 0, 0.0, [$relativePaths[0] . ': refused'], '');
            case self::SILENT:
                @unlink($target);

                return new TransferReport($this->name(), 1, 0, 0.0, [], '');
            case self::CORRUPT:
                $report = $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
                file_put_contents($target, 'Y' . substr((string) file_get_contents($target), 1));

                return $report;
            case self::GROW:
                $report = $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
                file_put_contents($target, 'extra', FILE_APPEND);

                return $report;
        }

        return $this->inner->transfer($sourceRoot, $destinationRoot, $relativePaths);
    }

    public function describe(): array
    {
        return ['transport' => $this->name()];
    }
}

/**
 * Delegates to the real LocalSupplyDestination except in activate():
 *   HALF_ACTIVATE  - performs Generations::activate()'s first step exactly
 *                    (outgoing current -> previous, via Fs::atomicSymlink) and
 *                    then fails where the current swap would, reproducing the
 *                    only intermediate state activate() can leave;
 *   POST_TAMPER    - real activate(), then a delivered master is altered;
 *   ACTIVATE_THROW - fails before either swap.
 */
final class FaultSupplyDestination implements SupplyDestination
{
    public const HALF_ACTIVATE = 'half';
    public const POST_TAMPER = 'post-tamper';
    public const ACTIVATE_THROW = 'throw';

    public function __construct(private LocalSupplyDestination $inner, private string $root, private string $mode)
    {
    }

    public function lockPath(): string
    {
        return $this->inner->lockPath();
    }

    public function prepare(string $generation): void
    {
        $this->inner->prepare($generation);
    }

    public function assertRealContainment(string $relative): void
    {
        $this->inner->assertRealContainment($relative);
    }

    public function pointers(): array
    {
        return $this->inner->pointers();
    }

    public function currentGeneration(): ?string
    {
        return $this->inner->currentGeneration();
    }

    public function manifestBytes(string $generation): ?string
    {
        return $this->inner->manifestBytes($generation);
    }

    public function verifyGeneration(Manifest $manifest, string $generation): array
    {
        return $this->inner->verifyGeneration($manifest, $generation);
    }

    public function activate(string $generation): void
    {
        if ($this->mode === self::ACTIVATE_THROW) {
            throw new PublishException('activate.generation_missing', 'Generation directory does not exist.');
        }
        if ($this->mode === self::HALF_ACTIVATE) {
            $outgoing = $this->inner->currentGeneration();
            if ($outgoing !== null && $outgoing !== $generation) {
                Fs::atomicSymlink('generations/' . $outgoing, $this->root . '/previous');
            }
            throw new PublishException('fs.symlink_swap_failed', 'Symlink could not be swapped atomically.', ['path' => 'current']);
        }
        $this->inner->activate($generation);
        $files = glob($this->root . '/generations/' . $generation . '/*.mp3');
        file_put_contents($files[0], 'tampered after activation', FILE_APPEND);
    }
}
