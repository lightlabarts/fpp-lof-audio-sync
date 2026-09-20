<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\Support\Json;
use LofTest\TestCase;

final class ManifestTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        $this->root = $this->makeTempRoot('manifest') . '/assets';
        mkdir($this->root, 0755, true);
    }

    /** @param array<string,string> $files */
    private function seed(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $this->writeFile($this->root . '/' . $relative, $contents);
        }
    }

    private function build(): Manifest
    {
        $skipped = [];
        $paths = \LofAudioSupply\Support\Fs::walkFiles($this->root, $skipped);

        return Manifest::build($this->root, $paths, '20260919T120000Z-aabbccdd', $this->root, '2026-09-19T12:00:00Z');
    }

    public function testRecordsSizeAndDigestPerAsset(): void
    {
        $this->seed(['a.mp3' => 'hello', 'nested/b.mp3' => 'world!']);
        $manifest = $this->build();

        $this->assertSame(2, $manifest->assetCount());
        $this->assertSame(11, $manifest->totalBytes());
        $this->assertSame(5, $manifest->assets()['a.mp3']['size']);
        $this->assertSame(hash('sha256', 'hello'), $manifest->assets()['a.mp3']['sha256']);
        $this->assertSame(hash('sha256', 'world!'), $manifest->assets()['nested/b.mp3']['sha256']);
        $this->assertSame(['a.mp3', 'nested/b.mp3'], $manifest->paths());
    }

    public function testDigestIsStableAcrossInsertionOrder(): void
    {
        $this->seed(['b.mp3' => 'two', 'a.mp3' => 'one']);
        $first = $this->build();

        $second = new Manifest(
            $first->generation,
            $first->createdUtc,
            $first->sourceRoot,
            array_reverse($first->assets(), true)
        );

        $this->assertSame($first->digest(), $second->digest(), 'Canonical encoding must sort keys.');
        $this->assertSame($first->contentDigest(), $second->contentDigest());
    }

    public function testRoundTripsThroughDisk(): void
    {
        $this->seed(['a.mp3' => 'hello']);
        $manifest = $this->build();
        $path = $this->root . '/../manifest.json';
        $manifest->writeTo($path);

        $reloaded = Manifest::readFrom($path);
        $this->assertSame($manifest->digest(), $reloaded->digest());
        $this->assertSame($manifest->createdUtc, $reloaded->createdUtc);
        $this->assertSame($manifest->assets(), $reloaded->assets());
    }

    public function testTamperingWithAnAssetEntryInvalidatesTheWholeDocument(): void
    {
        $this->seed(['a.mp3' => 'hello']);
        $manifest = $this->build();
        $path = $this->root . '/../manifest.json';
        $manifest->writeTo($path);

        $raw = Json::readFile($path);
        $raw['assets']['a.mp3']['sha256'] = str_repeat('0', 64);
        file_put_contents($path, Json::pretty($raw));

        $this->assertRefused('manifest.digest_mismatch', static fn () => Manifest::readFrom($path));
    }

    public function testForgedCountersCannotSurviveEvenWithARecomputedDigest(): void
    {
        $this->seed(['a.mp3' => 'hello', 'b.mp3' => 'there']);
        $manifest = $this->build();

        // Edit a counter and recompute the self-digest over the forged body,
        // which is the strongest forgery available to someone with write
        // access to the manifest file. It still fails, because the counters
        // are derived from the asset table on read rather than trusted.
        $body = $manifest->body();
        $body['asset_count'] = 99;
        $forged = $body + ['manifest_sha256' => hash('sha256', Json::canonical($body))];
        $this->assertRefused('manifest.digest_mismatch', static fn () => Manifest::fromArray($forged));

        $body2 = $manifest->body();
        $body2['total_bytes'] = 1;
        $forged2 = $body2 + ['manifest_sha256' => hash('sha256', Json::canonical($body2))];
        $this->assertRefused('manifest.digest_mismatch', static fn () => Manifest::fromArray($forged2));
    }

    public function testRejectsMalformedDocuments(): void
    {
        $this->assertRefused('manifest.bad_version', static fn () => Manifest::fromArray(['manifest_version' => 99]));
        $this->assertRefused('manifest.bad_field', static fn () => Manifest::fromArray(['manifest_version' => 1]));
        $this->assertRefused('manifest.bad_asset_digest', static fn () => Manifest::fromArray([
            'manifest_version' => 1,
            'generation' => 'g',
            'created_utc' => 't',
            'source_root' => '/s',
            'algorithm' => 'sha256',
            'assets' => ['a.mp3' => ['size' => 1, 'sha256' => 'not-a-digest']],
        ]));
        // A traversal smuggled in as an asset key must be refused on read.
        $this->assertRefused('relative.dot_segment', static fn () => Manifest::fromArray([
            'manifest_version' => 1,
            'generation' => 'g',
            'created_utc' => 't',
            'source_root' => '/s',
            'algorithm' => 'sha256',
            'assets' => ['../../etc/passwd' => ['size' => 1, 'sha256' => str_repeat('a', 64)]],
        ]));
    }

    public function testVerifyDetectsEveryKindOfDrift(): void
    {
        $this->seed(['keep.mp3' => 'keep', 'shrink.mp3' => 'original', 'swap.mp3' => 'aaaa', 'vanish.mp3' => 'gone']);
        $manifest = $this->build();
        $this->assertCount(0, $manifest->verifyAgainst($this->root), 'A pristine tree must verify clean.');

        // Truncation.
        file_put_contents($this->root . '/shrink.mp3', 'orig');
        // Same size, different bytes: only the digest catches this.
        file_put_contents($this->root . '/swap.mp3', 'bbbb');
        // Missing.
        unlink($this->root . '/vanish.mp3');
        // Extra file the manifest never described.
        file_put_contents($this->root . '/smuggled.mp3', 'x');

        $problems = $manifest->verifyAgainst($this->root);
        $byAsset = [];
        foreach ($problems as $problem) {
            $byAsset[$problem['asset']] = $problem['problem'];
        }

        $this->assertSame('size_mismatch', $byAsset['shrink.mp3'] ?? null);
        $this->assertSame('digest_mismatch', $byAsset['swap.mp3'] ?? null);
        $this->assertSame('missing', $byAsset['vanish.mp3'] ?? null);
        $this->assertSame('unexpected_file', $byAsset['smuggled.mp3'] ?? null);
        $this->assertFalse(isset($byAsset['keep.mp3']));
    }

    public function testVerifyRejectsAnAssetReplacedByASymlink(): void
    {
        $this->seed(['a.mp3' => 'hello']);
        $manifest = $this->build();

        unlink($this->root . '/a.mp3');
        symlink('/etc/passwd', $this->root . '/a.mp3');

        $problems = $manifest->verifyAgainst($this->root);
        $this->assertSame('a.mp3', $problems[0]['asset']);
        $this->assertSame('is_symlink', $problems[0]['problem']);
    }

    public function testDiffsDriveQuarantineAndReporting(): void
    {
        $this->seed(['stay.mp3' => 'same', 'change.mp3' => 'before', 'leave.mp3' => 'bye']);
        $before = $this->build();

        unlink($this->root . '/leave.mp3');
        file_put_contents($this->root . '/change.mp3', 'after!');
        $this->writeFile($this->root . '/arrive.mp3', 'new');
        $after = $this->build();

        $this->assertSame(['leave.mp3'], $after->removedSince($before));
        $this->assertSame(['arrive.mp3'], $after->addedSince($before));
        $this->assertSame(['change.mp3'], $after->changedSince($before));
        $this->assertFalse($after->equals($before));
        $this->assertTrue($before->equals($before));
    }

    public function testEmptySourceProducesAValidEmptyManifest(): void
    {
        $manifest = $this->build();
        $this->assertSame(0, $manifest->assetCount());
        $this->assertSame(0, $manifest->totalBytes());
        $this->assertCount(0, $manifest->verifyAgainst($this->root));
        $this->assertSame($manifest->digest(), Manifest::fromArray($manifest->toArray())->digest());
    }
}
