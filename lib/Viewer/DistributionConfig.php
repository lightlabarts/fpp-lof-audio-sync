<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\ValidationException;

/**
 * Where the viewer publication is delivered for lof-core to read.
 *
 * Root-owned policy only (`viewer_distribution` block), disarmed and with no
 * destination by default. The two destinations are configured separately and
 * are never inferred from the media-supply `destination_path`: that leg
 * carries masters, this one carries renditions, and the two must never meet.
 *
 * mode "local": both destinations are paths on this host (a mounted share, or
 * the lof-core host itself). mode "remote": reserved for an SSH destination;
 * it is refused until a destination read-back mechanism exists (see
 * ViewerDistributor).
 */
final class DistributionConfig
{
    public const MODE_LOCAL = 'local';
    public const MODE_REMOTE = 'remote';

    /** @param list<string> $forbiddenRoots */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $mode,
        public readonly string $viewerDestination,
        public readonly string $privateDestination,
        private array $forbiddenRoots,
        private array $publicWebRoots
    ) {
    }

    /** @return array<string,mixed> */
    public static function defaultArray(): array
    {
        return ['enabled' => false, 'mode' => self::MODE_LOCAL, 'viewer_destination' => '', 'private_destination' => ''];
    }

    public static function fromPolicy(Policy $policy, Settings $settings, ViewerConfig $viewer): self
    {
        $raw = $policy->viewerDistribution;
        $unknown = array_diff(array_map('strval', array_keys($raw)), array_keys(self::defaultArray()));
        if ($unknown !== []) {
            throw new ValidationException('viewer_distribution', 'viewer_distribute.bad_config', 'viewer_distribution has an unrecognised key.');
        }
        $raw += self::defaultArray();
        if (!is_bool($raw['enabled']) || !in_array($raw['mode'], [self::MODE_LOCAL, self::MODE_REMOTE], true)
            || !is_string($raw['viewer_destination']) || !is_string($raw['private_destination'])) {
            throw new ValidationException('viewer_distribution', 'viewer_distribute.bad_config', 'viewer_distribution has a malformed value.');
        }
        $forbidden = array_values(array_unique(array_merge(
            $viewer->protectedRoots,
            [$viewer->viewerRoot, $viewer->privateRoot, $settings->destinationPath],
            $policy->destinationRoots
        )));

        return new self($raw['enabled'], $raw['mode'], trim($raw['viewer_destination']), trim($raw['private_destination']), $forbidden, $viewer->publicWebRoots);
    }

    /**
     * Refuse any destination that is unset, relative, public, a symlink, or
     * overlapping the other destination, the local publication, masters, keys,
     * or the media-supply destinations - judged on the configured and the
     * resolved paths.
     */
    public function assertDestinations(): void
    {
        if (!$this->enabled) {
            throw new PolicyViolationException('viewer_distribute.disarmed', 'Viewer distribution is not enabled in policy.');
        }
        if ($this->viewerDestination === '' || $this->privateDestination === '') {
            throw new PolicyViolationException('viewer_distribute.unconfigured', 'Both viewer and private destinations must be configured.');
        }
        $roots = [];
        foreach (['viewer_destination' => $this->viewerDestination, 'private_destination' => $this->privateDestination] as $field => $path) {
            if ($path[0] !== '/') {
                throw new PolicyViolationException('viewer_distribute.not_absolute', 'Destinations must be absolute paths.', ['field' => $field]);
            }
            $normalized = rtrim(SafePath::normalizeAbsolute($path, $field), '/');
            if ($normalized === '' || $normalized !== rtrim($path, '/')) {
                throw new PolicyViolationException('viewer_distribute.not_normal', 'Destinations must be normalised absolute paths.', ['field' => $field]);
            }
            if (is_link($normalized)) {
                throw new PolicyViolationException('viewer_distribute.destination_symlink', 'A destination root is a symlink.', ['field' => $field]);
            }
            $roots[$field] = $normalized;
        }
        $overlaps = static fn (string $a, string $b): bool => SafePath::isContainedIn($a, $b) || SafePath::isContainedIn($b, $a);
        foreach ([false, true] as $resolved) {
            $r = static fn (string $p): string => $resolved ? SafePath::resolveDeepest($p) : $p;
            if ($overlaps($r($roots['viewer_destination']), $r($roots['private_destination']))) {
                throw new PolicyViolationException('viewer_distribute.overlap', 'Viewer and private destinations must be disjoint.');
            }
            foreach ($roots as $field => $root) {
                foreach ($this->forbiddenRoots as $other) {
                    if ($overlaps($r($root), $r($other))) {
                        throw new PolicyViolationException('viewer_distribute.overlap', 'A destination overlaps the local publication, masters, keys, or supply destinations.', ['field' => $field]);
                    }
                }
                foreach ($this->publicWebRoots as $web) {
                    if ($overlaps($r($root), $r($web))) {
                        throw new PolicyViolationException('viewer_distribute.public', 'A destination is inside a public web root.', ['field' => $field]);
                    }
                }
            }
        }
    }
}
