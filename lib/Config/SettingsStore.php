<?php

declare(strict_types=1);

namespace LofAudioSupply\Config;

use LofAudioSupply\LofAudioException;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\Json;

/**
 * Reads and writes settings.json.
 *
 * Validation happens on the way *in* and on the way *out*: a file that was
 * edited by hand, or left over from the pre-hardening plugin, is re-checked
 * before any of its values can reach a process argument. A settings file that
 * no longer satisfies policy is reported, not silently repaired.
 */
final class SettingsStore
{
    private string $path;
    private Validator $validator;

    public function __construct(string $path, ?Validator $validator = null)
    {
        $this->path = $path;
        $this->validator = $validator ?? new Validator();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function validator(): Validator
    {
        return $this->validator;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Load and re-validate. Missing file yields policy defaults.
     */
    public function load(): Settings
    {
        if (!$this->exists()) {
            return $this->validator->validate($this->defaultsArray());
        }
        $raw = Json::readFile($this->path);

        return $this->validator->validate($this->migrate($raw));
    }

    /**
     * Load without throwing, so a status page can still render after a bad edit.
     *
     * @return array{0: Settings|null, 1: string|null}
     */
    public function loadTolerant(): array
    {
        try {
            return [$this->load(), null];
        } catch (LofAudioException $e) {
            return [null, $e->code()];
        }
    }

    public function save(Settings $settings): void
    {
        // 0640 with the plugin's group: the web user must read it, nobody else
        // on the box needs to.
        Fs::writeFileAtomic($this->path, Json::pretty($settings->toArray()), 0640);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function migrate(array $raw): array
    {
        $version = isset($raw['settings_version']) && is_int($raw['settings_version']) ? $raw['settings_version'] : 1;
        if ($version >= 2) {
            return $raw;
        }

        // Version 1 was the lsyncd-era file. Its remote-facing values are
        // carried over only as *candidates*; the validator still has to approve
        // them, and distribution starts disarmed so an upgrade never silently
        // resumes pushing to a host under the old, unchecked rules.
        $migrated = $this->defaultsArray();
        foreach (['destination_host', 'destination_path', 'source_path'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $migrated[$key] = trim($raw[$key]);
            }
        }
        if (isset($raw['ssh_user']) && is_string($raw['ssh_user']) && trim($raw['ssh_user']) !== '') {
            $migrated['service_user'] = trim($raw['ssh_user']);
        }
        $migrated['enabled'] = false;
        $migrated['distribution_enabled'] = false;
        $migrated['settings_version'] = 2;

        return $migrated;
    }

    /** @return array<string,mixed> */
    public function defaultsArray(): array
    {
        $policy = $this->validator->policy();

        return [
            'settings_version' => 2,
            'enabled' => false,
            'source_path' => $policy->defaultSourcePath(),
            'publication_root' => $policy->defaultPublicationRoot(),
            'distribution_enabled' => false,
            'destination_host' => '',
            'destination_port' => 22,
            'destination_path' => $policy->defaultDestinationPath(),
            'service_user' => $policy->defaultServiceUser(),
            'ssh_key_path' => '',
            'sync_interval_seconds' => 60,
            'retain_generations' => 5,
            'quarantine_retention_days' => 30,
        ];
    }
}
