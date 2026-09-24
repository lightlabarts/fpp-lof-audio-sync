<?php
/**
 * Viewer-rendition lane test support: the frozen contract fixture, a viewer
 * estate, and encoder/inspector/master-source doubles.
 *
 * Every master and rendition here is synthetic: the contract's generator
 * bytes, or tiny tones produced on the spot. No show media is read or copied.
 */

declare(strict_types=1);

namespace LofTest;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;
use LofAudioSupply\TransportException;
use LofAudioSupply\Viewer\MasterSet;
use LofAudioSupply\Viewer\MasterSource;
use LofAudioSupply\Viewer\RenditionEncoder;
use LofAudioSupply\Viewer\RenditionInspector;
use LofAudioSupply\Viewer\ViewerConfig;
use LofAudioSupply\Viewer\ViewerPublisher;

/** The vendored, byte-identical copy of PHONE-AUDIO-CROSS-REPO-CONTRACT-V1-2026-09-23. */
final class ContractFixture
{
    public static function dir(): string
    {
        return __DIR__ . '/contract-v1';
    }

    /** @return array<string,mixed> */
    public static function constants(): array
    {
        return Json::readFile(self::dir() . '/fixtures/constants.json');
    }

    /** @return array<string,mixed> */
    public static function digests(): array
    {
        return Json::readFile(self::dir() . '/fixtures/expected-digests.json');
    }

    /** The contract's synthetic byte generator. */
    public static function synth(string $label, int $size): string
    {
        $out = '';
        for ($i = 0; strlen($out) < $size; $i++) {
            $out .= hash('sha256', 'lof-phone-audio-contract-v1|' . $label . '|' . $i, true);
        }

        return substr($out, 0, $size);
    }

    /** sha256 over "<sha256>  <relpath>\n" lines, sorted bytewise by path. */
    public static function contractDigest(): string
    {
        $root = self::dir();
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile()) {
                $files[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        sort($files, SORT_STRING);
        $lines = '';
        foreach ($files as $rel) {
            $lines .= hash_file('sha256', $root . '/' . $rel) . '  ' . $rel . "\n";
        }

        return hash('sha256', $lines);
    }
}

/** A fixed set of masters with a controllable "still current" answer. */
final class FixedMasterSource implements MasterSource
{
    public bool $current = true;

    public function __construct(public MasterSet $set)
    {
    }

    public function load(): MasterSet
    {
        return $this->set;
    }

    public function stillCurrent(MasterSet $set): bool
    {
        return $this->current;
    }
}

/**
 * Deterministic stand-in for the encoder: output bytes are a function of the
 * master's digest. Behaviours reproduce each way a real encoder run can go
 * wrong.
 */
final class StubEncoder implements RenditionEncoder
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const EMPTY = 'empty';
    public const COPY = 'copy';
    public const LEAK_NAME = 'leak-name';
    public const SYMLINK = 'symlink';
    public const DRIFT = 'drift';

    public int $calls = 0;

    /** @param array<string,string> $bySourceSha master sha256 => rendition bytes */
    public function __construct(public array $bySourceSha = [], public string $mode = self::OK, public int $failOnCall = 1)
    {
    }

    public function name(): string
    {
        return 'stub';
    }

    public function encode(string $source, string $output): void
    {
        $this->calls++;
        if (file_exists($output)) {
            throw new TransportException('viewer.encode_failed', 'Output already exists.');
        }
        $sha = hash_file('sha256', $source);
        $bytes = $this->bySourceSha[$sha] ?? ('stub-rendition|' . $sha . str_repeat("\x5a", 2048));
        $mode = $this->calls >= $this->failOnCall ? $this->mode : self::OK;
        switch ($mode) {
            case self::FAIL:
                file_put_contents($output, substr($bytes, 0, 100));
                throw new TransportException('viewer.encode_failed', 'Encoder refused or failed on a master.', ['exit_code' => 1]);
            case self::EMPTY:
                touch($output);
                return;
            case self::COPY:
                copy($source, $output);
                return;
            case self::LEAK_NAME:
                file_put_contents($output, $bytes . basename($source));
                return;
            case self::SYMLINK:
                symlink($source, $output);
                return;
            case self::DRIFT:
                file_put_contents($output, $bytes);
                file_put_contents($source, 'changed underneath the encoder', FILE_APPEND);
                return;
            default:
                file_put_contents($output, $bytes);
        }
    }
}

/** An inspector with a fixed verdict. */
final class FixedInspector implements RenditionInspector
{
    /** @param list<string> $problems */
    public function __construct(private array $problems = [])
    {
    }

    public function inspect(string $path): array
    {
        return $this->problems;
    }
}

/**
 * A viewer lane mounted on a temporary FPP 10 estate, with web roots inside
 * the estate so containment can be exercised without touching /var/www.
 */
final class ViewerEstate
{
    public Estate $estate;
    public string $viewerRoot;
    public string $privateRoot;
    public string $webRoot;
    public string $keyPath;
    public string $mastersDir;
    public Policy $policy;

    /** @param array<string,mixed> $viewerOverrides */
    public function __construct(string $root, array $viewerOverrides = [], string $keyHex = '')
    {
        $this->estate = new Estate($root);
        $media = $this->estate->mediaRoot;
        $this->viewerRoot = $media . '/lof-viewer-rendition';
        $this->privateRoot = $media . '/lof-viewer-private';
        $this->webRoot = $this->estate->root . '/var/www';
        $this->mastersDir = $this->estate->publicationRoot . '/generations/20260923T015500Z-a41c9e07';
        @mkdir($this->webRoot, 0755, true);
        $this->keyPath = $this->estate->configRoot . '/lof-viewer-rid.key';
        file_put_contents($this->keyPath, ($keyHex !== '' ? $keyHex : ContractFixture::constants()['rid_key_hex']) . "\n");
        chmod($this->keyPath, 0600);
        $this->policy = self::policyFor($this->estate, $viewerOverrides + $this->viewerBlock());
    }

    /** @return array<string,mixed> */
    public function viewerBlock(string $encoder = '/usr/bin/ffmpeg'): array
    {
        return [
            'enabled' => true,
            'viewer_root' => $this->viewerRoot,
            'private_root' => $this->privateRoot,
            'rid_key_path' => $this->keyPath,
            'rid_key_id' => 'fixture-r1',
            'encoder' => $encoder,
            'retain_generations' => 3,
            'encode_timeout_seconds' => 60,
            'public_web_roots' => [$this->webRoot, $this->estate->root . '/opt/fpp/www'],
        ];
    }

    /** @param array<string,mixed> $viewer */
    public static function policyFor(Estate $estate, array $viewer): Policy
    {
        $binaries = ['/usr/bin/rsync', '/usr/bin/ssh'];
        if (is_string($viewer['encoder'] ?? null) && !in_array($viewer['encoder'], $binaries, true)) {
            $binaries[] = $viewer['encoder'];
        }

        return new Policy([
            'source_roots' => [$estate->musicRoot, $estate->uploadRoot],
            'publication_roots' => [$estate->publicationRoot],
            'key_roots' => [$estate->configRoot],
            'destination_roots' => ['/var/www/lof-audio', '/srv/lof-audio'],
            'known_hosts_path' => $estate->configRoot . '/lof-audio-known_hosts',
            'allowed_binaries' => array_merge($binaries, ['/usr/bin/ffmpeg']),
            'viewer_rendition' => $viewer,
        ]);
    }

    public function config(): ViewerConfig
    {
        return ViewerConfig::fromPolicy($this->policy, $this->estate->settings());
    }

    public function supply(): Generations
    {
        $g = new Generations($this->estate->publicationRoot);
        $g->initialise();

        return $g;
    }

    public function publisher(MasterSource $masters, RenditionEncoder $encoder, ?RenditionInspector $inspector = null): ViewerPublisher
    {
        return new ViewerPublisher($this->config(), $this->supply(), $this->estate->settings(), $masters, $encoder, $inspector ?? new FixedInspector());
    }

    /**
     * Write synthetic masters into a directory standing in for a verified
     * supply generation and describe them the way SupplyMasterSource would.
     *
     * @param array<string,string> $files source_rel => bytes
     */
    public function masters(array $files, string $supplyGeneration = '20260923T015500Z-a41c9e07', ?string $supplyManifestSha256 = null): MasterSet
    {
        $masters = [];
        foreach ($files as $rel => $bytes) {
            $path = $this->mastersDir . '/' . $rel;
            Fs::ensureDir(\dirname($path), 0755);
            file_put_contents($path, $bytes);
            $masters[$rel] = ['path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        ksort($masters, SORT_STRING);

        return new MasterSet(
            $supplyGeneration,
            $supplyManifestSha256 ?? hash('sha256', 'fixture media-supply manifest'),
            $masters,
            array_values(array_map(static fn (array $m): string => $m['sha256'], $masters))
        );
    }

    /**
     * The contract fixture's two masters and the stub mapping that yields its
     * two renditions.
     *
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    public static function fixtureAssets(): array
    {
        $c = ContractFixture::constants();
        $files = [];
        $map = [];
        foreach (['A', 'B'] as $k) {
            $a = $c['assets'][$k];
            $source = ContractFixture::synth('source-' . $k, $a['source_size']);
            $files[$a['source_rel']] = $source;
            $map[hash('sha256', $source)] = ContractFixture::synth('rendition-' . $k, $a['rendition_size']);
        }

        return [$files, $map];
    }

    /**
     * Every byte under $root, with each root-relative path, for leak scans.
     */
    public static function treeBytes(string $root): string
    {
        $out = '';
        if (!is_dir($root)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $out .= substr($f->getPathname(), strlen($root) + 1) . "\n";
            if ($f->isFile() && !$f->isLink()) {
                $out .= (string) file_get_contents($f->getPathname()) . "\n";
            }
        }

        return $out;
    }

    /** @return array<string,string> relative path => sha256 of every file under $root */
    public static function snapshot(string $root): array
    {
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $out[substr($f->getPathname(), strlen($root) + 1)] = $f->isLink() ? 'link' : (string) hash_file('sha256', $f->getPathname());
        }
        ksort($out, SORT_STRING);

        return $out;
    }
}
