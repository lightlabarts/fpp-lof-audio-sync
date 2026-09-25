<?php

declare(strict_types=1);

namespace LofAudioSupply\Publish;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\ValidationException;
use LofAudioSupply\Viewer\ViewerConfig;

/**
 * Where verified masters are delivered as a complete supply root (masters,
 * byte-identical manifest, `current` last) for a server-side viewer lane.
 *
 * Root-owned policy only (`supply_delivery`), disarmed and with no destination
 * by default. Separate from the settings `destination_path` used by the legacy
 * masters-only `distribute`. mode "local" is the only mode that runs; "remote"
 * is refused before any transfer because no transport can read the destination
 * back or swap its pointer.
 */
final class SupplyDeliveryConfig
{
    public const MODE_LOCAL = 'local';
    public const MODE_REMOTE = 'remote';

    /**
     * @param list<string> $forbiddenRoots
     * @param list<string> $publicWebRoots
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $mode,
        public readonly string $destinationRoot,
        private array $forbiddenRoots,
        private array $publicWebRoots
    ) {
    }

    /** @return array<string,mixed> */
    public static function defaultArray(): array
    {
        return [
            'enabled' => false,
            'mode' => self::MODE_LOCAL,
            'destination_root' => '',
            'public_web_roots' => ViewerConfig::DEFAULT_PUBLIC_WEB_ROOTS,
        ];
    }

    public static function fromPolicy(Policy $policy, Settings $settings): self
    {
        $raw = $policy->supplyDelivery;
        $unknown = array_diff(array_map('strval', array_keys($raw)), array_keys(self::defaultArray()));
        if ($unknown !== []) {
            throw new ValidationException('supply_delivery', 'supply_deliver.bad_config', 'supply_delivery has an unrecognised key.');
        }
        $raw += self::defaultArray();
        if (!is_bool($raw['enabled']) || !in_array($raw['mode'], [self::MODE_LOCAL, self::MODE_REMOTE], true)
            || !is_string($raw['destination_root']) || !is_array($raw['public_web_roots']) || $raw['public_web_roots'] === []) {
            throw new ValidationException('supply_delivery', 'supply_deliver.bad_config', 'supply_delivery has a malformed value.');
        }
        $web = [];
        foreach ($raw['public_web_roots'] as $w) {
            if (!is_string($w)) {
                throw new ValidationException('supply_delivery', 'supply_deliver.bad_config', 'public_web_roots must be paths.');
            }
            $web[] = rtrim(SafePath::normalizeAbsolute($w, 'supply_delivery.public_web_roots'), '/');
        }

        // The viewer lane's roots, as configured or defaulted, are never a
        // delivery target, and neither are its distribution destinations.
        $viewer = $policy->viewerRendition + ViewerConfig::defaultArray();
        $distribution = $policy->viewerDistribution;
        $forbidden = array_merge(
            $policy->sourceRoots,
            $policy->publicationRoots,
            $policy->keyRoots,
            [$settings->sourcePath, $settings->publicationRoot]
        );
        foreach ([$viewer['viewer_root'] ?? null, $viewer['private_root'] ?? null, $distribution['viewer_destination'] ?? null, $distribution['private_destination'] ?? null] as $p) {
            if (is_string($p) && $p !== '' && $p[0] === '/') {
                $forbidden[] = rtrim(SafePath::normalizeAbsolute($p, 'supply_delivery.forbidden'), '/');
            }
        }

        return new self($raw['enabled'], $raw['mode'], trim($raw['destination_root']), array_values(array_unique($forbidden)), $web);
    }

    /**
     * Refuse a destination that is disarmed, unset, relative, not normalised,
     * the filesystem root, a symlink or below a symlinked ancestor, or that
     * overlaps a source, supply, key, viewer or public web root - judged on
     * the configured and the resolved path.
     */
    public function assertDestination(): string
    {
        if (!$this->enabled) {
            throw new PolicyViolationException('supply_deliver.disarmed', 'Supply delivery is not enabled in policy.');
        }
        if ($this->mode === self::MODE_REMOTE) {
            throw new PolicyViolationException('supply_deliver.remote_unverifiable', 'Remote supply delivery cannot read back or swap the destination pointer; it stays disarmed.');
        }
        $path = $this->destinationRoot;
        if ($path === '') {
            throw new PolicyViolationException('supply_deliver.unconfigured', 'No supply delivery destination is configured.');
        }
        if ($path[0] !== '/') {
            throw new PolicyViolationException('supply_deliver.not_absolute', 'The destination must be an absolute path.');
        }
        $root = rtrim(SafePath::normalizeAbsolute($path, 'destination_root'), '/');
        if ($root === '' || $root !== rtrim($path, '/')) {
            throw new PolicyViolationException('supply_deliver.not_normal', 'The destination must be a normalised absolute path.');
        }
        $walk = '';
        foreach (explode('/', ltrim($root, '/')) as $segment) {
            $walk .= '/' . $segment;
            if (is_link($walk)) {
                throw new PolicyViolationException('supply_deliver.destination_symlink', 'The destination or one of its ancestors is a symlink.');
            }
        }
        $overlaps = static fn (string $a, string $b): bool => SafePath::isContainedIn($a, $b) || SafePath::isContainedIn($b, $a);
        foreach ([false, true] as $resolved) {
            $r = static fn (string $p): string => $resolved ? SafePath::resolveDeepest($p) : $p;
            foreach ($this->forbiddenRoots as $other) {
                if ($overlaps($r($root), $r($other))) {
                    throw new PolicyViolationException('supply_deliver.overlap', 'The destination overlaps a source, supply, key or viewer root.');
                }
            }
            foreach ($this->publicWebRoots as $web) {
                if ($overlaps($r($root), $r($web))) {
                    throw new PolicyViolationException('supply_deliver.public', 'The destination is inside a public web root.');
                }
            }
        }

        return $root;
    }
}
