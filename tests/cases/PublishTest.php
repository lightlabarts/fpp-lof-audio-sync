<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\Publish\PublishResult;
use LofAudioSupply\Support\Json;
use LofTest\Estate;
use LofTest\TestCase;

final class PublishTest extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
    }

    public function testFirstPublishCreatesAVerifiedActiveGeneration(): void
    {
        $this->estate->seedMusic(['one.mp3' => 'first', 'two.mp3' => 'second', 'nested/three.mp3' => 'third']);
        $settings = $this->estate->settings();

        $result = $this->estate->publisher($settings)->publish();

        $this->assertSame(PublishResult::OUTCOME_PUBLISHED, $result->outcome);
        $this->assertSame(3, $result->assetCount);
        $this->assertSame(null, $result->previousGeneration);

        $generations = $this->estate->generations($settings);
        $current = $generations->currentGeneration();
        $this->assertSame($result->generation, $current);
        $this->assertTrue(is_link($generations->currentLink()), 'current must be a symlink so the switch is atomic.');
        $this->assertSame('generations/' . $current, readlink($generations->currentLink()), 'The link target must be relative.');

        // The published bytes are the source bytes.
        $this->assertSame('first', (string) file_get_contents($generations->currentLink() . '/one.mp3'));
        $this->assertSame('third', (string) file_get_contents($generations->currentLink() . '/nested/three.mp3'));

        // And the manifest proves it.
        $this->assertCount(0, $this->estate->publisher($settings)->verifyCurrent());

        // Staging is left clean.
        $this->assertCount(0, $generations->listStaging());
    }

    public function testRepublishingAnUnchangedSourceIsANoOp(): void
    {
        $this->estate->seedMusic(['one.mp3' => 'first']);
        $settings = $this->estate->settings();

        $first = $this->estate->publisher($settings)->publish();
        $second = $this->estate->publisher($settings)->publish();

        $this->assertSame(PublishResult::OUTCOME_PUBLISHED, $first->outcome);
        $this->assertSame(PublishResult::OUTCOME_UNCHANGED, $second->outcome);
        $this->assertSame($first->generation, $second->generation, 'No new generation for an unchanged set.');

        $generations = $this->estate->generations($settings);
        $this->assertCount(1, $generations->listGenerations());
        $this->assertSame(null, $generations->previousGeneration(), 'Nothing was replaced, so there is no previous.');
    }

    public function testAChangedAssetProducesANewGenerationAndKeepsTheOldOne(): void
    {
        $this->estate->seedMusic(['one.mp3' => 'first']);
        $settings = $this->estate->settings();
        $first = $this->estate->publisher($settings)->publish();

        $this->estate->seedMusic(['one.mp3' => 'first-revised']);
        $second = $this->estate->publisher($settings)->publish();

        $this->assertSame(PublishResult::OUTCOME_PUBLISHED, $second->outcome);
        $this->assertNotSame($first->generation, $second->generation);
        $this->assertSame($first->generation, $second->previousGeneration);

        $generations = $this->estate->generations($settings);
        $this->assertSame($second->generation, $generations->currentGeneration());
        $this->assertSame($first->generation, $generations->previousGeneration());
        $this->assertSame('first-revised', (string) file_get_contents($generations->currentLink() . '/one.mp3'));
        // The old generation is still byte-intact behind `previous`.
        $this->assertSame('first', (string) file_get_contents($generations->previousLink() . '/one.mp3'));
    }

    public function testAssetsThatVanishUpstreamAreQuarantinedNotDeleted(): void
    {
        $this->estate->seedMusic(['keep.mp3' => 'keep me', 'retire.mp3' => 'irreplaceable master']);
        $settings = $this->estate->settings();
        $first = $this->estate->publisher($settings)->publish();

        unlink($this->estate->musicRoot . '/retire.mp3');
        $second = $this->estate->publisher($settings)->publish();

        $this->assertSame(['retire.mp3'], $second->quarantined);

        $generations = $this->estate->generations($settings);
        // Gone from the new active generation...
        $this->assertFalse(is_file($generations->currentLink() . '/retire.mp3'));
        // ...still byte-intact in the previous generation...
        $this->assertSame('irreplaceable master', (string) file_get_contents($generations->previousLink() . '/retire.mp3'));

        // ...and copied into a timestamped quarantine generation with a record.
        $quarantines = $generations->listQuarantine();
        $this->assertCount(1, $quarantines);
        $dir = $generations->quarantineRoot() . '/' . $quarantines[0];
        $this->assertSame('irreplaceable master', (string) file_get_contents($dir . '/assets/retire.mp3'));

        $record = Json::readFile($dir . '/quarantine.json');
        $this->assertSame('absent-upstream', $record['reason']);
        $this->assertSame($first->generation, $record['origin_generation']);
        $this->assertSame('quarantined', $record['assets']['retire.mp3']['status']);
        $this->assertSame(hash('sha256', 'irreplaceable master'), $record['assets']['retire.mp3']['sha256']);
    }

    public function testQuarantineIsNeverEmptiedByASynchronisationRequest(): void
    {
        $this->estate->seedMusic(['a.mp3' => 'a', 'gone.mp3' => 'gone']);
        $settings = $this->estate->settings();
        $this->estate->publisher($settings)->publish();
        unlink($this->estate->musicRoot . '/gone.mp3');
        $this->estate->publisher($settings)->publish();

        $generations = $this->estate->generations($settings);
        $this->assertCount(1, $generations->listQuarantine());

        // Many more publishes must not remove it.
        for ($i = 0; $i < 4; $i++) {
            $this->estate->seedMusic(['a.mp3' => 'a' . $i]);
            $this->estate->publisher($settings)->publish();
        }
        $this->assertCount(1, $generations->listQuarantine(), 'Publishing must never prune quarantine.');

        // Deletion needs an explicit, confirmed retention command.
        $this->assertRefused('quarantine.not_confirmed', static fn () => $generations->pruneQuarantine(30, false));
        $this->assertSame([], $generations->pruneQuarantine(30, true), 'Nothing is old enough yet.');
        $this->assertCount(1, $generations->listQuarantine());

        // Age it past the window and confirm.
        $dir = $generations->quarantineRoot() . '/' . $generations->listQuarantine()[0];
        touch($dir, time() - (31 * 86400));
        $removed = $generations->pruneQuarantine(30, true);
        $this->assertCount(1, $removed);
        $this->assertCount(0, $generations->listQuarantine());
    }

    public function testRollbackReturnsToTheLastKnownGood(): void
    {
        $this->estate->seedMusic(['one.mp3' => 'good']);
        $settings = $this->estate->settings();
        $good = $this->estate->publisher($settings)->publish();

        $this->estate->seedMusic(['one.mp3' => 'regrettable']);
        $bad = $this->estate->publisher($settings)->publish();

        $publisher = $this->estate->publisher($settings);
        $target = $publisher->rollback();

        $this->assertSame($good->generation, $target);
        $generations = $this->estate->generations($settings);
        $this->assertSame($good->generation, $generations->currentGeneration());
        $this->assertSame($bad->generation, $generations->previousGeneration());
        $this->assertSame('good', (string) file_get_contents($generations->currentLink() . '/one.mp3'));
        $this->assertCount(0, $publisher->verifyCurrent());
    }

    public function testRollbackRefusesAnUnverifiableTarget(): void
    {
        $this->estate->seedMusic(['one.mp3' => 'good']);
        $settings = $this->estate->settings();
        $good = $this->estate->publisher($settings)->publish();
        $this->estate->seedMusic(['one.mp3' => 'newer']);
        $this->estate->publisher($settings)->publish();

        $generations = $this->estate->generations($settings);
        // Corrupt the rollback target on disk.
        file_put_contents($generations->generationDir((string) $good->generation) . '/one.mp3', 'tampered');

        $publisher = $this->estate->publisher($settings);
        $this->assertRefused('rollback.previous_unverified', static fn () => $publisher->rollback());
        $this->assertNotSame($good->generation, $generations->currentGeneration(), 'current must not have moved.');
    }

    public function testRollbackWithNoHistoryIsRefused(): void
    {
        $this->estate->seedMusic(['one.mp3' => 'only']);
        $settings = $this->estate->settings();
        $this->estate->publisher($settings)->publish();

        $publisher = $this->estate->publisher($settings);
        $this->assertRefused('rollback.no_previous', static fn () => $publisher->rollback());
    }

    public function testGenerationHistoryIsBounded(): void
    {
        $settings = $this->estate->settings(['retain_generations' => 2]);
        for ($i = 0; $i < 6; $i++) {
            $this->estate->seedMusic(['one.mp3' => 'revision-' . $i]);
            $this->estate->publisher($settings)->publish();
        }

        $generations = $this->estate->generations($settings);
        $onDisk = $generations->listGenerations();
        // current + previous are pinned, plus the retained window.
        $this->assertTrue(count($onDisk) <= 4, 'Retention must bound the generations kept on disk, saw ' . count($onDisk));
        $this->assertContainsValue((string) $generations->currentGeneration(), $onDisk);
        $this->assertContainsValue((string) $generations->previousGeneration(), $onDisk);

        // Manifests are pruned alongside their generations.
        $manifests = glob($generations->manifestsRoot() . '/*.json');
        $this->assertSame(count($onDisk), count($manifests));

        $history = $generations->history()['entries'];
        $this->assertTrue(count($history) <= 4, 'The activation log is bounded too.');
        $this->assertSame($generations->currentGeneration(), $history[0]['generation']);
    }

    public function testOnlyApprovedAssetExtensionsArePublished(): void
    {
        $this->estate->seedMusic([
            'song.mp3' => 'audio',
            'notes.txt' => 'not audio',
            'evil.php' => '<?php system($_GET["c"]); ?>',
            'evil.sh' => '#!/bin/sh\nid',
            'noext' => 'x',
        ]);
        $settings = $this->estate->settings();
        $result = $this->estate->publisher($settings)->publish();

        $this->assertSame(1, $result->assetCount);
        $generations = $this->estate->generations($settings);
        $this->assertTrue(is_file($generations->currentLink() . '/song.mp3'));
        foreach (['notes.txt', 'evil.php', 'evil.sh', 'noext'] as $name) {
            $this->assertFalse(is_file($generations->currentLink() . '/' . $name), $name . ' must not be published.');
        }
    }

    public function testActivationIsASingleRenameOverTheExistingLink(): void
    {
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings();
        $generations = $this->estate->generations($settings);
        $this->estate->publisher($settings)->publish();

        $before = (string) $generations->currentGeneration();
        $this->estate->seedMusic(['a.mp3' => 'two']);
        $this->estate->publisher($settings)->publish();
        $after = (string) $generations->currentGeneration();

        $this->assertNotSame($before, $after);
        // No temporary link may survive the swap.
        $strays = array_values(array_filter(
            (array) scandir($generations->root()),
            static fn (string $entry): bool => strpos($entry, '.tmp.') !== false
        ));
        $this->assertCount(0, $strays);
        $this->assertTrue(is_link($generations->currentLink()));
        $this->assertTrue(is_link($generations->previousLink()));
    }

    public function testHealthRecordIsWrittenAndDeclaresItsScope(): void
    {
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings();
        $this->estate->publisher($settings)->publish();

        $generations = $this->estate->generations($settings);
        $health = Json::readFile($generations->healthPath());

        $this->assertSame(1, $health['health_version']);
        $this->assertSame('fpp-lof-audio-sync', $health['component']);
        $this->assertSame('media-supply', $health['role']);
        $this->assertSame('ok', $health['state']);
        $this->assertSame($generations->currentGeneration(), $health['current']['generation']);
        $this->assertSame(1, $health['current']['asset_count']);

        // The scope contract is machine readable and explicitly negative.
        $this->assertTrue($health['owns']['media_supply']);
        foreach (['browser_authorization', 'playback_synchronization', 'listener_statistics', 'show_decisions', 'physical_speaker_authority'] as $notOurs) {
            $this->assertFalse($health['owns'][$notOurs], 'Audio supply must not claim ' . $notOurs);
        }
    }

    public function testManifestIsWrittenBesideEachGeneration(): void
    {
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings();
        $result = $this->estate->publisher($settings)->publish();

        $generations = $this->estate->generations($settings);
        $manifest = Manifest::readFrom($generations->manifestPath((string) $result->generation));
        $this->assertSame($result->generation, $manifest->generation);
        $this->assertSame(1, $manifest->assetCount());
        $this->assertCount(0, $manifest->verifyAgainst($generations->generationDir((string) $result->generation)));
    }
}
