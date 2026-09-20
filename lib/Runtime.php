<?php

declare(strict_types=1);

namespace LofAudioSupply;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\Config\SettingsStore;
use LofAudioSupply\Config\Validator;
use LofAudioSupply\Process\ProcessRunner;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Publisher;
use LofAudioSupply\Transport\LocalTransport;
use LofAudioSupply\Transport\SshRsyncTransport;

/**
 * Assembles the component from a plugin directory.
 *
 * One place decides where settings.json and policy.json live, so the CLI, the
 * web page, and the tests can never drift into using different rules.
 */
final class Runtime
{
    private string $pluginDir;
    private Policy $policy;
    private SettingsStore $store;

    public function __construct(?string $pluginDir = null, ?string $settingsPath = null, ?string $policyPath = null)
    {
        $this->pluginDir = rtrim($pluginDir ?? self::defaultPluginDir(), '/');
        $policyFile = $policyPath ?? self::envPath('LOF_AUDIO_POLICY') ?? $this->pluginDir . '/policy.json';
        $this->policy = Policy::load($policyFile);
        $settingsFile = $settingsPath ?? self::envPath('LOF_AUDIO_SETTINGS') ?? $this->pluginDir . '/settings.json';
        $this->store = new SettingsStore($settingsFile, new Validator($this->policy));
    }

    private static function envPath(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public static function defaultPluginDir(): string
    {
        return dirname(__DIR__);
    }

    public function pluginDir(): string
    {
        return $this->pluginDir;
    }

    public function policy(): Policy
    {
        return $this->policy;
    }

    public function store(): SettingsStore
    {
        return $this->store;
    }

    public function generations(Settings $settings): Generations
    {
        return new Generations($settings->publicationRoot);
    }

    public function localTransport(): LocalTransport
    {
        return new LocalTransport($this->policy->maxAssetBytes);
    }

    public function publisher(Settings $settings): Publisher
    {
        return new Publisher($settings, $this->policy, $this->localTransport(), $this->generations($settings));
    }

    public function processRunner(): ProcessRunner
    {
        return new ProcessRunner($this->policy->allowedBinaries);
    }

    public function remoteTransport(Settings $settings): SshRsyncTransport
    {
        $rsync = $this->binaryNamed('rsync');
        $ssh = $this->binaryNamed('ssh');

        return new SshRsyncTransport($settings, $this->policy, $this->processRunner(), $rsync, $ssh);
    }

    private function binaryNamed(string $name): string
    {
        foreach ($this->policy->allowedBinaries as $binary) {
            if (basename($binary) === $name) {
                return $binary;
            }
        }

        throw new PolicyViolationException('runtime.binary_not_allowed', 'Required binary is not on the policy allowlist.', ['binary' => $name]);
    }
}
