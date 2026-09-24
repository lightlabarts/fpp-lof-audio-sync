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
use LofAudioSupply\Viewer\DistributionConfig;
use LofAudioSupply\Viewer\FfmpegEncoder;
use LofAudioSupply\Viewer\LocalDestinationVerifier;
use LofAudioSupply\Viewer\M4aInspector;
use LofAudioSupply\Viewer\SupplyMasterSource;
use LofAudioSupply\Viewer\ViewerConfig;
use LofAudioSupply\Viewer\ViewerDistributor;
use LofAudioSupply\Viewer\ViewerPublisher;

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

    public function viewerEnabled(): bool
    {
        return ($this->policy->viewerRendition['enabled'] ?? false) === true;
    }

    public function viewerConfig(Settings $settings): ViewerConfig
    {
        return ViewerConfig::fromPolicy($this->policy, $settings);
    }

    /**
     * The viewer-rendition lane, assembled from policy alone: the allowlisted
     * encoder through the one ProcessRunner, the structural inspector, and the
     * verified media-supply generation as the only source of masters.
     */
    public function viewerPublisher(Settings $settings): ViewerPublisher
    {
        $config = $this->viewerConfig($settings);
        $supply = $this->generations($settings);

        return new ViewerPublisher(
            $config,
            $supply,
            $settings,
            new SupplyMasterSource($supply),
            new FfmpegEncoder($this->processRunner(), $config->encoder, $config->encodeTimeoutSeconds),
            new M4aInspector()
        );
    }

    /**
     * Viewer-publication delivery. Local mode reuses LocalTransport and reads
     * the destination back; remote mode gets no verifier, so the distributor
     * refuses it before any transfer (see ViewerDistributor).
     */
    public function viewerDistributor(Settings $settings): ViewerDistributor
    {
        $viewer = $this->viewerConfig($settings);
        $config = DistributionConfig::fromPolicy($this->policy, $settings, $viewer);
        $verifier = $config->mode === DistributionConfig::MODE_LOCAL
            ? new LocalDestinationVerifier($config->viewerDestination, $config->privateDestination)
            : null;

        return new ViewerDistributor($config, $viewer, $this->generations($settings), $settings, $this->localTransport(), $verifier);
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
