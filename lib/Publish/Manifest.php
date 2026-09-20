<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\IntegrityException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Support\SafePath;

/**
 * A versioned, self-checksummed inventory of one media generation.
 *
 * Size alone is not integrity - a truncated-then-padded file has the right
 * size - so every asset carries a SHA-256 as well. The manifest then carries a
 * digest of itself, so tampering with an entry invalidates the whole document
 * rather than just that row.
 */
final class Manifest
{
    public const VERSION = 1;
    public const ALGORITHM = 'sha256';

    /** @var array<string,array{size:int,sha256:string}> */
    private array $assets;

    /**
     * @param array<string,array{size:int,sha256:string}> $assets
     */
    public function __construct(
        public readonly string $generation,
        public readonly string $createdUtc,
        public readonly string $sourceRoot,
        array $assets
    ) {
        ksort($assets, SORT_STRING);
        $this->assets = $assets;
    }

    /** @return array<string,array{size:int,sha256:string}> */
    public function assets(): array
    {
        return $this->assets;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->assets);
    }

    public function assetCount(): int
    {
        return count($this->assets);
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->assets as $asset) {
            $total += $asset['size'];
        }

        return $total;
    }

    /**
     * Hash every named asset under $root.
     *
     * @param list<string> $relativePaths
     */
    public static function build(string $root, array $relativePaths, string $generation, string $sourceRoot, ?string $nowUtc = null): self
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw new IntegrityException('manifest.root_missing', 'Manifest root does not exist.');
        }
        $assets = [];
        foreach ($relativePaths as $relative) {
            SafePath::assertRelative($relative);
            $absolute = SafePath::join($realRoot, $relative);
            SafePath::assertRealWithin($realRoot, $absolute, 'manifest_asset');
            if (!is_file($absolute)) {
                throw new IntegrityException('manifest.asset_missing', 'Asset named for the manifest is missing.', ['asset' => $relative]);
            }
            $size = filesize($absolute);
            if ($size === false) {
                throw new IntegrityException('manifest.size_unavailable', 'Asset size could not be read.', ['asset' => $relative]);
            }
            $assets[$relative] = [
                'size' => (int) $size,
                'sha256' => Fs::sha256File($absolute),
            ];
        }

        return new self($generation, $nowUtc ?? self::nowUtc(), $sourceRoot, $assets);
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return [
            'manifest_version' => self::VERSION,
            'generation' => $this->generation,
            'created_utc' => $this->createdUtc,
            'source_root' => $this->sourceRoot,
            'algorithm' => self::ALGORITHM,
            'asset_count' => $this->assetCount(),
            'total_bytes' => $this->totalBytes(),
            'assets' => $this->assets,
        ];
    }

    public function digest(): string
    {
        return hash('sha256', Json::canonical($this->body()));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->body() + ['manifest_sha256' => $this->digest()];
    }

    public function writeTo(string $path): void
    {
        Fs::writeFileAtomic($path, Json::pretty($this->toArray()), 0640);
    }

    public static function readFrom(string $path): self
    {
        return self::fromArray(Json::readFile($path));
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $version = $raw['manifest_version'] ?? null;
        if (!is_int($version) || $version !== self::VERSION) {
            throw new IntegrityException('manifest.bad_version', 'Unsupported manifest version.');
        }
        foreach (['generation', 'created_utc', 'source_root', 'algorithm'] as $field) {
            if (!isset($raw[$field]) || !is_string($raw[$field]) || $raw[$field] === '') {
                throw new IntegrityException('manifest.bad_field', 'Manifest field is missing or not a string.', ['field' => $field]);
            }
        }
        if ($raw['algorithm'] !== self::ALGORITHM) {
            throw new IntegrityException('manifest.bad_algorithm', 'Unsupported digest algorithm.');
        }
        if (!isset($raw['assets']) || !is_array($raw['assets'])) {
            throw new IntegrityException('manifest.bad_assets', 'Manifest asset table is missing.');
        }

        $assets = [];
        foreach ($raw['assets'] as $relative => $entry) {
            if (!is_string($relative)) {
                throw new IntegrityException('manifest.bad_asset_key', 'Manifest asset key is not a string.');
            }
            SafePath::assertRelative($relative, 'manifest_asset');
            if (!is_array($entry) || !isset($entry['size'], $entry['sha256'])) {
                throw new IntegrityException('manifest.bad_asset_entry', 'Manifest asset entry is malformed.', ['asset' => $relative]);
            }
            if (!is_int($entry['size']) || $entry['size'] < 0) {
                throw new IntegrityException('manifest.bad_asset_size', 'Manifest asset size is not a non-negative integer.', ['asset' => $relative]);
            }
            if (!is_string($entry['sha256']) || preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) !== 1) {
                throw new IntegrityException('manifest.bad_asset_digest', 'Manifest asset digest is not a SHA-256 hex string.', ['asset' => $relative]);
            }
            $assets[$relative] = ['size' => $entry['size'], 'sha256' => $entry['sha256']];
        }

        $manifest = new self((string) $raw['generation'], (string) $raw['created_utc'], (string) $raw['source_root'], $assets);

        // asset_count and total_bytes are *derived* in body(), never read back
        // from the document. So a forged counter cannot produce a matching
        // self-digest either: editing it alone breaks the digest, and
        // recomputing the digest over the forged body still disagrees with the
        // value derived from the asset table. One check covers both.
        $presented = $raw['manifest_sha256'] ?? null;
        if (!is_string($presented) || !hash_equals($manifest->digest(), $presented)) {
            throw new IntegrityException('manifest.digest_mismatch', 'Manifest self-digest does not match its contents.');
        }

        return $manifest;
    }

    /**
     * Verify that $root holds exactly this manifest - no missing asset, no
     * wrong size, no wrong digest, and no extra file.
     *
     * "No extra file" is the part that matters for activation: a generation
     * that contains something the manifest never described has not been proven,
     * and must not become current.
     *
     * @return list<array{asset:string,problem:string}>
     */
    public function verifyAgainst(string $root): array
    {
        $problems = [];
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return [['asset' => '', 'problem' => 'generation_missing']];
        }

        foreach ($this->assets as $relative => $expected) {
            $absolute = SafePath::join($realRoot, $relative);
            if (is_link($absolute)) {
                $problems[] = ['asset' => $relative, 'problem' => 'is_symlink'];
                continue;
            }
            if (!is_file($absolute)) {
                $problems[] = ['asset' => $relative, 'problem' => 'missing'];
                continue;
            }
            $size = filesize($absolute);
            if ($size === false || (int) $size !== $expected['size']) {
                $problems[] = ['asset' => $relative, 'problem' => 'size_mismatch'];
                continue;
            }
            if (!hash_equals($expected['sha256'], Fs::sha256File($absolute))) {
                $problems[] = ['asset' => $relative, 'problem' => 'digest_mismatch'];
            }
        }

        $skipped = [];
        $present = Fs::walkFiles($realRoot, $skipped);
        foreach ($present as $relative) {
            if (!isset($this->assets[$relative])) {
                $problems[] = ['asset' => $relative, 'problem' => 'unexpected_file'];
            }
        }
        foreach ($skipped as $entry) {
            $problems[] = ['asset' => $entry, 'problem' => 'unverifiable_entry'];
        }

        return $problems;
    }

    /**
     * Assets present in $previous but absent here. These are the candidates
     * for quarantine - never for deletion.
     *
     * @return list<string>
     */
    public function removedSince(self $previous): array
    {
        $removed = [];
        foreach ($previous->assets as $relative => $_) {
            if (!isset($this->assets[$relative])) {
                $removed[] = $relative;
            }
        }
        sort($removed, SORT_STRING);

        return $removed;
    }

    /** @return list<string> */
    public function addedSince(self $previous): array
    {
        $added = [];
        foreach ($this->assets as $relative => $_) {
            if (!isset($previous->assets[$relative])) {
                $added[] = $relative;
            }
        }
        sort($added, SORT_STRING);

        return $added;
    }

    /** @return list<string> */
    public function changedSince(self $previous): array
    {
        $changed = [];
        foreach ($this->assets as $relative => $entry) {
            if (!isset($previous->assets[$relative])) {
                continue;
            }
            $before = $previous->assets[$relative];
            if ($before['sha256'] !== $entry['sha256'] || $before['size'] !== $entry['size']) {
                $changed[] = $relative;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->contentDigest(), $other->contentDigest());
    }

    /** Digest of the asset table alone, ignoring generation id and timestamp. */
    public function contentDigest(): string
    {
        return hash('sha256', Json::canonical($this->assets));
    }
}
