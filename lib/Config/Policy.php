<?php

declare(strict_types=1);

namespace LofAudioSupply\Config;

use LofAudioSupply\LofAudioException;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Support\SafePath;

/**
 * The approved estate. Everything the validator enforces is declared here.
 *
 * The policy is deliberately *not* editable from the web form. It ships as a
 * root-owned file next to the plugin, so a compromised web session can only
 * choose between values the operator already approved - it cannot widen the
 * allowlist, add a binary, or point the transfer at a new network.
 */
final class Policy
{
    /** Where FPP 10 keeps media. Confirmed layout for FPP 8, 9, and 10. */
    public const FPP_MEDIA_ROOT = '/home/fpp/media';

    /** @var list<string> */
    public readonly array $sourceRoots;
    /** @var list<string> */
    public readonly array $publicationRoots;
    /** @var list<string> */
    public readonly array $keyRoots;
    /** @var list<string> */
    public readonly array $destinationRoots;
    /** @var list<string> */
    public readonly array $serviceUsers;
    /** @var list<string> exact hosts, CIDR blocks, or ".suffix" forms */
    public readonly array $hostAllowlist;
    /** @var list<string> */
    public readonly array $allowedBinaries;
    /** @var list<string> lowercase extensions permitted in a manifest */
    public readonly array $assetExtensions;

    public readonly int $minSyncInterval;
    public readonly int $maxSyncInterval;
    public readonly int $minRetainGenerations;
    public readonly int $maxRetainGenerations;
    public readonly int $minQuarantineDays;
    public readonly int $maxQuarantineDays;
    public readonly int $maxAssetBytes;
    public readonly int $transferTimeoutSeconds;
    /** Pinned known_hosts file. Host keys are never learned on first use. */
    public readonly string $knownHostsPath;
    /**
     * Raw `viewer_rendition` block; validated by Viewer\ViewerConfig when the
     * lane is used, so a bad block can never stop media supply from loading.
     *
     * @var array<string,mixed>
     */
    public readonly array $viewerRendition;
    /**
     * Raw `viewer_distribution` block; validated by Viewer\DistributionConfig
     * only when `viewer-distribute` runs.
     *
     * @var array<string,mixed>
     */
    public readonly array $viewerDistribution;
    /**
     * Raw `supply_delivery` block; validated by Publish\SupplyDeliveryConfig
     * only when `supply-deliver` runs.
     *
     * @var array<string,mixed>
     */
    public readonly array $supplyDelivery;

    /** @param array<string,mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $defaults = self::defaultArray();
        $merged = $overrides + $defaults;

        $this->sourceRoots = self::normalizedRoots($merged['source_roots'], 'source_roots');
        $this->publicationRoots = self::normalizedRoots($merged['publication_roots'], 'publication_roots');
        $this->keyRoots = self::normalizedRoots($merged['key_roots'], 'key_roots');
        $this->destinationRoots = self::normalizedRoots($merged['destination_roots'], 'destination_roots');
        $this->serviceUsers = self::stringList($merged['service_users'], 'service_users');
        $this->hostAllowlist = self::stringList($merged['host_allowlist'], 'host_allowlist');
        $this->allowedBinaries = self::normalizedRoots($merged['allowed_binaries'], 'allowed_binaries');
        $this->assetExtensions = array_map('strtolower', self::stringList($merged['asset_extensions'], 'asset_extensions'));

        $this->minSyncInterval = (int) $merged['min_sync_interval_seconds'];
        $this->maxSyncInterval = (int) $merged['max_sync_interval_seconds'];
        $this->minRetainGenerations = (int) $merged['min_retain_generations'];
        $this->maxRetainGenerations = (int) $merged['max_retain_generations'];
        $this->minQuarantineDays = (int) $merged['min_quarantine_retention_days'];
        $this->maxQuarantineDays = (int) $merged['max_quarantine_retention_days'];
        $this->maxAssetBytes = (int) $merged['max_asset_bytes'];
        $this->transferTimeoutSeconds = (int) $merged['transfer_timeout_seconds'];
        $this->knownHostsPath = SafePath::normalizeAbsolute((string) $merged['known_hosts_path'], 'known_hosts_path');
        if (!is_array($merged['viewer_rendition']) || array_is_list($merged['viewer_rendition']) && $merged['viewer_rendition'] !== []) {
            throw new LofAudioException('policy.bad_viewer_rendition', 'viewer_rendition must be an object.');
        }
        $this->viewerRendition = $merged['viewer_rendition'];
        if (!is_array($merged['viewer_distribution']) || array_is_list($merged['viewer_distribution']) && $merged['viewer_distribution'] !== []) {
            throw new LofAudioException('policy.bad_viewer_distribution', 'viewer_distribution must be an object.');
        }
        $this->viewerDistribution = $merged['viewer_distribution'];
        if (!is_array($merged['supply_delivery']) || array_is_list($merged['supply_delivery']) && $merged['supply_delivery'] !== []) {
            throw new LofAudioException('policy.bad_supply_delivery', 'supply_delivery must be an object.');
        }
        $this->supplyDelivery = $merged['supply_delivery'];
    }

    /** @return array<string,mixed> */
    public static function defaultArray(): array
    {
        return [
            // Audio supply reads only from the FPP media estate.
            'source_roots' => [
                self::FPP_MEDIA_ROOT . '/music',
                self::FPP_MEDIA_ROOT . '/upload',
            ],
            // Generations, manifests, quarantine, and health live here.
            'publication_roots' => [
                self::FPP_MEDIA_ROOT . '/lof-audio-supply',
            ],
            // An SSH key may only be read from inside the FPP config estate.
            'key_roots' => [
                self::FPP_MEDIA_ROOT . '/config',
                '/home/fpp/.ssh',
            ],
            // Remote roots the distribution leg may write under.
            'destination_roots' => [
                '/var/www/lof-audio',
                '/srv/lof-audio',
            ],
            // Fixed service-user policy: no arbitrary remote account.
            'service_users' => ['lof-audio', 'www-data'],
            // Private ranges plus the show's own names. Widening this is a
            // root-side edit, never a web form action.
            'host_allowlist' => [
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '::1/128',
                'fd00::/8',
                '.lightsonfalcon.com',
                '.local',
            ],
            'allowed_binaries' => [
                '/usr/bin/rsync',
                '/usr/bin/ssh',
            ],
            'asset_extensions' => ['mp3', 'ogg', 'wav', 'flac', 'm4a', 'aac', 'json'],
            'min_sync_interval_seconds' => 5,
            'max_sync_interval_seconds' => 86400,
            'min_retain_generations' => 1,
            'max_retain_generations' => 20,
            'min_quarantine_retention_days' => 1,
            'max_quarantine_retention_days' => 365,
            'max_asset_bytes' => 2147483648, // 2 GiB per asset
            'transfer_timeout_seconds' => 3600,
            'known_hosts_path' => self::FPP_MEDIA_ROOT . '/config/lof-audio-known_hosts',
            // Viewer-rendition lane: disabled unless a root-owned policy turns it on.
            'viewer_rendition' => [],
            // Viewer-publication distribution: disarmed, no destination set.
            'viewer_distribution' => [],
            // Verified local master delivery (supply-deliver): disarmed, no destination.
            'supply_delivery' => [],
        ];
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Load a root-owned policy override file if one is present.
     */
    public static function load(?string $policyFile): self
    {
        if ($policyFile === null || !is_file($policyFile)) {
            return self::default();
        }

        return new self(Json::readFile($policyFile));
    }

    /** @param mixed $value @return list<string> */
    private static function stringList($value, string $field): array
    {
        if (!is_array($value)) {
            throw new LofAudioException('policy.bad_list', 'Policy entry must be a list.', ['field' => $field]);
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new LofAudioException('policy.bad_entry', 'Policy list entry must be a non-empty string.', ['field' => $field]);
            }
            SafePath::assertSafeValue($item[0] === '.' ? 'x' . $item : $item, $field);
            $out[] = $item;
        }
        if ($out === []) {
            throw new LofAudioException('policy.empty_list', 'Policy list must not be empty.', ['field' => $field]);
        }

        return $out;
    }

    /** @param mixed $value @return list<string> */
    private static function normalizedRoots($value, string $field): array
    {
        $out = [];
        foreach (self::stringList($value, $field) as $item) {
            $out[] = rtrim(SafePath::normalizeAbsolute($item, $field), '/');
        }

        return $out;
    }

    public function defaultSourcePath(): string
    {
        return $this->sourceRoots[0];
    }

    public function defaultPublicationRoot(): string
    {
        return $this->publicationRoots[0];
    }

    public function defaultDestinationPath(): string
    {
        return $this->destinationRoots[0];
    }

    public function defaultServiceUser(): string
    {
        return $this->serviceUsers[0];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'source_roots' => $this->sourceRoots,
            'publication_roots' => $this->publicationRoots,
            'key_roots' => $this->keyRoots,
            'destination_roots' => $this->destinationRoots,
            'service_users' => $this->serviceUsers,
            'host_allowlist' => $this->hostAllowlist,
            'allowed_binaries' => $this->allowedBinaries,
            'asset_extensions' => $this->assetExtensions,
            'known_hosts_path' => $this->knownHostsPath,
            'viewer_rendition' => $this->viewerRendition,
            'viewer_distribution' => $this->viewerDistribution,
            'supply_delivery' => $this->supplyDelivery,
        ];
    }
}
