<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\IntegrityException;
use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\ValidationException;

/**
 * The viewer-rendition lane's configuration.
 *
 * It lives only in the root-owned policy file (`viewer_rendition` block), never
 * in the web-editable settings: the roots, the rid key and the encoder are
 * trust decisions, not preferences. The lane is disabled by default, and the
 * encoder must also appear in `allowed_binaries`, so enabling it is always an
 * explicit root-side act.
 */
final class ViewerConfig
{
    public const DEFAULT_PUBLIC_WEB_ROOTS = ['/opt/fpp/www', '/var/www', '/srv/www', '/usr/share/nginx', '/usr/share/apache2'];

    /**
     * @param list<string> $publicWebRoots
     * @param list<string> $protectedRoots roots the viewer and private roots must stay clear of
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $viewerRoot,
        public readonly string $privateRoot,
        public readonly string $ridKeyPath,
        public readonly string $ridKeyId,
        public readonly string $encoder,
        public readonly int $retainGenerations,
        public readonly int $encodeTimeoutSeconds,
        public readonly array $publicWebRoots,
        public readonly array $protectedRoots,
        private array $keyRoots
    ) {
    }

    /** @return array<string,mixed> */
    public static function defaultArray(): array
    {
        return [
            'enabled' => false,
            'viewer_root' => Policy::FPP_MEDIA_ROOT . '/lof-viewer-rendition',
            'private_root' => Policy::FPP_MEDIA_ROOT . '/lof-viewer-private',
            'rid_key_path' => Policy::FPP_MEDIA_ROOT . '/config/lof-viewer-rid.key',
            'rid_key_id' => 'fpp-r1',
            'encoder' => '/usr/bin/ffmpeg',
            'retain_generations' => 3,
            'encode_timeout_seconds' => 600,
            'public_web_roots' => self::DEFAULT_PUBLIC_WEB_ROOTS,
        ];
    }

    public static function fromPolicy(Policy $policy, Settings $settings): self
    {
        $unknown = array_diff(array_map('strval', array_keys($policy->viewerRendition)), array_keys(self::defaultArray()));
        if ($unknown !== []) {
            // A misspelt key must not silently fall back to a default.
            throw new ValidationException('viewer_rendition', 'viewer.bad_config', 'viewer_rendition has an unrecognised key.');
        }
        $raw = $policy->viewerRendition + self::defaultArray();

        if (!is_bool($raw['enabled'])) {
            throw new ValidationException('viewer_rendition.enabled', 'viewer.bad_config', 'enabled must be a boolean.');
        }
        $viewerRoot = self::root($raw['viewer_root'], 'viewer_root');
        $privateRoot = self::root($raw['private_root'], 'private_root');
        $keyPath = self::root($raw['rid_key_path'], 'rid_key_path');
        if (!is_string($raw['rid_key_id']) || preg_match('/^[a-z0-9-]{1,32}$/', $raw['rid_key_id']) !== 1) {
            throw new ValidationException('viewer_rendition.rid_key_id', 'viewer.bad_config', 'rid_key_id has an unexpected shape.');
        }
        $encoder = self::root($raw['encoder'], 'encoder');
        if (!in_array($encoder, $policy->allowedBinaries, true)) {
            throw new PolicyViolationException('viewer.encoder_not_allowed', 'The encoder is not on the policy binary allowlist.');
        }
        $retain = self::int($raw['retain_generations'], 'retain_generations', 1, 20);
        $timeout = self::int($raw['encode_timeout_seconds'], 'encode_timeout_seconds', 10, 7200);
        if (!is_array($raw['public_web_roots']) || $raw['public_web_roots'] === []) {
            throw new ValidationException('viewer_rendition.public_web_roots', 'viewer.bad_config', 'public_web_roots must be a non-empty list.');
        }
        $web = [];
        foreach ($raw['public_web_roots'] as $w) {
            $web[] = self::root($w, 'public_web_roots');
        }

        $protected = array_values(array_unique(array_merge(
            $policy->sourceRoots,
            [$settings->sourcePath, $settings->publicationRoot],
            $policy->publicationRoots,
            $policy->keyRoots
        )));

        return new self($raw['enabled'], $viewerRoot, $privateRoot, $keyPath, $raw['rid_key_id'], $encoder, $retain, $timeout, $web, $protected, $policy->keyRoots);
    }

    /** @param mixed $value */
    private static function root($value, string $field): string
    {
        if (!is_string($value)) {
            throw new ValidationException('viewer_rendition.' . $field, 'viewer.bad_config', 'Path must be a string.');
        }
        $normalized = rtrim(SafePath::normalizeAbsolute($value, 'viewer_rendition.' . $field), '/');
        if ($normalized === '') {
            throw new ValidationException('viewer_rendition.' . $field, 'viewer.bad_config', 'Path must not be the filesystem root.');
        }

        return $normalized;
    }

    /** @param mixed $value */
    private static function int($value, string $field, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ValidationException('viewer_rendition.' . $field, 'viewer.bad_config', 'Value is not an integer in range.');
        }

        return $value;
    }

    private static function overlaps(string $a, string $b): bool
    {
        return SafePath::isContainedIn($a, $b) || SafePath::isContainedIn($b, $a);
    }

    /**
     * Complete separation, judged twice: on the configured strings and on the
     * resolved real paths, so `<viewer_root> -> /var/www/x` or a private root
     * symlinked into the masters is refused as firmly as a literal overlap.
     *
     *   - viewer and private roots are distinct and disjoint;
     *   - neither overlaps a master/source root, the media-supply publication
     *     root, or a key root;
     *   - neither is inside (or contains) a public web root;
     *   - neither is itself a symlink.
     */
    public function assertSeparated(): void
    {
        foreach (['viewer_root' => $this->viewerRoot, 'private_root' => $this->privateRoot] as $field => $root) {
            if (is_link($root)) {
                throw new PolicyViolationException('viewer.root_symlink', 'A viewer publication root is a symlink.', ['field' => $field]);
            }
        }
        $resolve = static fn (string $p): string => SafePath::resolveDeepest($p);
        foreach ([false, true] as $resolved) {
            $viewer = $resolved ? $resolve($this->viewerRoot) : $this->viewerRoot;
            $private = $resolved ? $resolve($this->privateRoot) : $this->privateRoot;
            if (self::overlaps($viewer, $private)) {
                throw new PolicyViolationException('viewer.root_overlap', 'Viewer and private roots must be disjoint.', ['field' => 'private_root']);
            }
            foreach (['viewer_root' => $viewer, 'private_root' => $private] as $field => $root) {
                foreach ($this->protectedRoots as $other) {
                    if (self::overlaps($root, $resolved ? $resolve($other) : $other)) {
                        throw new PolicyViolationException('viewer.root_overlap', 'A viewer publication root overlaps masters, media supply, or keys.', ['field' => $field]);
                    }
                }
                foreach ($this->publicWebRoots as $web) {
                    if (self::overlaps($root, $resolved ? $resolve($web) : $web)) {
                        throw new PolicyViolationException('viewer.root_public', 'A viewer publication root is inside a public web root.', ['field' => $field]);
                    }
                }
            }
        }
    }

    /** The consumer-side `publication_root_public` fact, from this host's point of view. */
    public function rootsPublic(): bool
    {
        try {
            $this->assertSeparated();
        } catch (PolicyViolationException $e) {
            return $e->code() === 'viewer.root_public';
        }

        return false;
    }

    /**
     * Read the rid key. Never logged, never copied, never written anywhere.
     *
     * The file must sit inside an approved key root, be a regular non-symlink
     * file readable by its owner only, and hold exactly 64 lowercase hex
     * characters (one trailing newline allowed).
     */
    public function loadRidKey(): string
    {
        $path = $this->ridKeyPath;
        $inside = false;
        foreach ($this->keyRoots as $root) {
            if (SafePath::isContainedIn($root, $path)) {
                $inside = true;
                if (is_dir($root)) {
                    SafePath::assertRealWithin($root, $path, 'rid_key_path');
                }
            }
        }
        if (!$inside) {
            throw new PolicyViolationException('viewer.key_outside_roots', 'The rid key is not inside an approved key root.');
        }
        if (is_link($path) || !is_file($path)) {
            throw new IntegrityException('viewer.key_missing', 'The rid key file is missing or not a regular file.');
        }
        $perms = @fileperms($path);
        if ($perms === false || ($perms & 0o077) !== 0) {
            throw new IntegrityException('viewer.key_permissive', 'The rid key file is readable beyond its owner.');
        }
        $raw = @file_get_contents($path, false, null, 0, 128);
        if ($raw === false || preg_match('/^[0-9a-f]{64}\n?$/D', $raw) !== 1) {
            throw new IntegrityException('viewer.key_malformed', 'The rid key file does not hold 64 lowercase hex characters.');
        }

        return (string) hex2bin(substr($raw, 0, 64));
    }
}
