<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Support\Json;
use LofTest\CorruptingTransport;
use LofTest\Estate;
use LofTest\ExtraFileTransport;
use LofTest\InterruptedTransport;
use LofTest\TestCase;
use LofTest\UnreachableTransport;

/**
 * Every failure mode named in the work order, each asserted against the same
 * invariant: the active generation is exactly where it was, and it still
 * verifies.
 */
final class FailureTest extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
    }

    /**
     * Publish a known-good generation to have something worth protecting.
     *
     * @return array{0:string,1:\LofAudioSupply\Config\Settings}
     */
    private function seedActiveGeneration(): array
    {
        $this->estate->seedMusic(['keeper.mp3' => 'the good bytes', 'second.mp3' => 'also good']);
        $settings = $this->estate->settings();
        $result = $this->estate->publisher($settings)->publish();

        return [(string) $result->generation, $settings];
    }

    /** Assert the active generation is untouched and still provable. */
    private function assertActiveGenerationIntact(string $expected, \LofAudioSupply\Config\Settings $settings): void
    {
        $generations = $this->estate->generations($settings);
        $this->assertSame($expected, $generations->currentGeneration(), 'current must not have moved.');
        $this->assertSame('the good bytes', (string) file_get_contents($generations->currentLink() . '/keeper.mp3'));
        $this->assertCount(0, $this->estate->publisher($settings)->verifyCurrent(), 'The active generation must still verify.');
    }

    public function testInterruptedTransferLeavesTheActiveGenerationAlone(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'changed', 'second.mp3' => 'changed too', 'third.mp3' => 'new']);

        $publisher = $this->estate->publisher($settings, new InterruptedTransport(1));
        $this->assertRefused('transport.interrupted', static fn () => $publisher->publish());

        $this->assertActiveGenerationIntact($active, $settings);

        // The abandoned staging directory is still marked incomplete, so it can
        // never be mistaken for a publishable generation.
        $generations = $this->estate->generations($settings);
        $staging = $generations->listStaging();
        $this->assertCount(1, $staging);
        $this->assertTrue($generations->isIncomplete($staging[0]));
        $this->assertRefused('generation.staging_incomplete', static fn () => $generations->promoteStaging($staging[0]));
    }

    public function testHashMismatchDuringStagingIsRefused(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'revised bytes']);

        // Same byte count, different content: only the digest catches it.
        $publisher = $this->estate->publisher($settings, new CorruptingTransport('keeper.mp3'));
        $error = $this->assertRefused('publish.staging_unverified', static fn () => $publisher->publish());
        $this->assertSame('digest_mismatch', $error->context()['first']);

        $this->assertActiveGenerationIntact($active, $settings);
        $this->assertCount(1, $this->estate->generations($settings)->listGenerations(), 'No new generation may exist.');
    }

    public function testTruncatedTransferIsRefused(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'revised bytes']);

        $publisher = $this->estate->publisher($settings, new CorruptingTransport('keeper.mp3', false));
        $error = $this->assertRefused('publish.staging_unverified', static fn () => $publisher->publish());
        $this->assertSame('size_mismatch', $error->context()['first']);

        $this->assertActiveGenerationIntact($active, $settings);
    }

    public function testAnUnexpectedExtraFileBlocksActivation(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'revised bytes']);

        // A generation containing something the manifest never described has
        // not been proven, so it must not become current.
        $publisher = $this->estate->publisher($settings, new ExtraFileTransport());
        $error = $this->assertRefused('publish.staging_unverified', static fn () => $publisher->publish());
        $this->assertSame('unexpected_file', $error->context()['first']);

        $this->assertActiveGenerationIntact($active, $settings);
    }

    public function testLostConnectionLeavesTheActiveGenerationAlone(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'revised']);

        $publisher = $this->estate->publisher($settings, new UnreachableTransport());
        $this->assertRefused('transport.ssh_lost', static fn () => $publisher->publish());

        $this->assertActiveGenerationIntact($active, $settings);
    }

    public function testMissingSourceIsRefusedBeforeAnythingMoves(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        TestCase::removeTree($this->estate->musicRoot);

        $publisher = $this->estate->publisher($settings);
        $this->assertRefused('publish.source_missing', static fn () => $publisher->publish());

        $generations = $this->estate->generations($settings);
        $this->assertSame($active, $generations->currentGeneration());
        $this->assertCount(0, $generations->listStaging(), 'Nothing should have been staged.');
    }

    public function testUnreadableSourceIsRefused(): void
    {
        if ($this->runningAsRoot()) {
            $this->skip('permission checks are meaningless as root');
        }
        [$active, $settings] = $this->seedActiveGeneration();
        chmod($this->estate->musicRoot, 0000);

        $publisher = $this->estate->publisher($settings);
        try {
            $this->assertRefused('publish.source_unreadable', static fn () => $publisher->publish());
        } finally {
            chmod($this->estate->musicRoot, 0755);
        }

        $this->assertSame($active, $this->estate->generations($settings)->currentGeneration());
    }

    public function testUnwritablePublicationRootIsRefused(): void
    {
        if ($this->runningAsRoot()) {
            $this->skip('permission checks are meaningless as root');
        }
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'revised']);

        $generations = $this->estate->generations($settings);
        chmod($generations->stagingRoot(), 0500);

        $publisher = $this->estate->publisher($settings);
        try {
            $this->assertRefused(null, static fn () => $publisher->publish());
        } finally {
            chmod($generations->stagingRoot(), 0755);
        }

        $this->assertActiveGenerationIntact($active, $settings);
    }

    public function testInsufficientFreeSpaceIsRefusedBeforeStaging(): void
    {
        [, $settings] = $this->seedActiveGeneration();
        $publisher = $this->estate->publisher($settings);

        // No disk is filled: the preflight is asked directly for a size no
        // volume can satisfy.
        $error = $this->assertRefused(
            'publish.insufficient_space',
            static fn () => $publisher->assertFreeSpace(PHP_INT_MAX - 1)
        );
        $this->assertSame(PHP_INT_MAX - 1, $error->context()['required_bytes']);

        // A trivially small requirement still passes.
        $publisher->assertFreeSpace(1);
    }

    public function testStagingLeftBehindByAKilledProcessIsReclaimable(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $generations = $this->estate->generations($settings);

        // Exactly the state a SIGKILL mid-copy leaves: a staging directory with
        // partial bytes and an incomplete marker, and no process holding it.
        $orphan = Generations::newGenerationId();
        mkdir($generations->stagingDir($orphan), 0755, true);
        $generations->markIncomplete($orphan);
        file_put_contents($generations->stagingDir($orphan) . '/partial.mp3', 'half');

        // It cannot be promoted...
        $this->assertRefused('generation.staging_incomplete', static fn () => $generations->promoteStaging($orphan));
        // ...it is not swept while it could still be in flight...
        $this->assertCount(0, $generations->reclaimStaging(3600));
        $this->assertCount(1, $generations->listStaging());
        // ...and it is reclaimed once it is clearly stale.
        touch($generations->stagingDir($orphan), time() - 7200);
        $this->assertSame([$orphan], $generations->reclaimStaging(3600));
        $this->assertCount(0, $generations->listStaging());

        // A normal publish still works afterwards.
        $this->estate->seedMusic(['keeper.mp3' => 'the good bytes', 'second.mp3' => 'also good', 'third.mp3' => 'new']);
        $result = $this->estate->publisher($settings)->publish();
        $this->assertSame('published', $result->outcome);
        $this->assertSame($active, $result->previousGeneration);
    }

    public function testFailureIsRecordedInTheHealthRecordWithoutTouchingCurrent(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $this->estate->seedMusic(['keeper.mp3' => 'revised']);

        $publisher = $this->estate->publisher($settings, new UnreachableTransport());
        $this->assertRefused('transport.ssh_lost', static fn () => $publisher->publish());

        $generations = $this->estate->generations($settings);
        $health = Json::readFile($generations->healthPath());

        $this->assertSame('failed', $health['state']);
        $this->assertSame('transport.ssh_lost', $health['last_run']['error_code']);
        $this->assertFalse($health['last_run']['active_generation_disturbed']);
        // The record still describes the generation that is actually serving.
        $this->assertSame($active, $health['current']['generation']);
    }

    public function testACorruptCurrentManifestDoesNotBlockANewPublish(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $generations = $this->estate->generations($settings);
        file_put_contents($generations->manifestPath($active), '{"manifest_version": 1, "broken": true}');

        $this->estate->seedMusic(['keeper.mp3' => 'the good bytes', 'second.mp3' => 'also good', 'third.mp3' => 'fresh']);
        $result = $this->estate->publisher($settings)->publish();

        $this->assertSame('published', $result->outcome);
        $this->assertSame($result->generation, $generations->currentGeneration());
        $this->assertCount(0, $this->estate->publisher($settings)->verifyCurrent());
    }

    public function testVerifyDetectsPostActivationTampering(): void
    {
        [$active, $settings] = $this->seedActiveGeneration();
        $generations = $this->estate->generations($settings);

        file_put_contents($generations->generationDir($active) . '/keeper.mp3', 'tampered bytes!');
        $problems = $this->estate->publisher($settings)->verifyCurrent();

        $this->assertTrue(count($problems) > 0, 'Tampering with the live generation must be detectable.');
        $this->assertSame('keeper.mp3', $problems[0]['asset']);
    }
}
