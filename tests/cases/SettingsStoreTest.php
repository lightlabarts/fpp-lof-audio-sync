<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Support\Json;
use LofTest\Estate;
use LofTest\TestCase;

final class SettingsStoreTest extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
    }

    public function testDefaultsAreThemselvesValid(): void
    {
        $store = $this->estate->store();
        $this->assertFalse($store->exists());

        $settings = $store->load();
        $this->assertFalse($settings->enabled, 'A fresh install must not publish until asked to.');
        $this->assertFalse($settings->distributionEnabled, 'A fresh install must not be armed to push anywhere.');
        $this->assertSame($this->estate->musicRoot, $settings->sourcePath);
        $this->assertSame('', $settings->destinationHost);
        $this->assertSame('', $settings->sshKeyPath);
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        $store = $this->estate->store();
        $settings = $this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'destination_port' => 2222,
            'ssh_key_path' => $this->estate->makeKey(),
            'service_user' => 'www-data',
        ]);
        $store->save($settings);

        $reloaded = $store->load();
        $this->assertSame($settings->toArray(), $reloaded->toArray());
        $this->assertSame(2, Json::readFile($store->path())['settings_version']);
    }

    public function testLegacyVersionOneFilesAreMigratedDisarmed(): void
    {
        // The lsyncd-era file, exactly as the old plugin wrote it.
        $legacy = [
            'enabled' => true,
            'destination_host' => '10.9.7.50',
            'destination_path' => '/var/www/lof-audio',
            'ssh_user' => 'www-data',
            'sync_delay' => 1,
            'source_path' => $this->estate->musicRoot,
        ];
        $store = $this->estate->store();
        file_put_contents($store->path(), Json::pretty($legacy));

        $settings = $store->load();

        // Carried over.
        $this->assertSame('10.9.7.50', $settings->destinationHost);
        $this->assertSame('/var/www/lof-audio', $settings->destinationPath);
        $this->assertSame('www-data', $settings->serviceUser);
        $this->assertSame($this->estate->musicRoot, $settings->sourcePath);
        // Deliberately disarmed: an upgrade must not silently resume pushing
        // under rules the old file was never checked against.
        $this->assertFalse($settings->enabled);
        $this->assertFalse($settings->distributionEnabled);
        $this->assertSame('', $settings->sshKeyPath);
    }

    public function testLegacyValuesThatViolatePolicyAreRefusedNotSilentlyKept(): void
    {
        $legacy = [
            'enabled' => true,
            'destination_host' => '8.8.8.8',
            'destination_path' => '/etc',
            'ssh_user' => 'root',
            'source_path' => '/etc',
        ];
        $store = $this->estate->store();
        file_put_contents($store->path(), Json::pretty($legacy));

        $this->assertRefused(null, static fn () => $store->load());

        // A status page can still render, with the reason.
        [$settings, $code] = $store->loadTolerant();
        $this->assertSame(null, $settings);
        $this->assertSame('path.not_approved', $code);
    }

    public function testAMalformedFileIsReportedRatherThanCrashing(): void
    {
        $store = $this->estate->store();
        file_put_contents($store->path(), '{not json at all');

        [$settings, $code] = $store->loadTolerant();
        $this->assertSame(null, $settings);
        $this->assertSame('json.malformed', $code);
    }

    public function testUnknownKeysAreIgnoredRatherThanPassedThrough(): void
    {
        $store = $this->estate->store();
        $raw = $store->defaultsArray();
        $raw['rsync_extra_args'] = '--delete';
        $raw['command'] = '; id';
        file_put_contents($store->path(), Json::pretty($raw));

        $settings = $store->load();
        $encoded = Json::pretty($settings->toArray());

        $this->assertStringNotContains('--delete', $encoded);
        $this->assertStringNotContains('rsync_extra_args', $encoded);
        $this->assertStringNotContains('; id', $encoded);
    }

    public function testWritesAreAtomicAndLeaveNoTemporaryFile(): void
    {
        $store = $this->estate->store();
        $store->save($this->estate->settings());
        $store->save($this->estate->settings(['destination_port' => 2200]));

        $strays = array_values(array_filter(
            (array) scandir($this->estate->pluginDir),
            static fn (string $entry): bool => strpos($entry, '.tmp.') !== false
        ));
        $this->assertCount(0, $strays);
        $this->assertSame(2200, $store->load()->destinationPort);
    }
}
