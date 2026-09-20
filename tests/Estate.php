<?php
/**
 * Builds a temporary stand-in for the FPP 10 media estate.
 *
 * Every test that touches the filesystem goes through here, so the suite never
 * reads or writes a real FPP path, a real key, or a real destination.
 */

declare(strict_types=1);

namespace LofTest;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\Config\SettingsStore;
use LofAudioSupply\Config\Validator;
use LofAudioSupply\Publish\Generations;
use LofAudioSupply\Publish\Publisher;
use LofAudioSupply\Transport\LocalTransport;
use LofAudioSupply\Transport\Transport;

final class Estate
{
    public string $root;
    public string $mediaRoot;
    public string $musicRoot;
    public string $uploadRoot;
    public string $configRoot;
    public string $publicationRoot;
    public string $pluginDir;
    public Policy $policy;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        // Mirrors the real FPP 10 layout under a temporary prefix.
        $this->mediaRoot = $this->root . '/home/fpp/media';
        $this->musicRoot = $this->mediaRoot . '/music';
        $this->uploadRoot = $this->mediaRoot . '/upload';
        $this->configRoot = $this->mediaRoot . '/config';
        $this->publicationRoot = $this->mediaRoot . '/lof-audio-supply';
        $this->pluginDir = $this->root . '/opt/fpp/plugins/fpp-lof-audio-sync';

        foreach ([$this->musicRoot, $this->uploadRoot, $this->configRoot, $this->publicationRoot, $this->pluginDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not build estate directory: ' . $dir);
            }
        }

        $this->policy = new Policy([
            'source_roots' => [$this->musicRoot, $this->uploadRoot],
            'publication_roots' => [$this->publicationRoot],
            'key_roots' => [$this->configRoot],
            'destination_roots' => ['/var/www/lof-audio', '/srv/lof-audio'],
            'known_hosts_path' => $this->configRoot . '/lof-audio-known_hosts',
            'allowed_binaries' => ['/usr/bin/rsync', '/usr/bin/ssh'],
        ]);
    }

    /** @param array<string,mixed> $overrides */
    public function settings(array $overrides = []): Settings
    {
        $base = [
            'enabled' => true,
            'source_path' => $this->musicRoot,
            'publication_root' => $this->publicationRoot,
            'distribution_enabled' => false,
            'destination_host' => '',
            'destination_port' => 22,
            'destination_path' => '/var/www/lof-audio',
            'service_user' => 'lof-audio',
            'ssh_key_path' => '',
            'sync_interval_seconds' => 5,
            'retain_generations' => 3,
            'quarantine_retention_days' => 30,
        ];

        return (new Validator($this->policy))->validate($overrides + $base);
    }

    public function store(): SettingsStore
    {
        return new SettingsStore($this->pluginDir . '/settings.json', new Validator($this->policy));
    }

    public function generations(?Settings $settings = null): Generations
    {
        return new Generations(($settings ?? $this->settings())->publicationRoot);
    }

    public function publisher(?Settings $settings = null, ?Transport $transport = null): Publisher
    {
        $settings = $settings ?? $this->settings();

        return new Publisher(
            $settings,
            $this->policy,
            $transport ?? new LocalTransport($this->policy->maxAssetBytes),
            new Generations($settings->publicationRoot)
        );
    }

    /** Create an approved-looking SSH key file with correct 0600 mode. */
    public function makeKey(string $name = 'lof-audio-id_ed25519'): string
    {
        $path = $this->configRoot . '/' . $name;
        file_put_contents($path, "-----BEGIN OPENSSH PRIVATE KEY-----\nZmFrZQ==\n-----END OPENSSH PRIVATE KEY-----\n");
        chmod($path, 0600);

        return $path;
    }

    /** @param array<string,string> $files relative path => contents */
    public function seedMusic(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $path = $this->musicRoot . '/' . $relative;
            $dir = \dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create fixture directory.');
            }
            file_put_contents($path, $contents);
        }
    }
}
