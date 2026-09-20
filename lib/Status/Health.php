<?php

declare(strict_types=1);

namespace LofAudioSupply\Status;

use LofAudioSupply\Config\Settings;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Support\Redactor;

/**
 * The machine-readable health record.
 *
 * Written to <publication root>/health.json after every run. It is designed to
 * be *read* by lof-core later; this component never calls lof-core, never
 * pushes to it, and holds no opinion about what lof-core does with it.
 *
 * The `owns` block is part of the contract, not decoration: it states in
 * machine-readable form that audio supply does not own browser authorisation,
 * playback synchronisation, listener statistics, show decisions, or physical
 * speaker authority, so a consumer cannot mistake this record for any of those.
 */
final class Health
{
    public const VERSION = 1;
    public const STATE_OK = 'ok';
    public const STATE_IDLE = 'idle';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_FAILED = 'failed';

    /**
     * @param array<string,mixed> $lastRun
     * @return array<string,mixed>
     */
    public static function build(
        Generations $generations,
        ?Settings $settings,
        string $state,
        array $lastRun,
        ?string $settingsError = null
    ): array {
        $current = $generations->currentGeneration();
        $previous = $generations->previousGeneration();

        $currentSummary = null;
        if ($current !== null && is_file($generations->manifestPath($current))) {
            try {
                $manifest = Manifest::readFrom($generations->manifestPath($current));
                $currentSummary = [
                    'generation' => $current,
                    'created_utc' => $manifest->createdUtc,
                    'asset_count' => $manifest->assetCount(),
                    'total_bytes' => $manifest->totalBytes(),
                    'manifest_sha256' => $manifest->digest(),
                    'content_sha256' => $manifest->contentDigest(),
                ];
            } catch (\Throwable $e) {
                $currentSummary = ['generation' => $current, 'manifest_error' => 'unreadable'];
                $state = self::STATE_DEGRADED;
            }
        }

        $record = [
            'health_version' => self::VERSION,
            'component' => 'fpp-lof-audio-sync',
            'role' => 'media-supply',
            'generated_utc' => Manifest::nowUtc(),
            'state' => $state,
            'publication_root' => $generations->root(),
            'current' => $currentSummary,
            'previous_generation' => $previous,
            'generation_count' => count($generations->listGenerations()),
            'staging_pending' => $generations->listStaging(),
            'quarantine' => [
                'generation_count' => count($generations->listQuarantine()),
                'generations' => array_slice($generations->listQuarantine(), -10),
                'deletion_policy' => 'retention-only; synchronisation never deletes',
            ],
            'history' => array_slice($generations->history()['entries'], 0, 10),
            'last_run' => $lastRun,
            'settings' => $settings === null ? null : $settings->toPublicArray(),
            'settings_error' => $settingsError,
            'free_bytes' => Fs::freeBytes($generations->root()),
            // Scope contract. Audio supply owns media delivery and nothing else.
            'owns' => [
                'media_supply' => true,
                'manifest_integrity' => true,
                'browser_authorization' => false,
                'playback_synchronization' => false,
                'listener_statistics' => false,
                'show_decisions' => false,
                'physical_speaker_authority' => false,
            ],
        ];

        return Redactor::structure($record);
    }

    /**
     * The record a reader should be shown *now*.
     *
     * Live facts - active generation, quarantine, free space, the settings
     * actually in force - are recomputed, while the outcome and state of the
     * last run are carried forward from the persisted record. Replaying the
     * stored document wholesale would report the configuration as it was at
     * the last publish, which is not what "status" means.
     *
     * @return array<string,mixed>
     */
    public static function current(Generations $generations, ?Settings $settings, ?string $settingsError = null): array
    {
        $stored = self::read($generations);
        $lastRun = is_array($stored['last_run'] ?? null) ? $stored['last_run'] : ['outcome' => 'never_run'];
        $state = is_string($stored['state'] ?? null) ? $stored['state'] : self::STATE_IDLE;

        return self::build($generations, $settings, $state, $lastRun, $settingsError);
    }

    /** @param array<string,mixed> $record */
    public static function write(Generations $generations, array $record): void
    {
        Fs::writeFileAtomic($generations->healthPath(), Json::pretty($record), 0644);
    }

    /** @return array<string,mixed>|null */
    public static function read(Generations $generations): ?array
    {
        if (!is_file($generations->healthPath())) {
            return null;
        }
        try {
            return Json::readFile($generations->healthPath());
        } catch (\Throwable $e) {
            return null;
        }
    }
}
