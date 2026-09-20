<?php

declare(strict_types=1);

namespace LofAudioSupply\Config;

use LofAudioSupply\Support\Redactor;

/**
 * Validated configuration. An instance of this class can only be produced by
 * Validator, so any code holding one is holding values that already passed
 * every type, bound, and allowlist check.
 */
final class Settings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $sourcePath,
        public readonly string $publicationRoot,
        public readonly bool $distributionEnabled,
        public readonly string $destinationHost,
        public readonly int $destinationPort,
        public readonly string $destinationPath,
        public readonly string $serviceUser,
        public readonly string $sshKeyPath,
        public readonly int $syncIntervalSeconds,
        public readonly int $retainGenerations,
        public readonly int $quarantineRetentionDays
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'settings_version' => 2,
            'enabled' => $this->enabled,
            'source_path' => $this->sourcePath,
            'publication_root' => $this->publicationRoot,
            'distribution_enabled' => $this->distributionEnabled,
            'destination_host' => $this->destinationHost,
            'destination_port' => $this->destinationPort,
            'destination_path' => $this->destinationPath,
            'service_user' => $this->serviceUser,
            'ssh_key_path' => $this->sshKeyPath,
            'sync_interval_seconds' => $this->syncIntervalSeconds,
            'retain_generations' => $this->retainGenerations,
            'quarantine_retention_days' => $this->quarantineRetentionDays,
        ];
    }

    /**
     * Shape used by read-only surfaces.
     *
     * The key path is reported as a presence flag and a basename only: the
     * operator needs to know a key is configured, nobody needs the contents and
     * nobody browsing the status page needs the full on-disk location.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        $public = $this->toArray();
        unset($public['ssh_key_path']);
        $public['ssh_key_configured'] = $this->sshKeyPath !== '';
        $public['ssh_key_name'] = $this->sshKeyPath === '' ? '' : basename($this->sshKeyPath);

        return Redactor::structure($public);
    }
}
