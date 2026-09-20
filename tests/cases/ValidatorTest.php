<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Config\HostAllowlist;
use LofAudioSupply\Config\Validator;
use LofTest\Estate;
use LofTest\TestCase;

final class ValidatorTest extends TestCase
{
    private Estate $estate;
    private Validator $validator;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
        $this->validator = new Validator($this->estate->policy);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function base(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true,
            'source_path' => $this->estate->musicRoot,
            'publication_root' => $this->estate->publicationRoot,
            'distribution_enabled' => false,
            'destination_host' => '',
            'destination_port' => 22,
            'destination_path' => '/var/www/lof-audio',
            'service_user' => 'lof-audio',
            'ssh_key_path' => '',
            'sync_interval_seconds' => 60,
            'retain_generations' => 5,
            'quarantine_retention_days' => 30,
        ];
    }

    public function testAcceptsAWellFormedConfiguration(): void
    {
        $settings = $this->validator->validate($this->base());
        $this->assertSame($this->estate->musicRoot, $settings->sourcePath);
        $this->assertSame('lof-audio', $settings->serviceUser);
        $this->assertSame(22, $settings->destinationPort);
        $this->assertTrue($settings->enabled);
    }

    public function testIntegersAreNotSilentlyCoerced(): void
    {
        // (int)"22; rm -rf /" is 22 in PHP. That must never be the behaviour.
        $this->assertRefused(
            'type.not_integer',
            fn () => $this->validator->validate($this->base(['destination_port' => '22; rm -rf /']))
        );
        $this->assertRefused(
            'type.not_integer',
            fn () => $this->validator->validate($this->base(['sync_interval_seconds' => '60 && curl evil']))
        );
        $this->assertRefused(
            'type.not_integer',
            fn () => $this->validator->validate($this->base(['retain_generations' => 1.5]))
        );
        $this->assertRefused(
            'type.not_integer',
            fn () => $this->validator->validate($this->base(['destination_port' => ['22']]))
        );
    }

    public function testNumericBoundsAreEnforced(): void
    {
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['destination_port' => 0])));
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['destination_port' => 65536])));
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['destination_port' => -1])));
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['sync_interval_seconds' => 1])));
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['retain_generations' => 0])));
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['retain_generations' => 1000])));
        $this->assertRefused('bound.out_of_range', fn () => $this->validator->validate($this->base(['quarantine_retention_days' => 0])));
    }

    public function testBooleansMustBeBooleans(): void
    {
        $this->assertSame(true, $this->validator->validate($this->base(['enabled' => '1']))->enabled);
        $this->assertSame(false, $this->validator->validate($this->base(['enabled' => '0']))->enabled);
        $this->assertRefused('type.not_bool', fn () => $this->validator->validate($this->base(['enabled' => 'maybe'])));
    }

    public function testSourcePathMustBeInsideAnApprovedRoot(): void
    {
        $this->assertRefused('path.not_approved', fn () => $this->validator->validate($this->base(['source_path' => '/etc'])));
        $this->assertRefused(
            'path.not_approved',
            fn () => $this->validator->validate($this->base(['source_path' => $this->estate->musicRoot . '/../../../../etc']))
        );
        // A sibling whose name merely starts the same way is not inside.
        $this->assertRefused(
            'path.not_approved',
            fn () => $this->validator->validate($this->base(['source_path' => $this->estate->musicRoot . '-evil']))
        );
        $this->assertRefused('path.missing', fn () => $this->validator->validate($this->base(['source_path' => $this->estate->musicRoot . '/absent'])));
    }

    public function testSourcePathCannotBeASymlinkOutOfTheEstate(): void
    {
        $outside = $this->estate->root . '/outside';
        mkdir($outside, 0755, true);
        symlink($outside, $this->estate->musicRoot . '/escape');

        $this->assertRefused(
            'path.symlink_escape',
            fn () => $this->validator->validate($this->base(['source_path' => $this->estate->musicRoot . '/escape']))
        );
    }

    public function testPublicationRootMayNotOverlapTheSource(): void
    {
        $this->assertRefused(
            'path.not_approved',
            fn () => $this->validator->validate($this->base(['publication_root' => $this->estate->musicRoot]))
        );
    }

    public function testDestinationPathMustBeInsideAnApprovedRemoteRoot(): void
    {
        $this->assertRefused('path.not_approved', fn () => $this->validator->validate($this->base(['destination_path' => '/etc/cron.d'])));
        $this->assertRefused('path.not_approved', fn () => $this->validator->validate($this->base(['destination_path' => '/var/www/lof-audio/../../../root'])));
        $this->assertRefused('path.not_approved', fn () => $this->validator->validate($this->base(['destination_path' => '/var/www/lof-audio-evil'])));

        $ok = $this->validator->validate($this->base(['destination_path' => '/var/www/lof-audio/generation']));
        $this->assertSame('/var/www/lof-audio/generation', $ok->destinationPath);
    }

    public function testServiceUserIsAFixedPolicy(): void
    {
        $this->assertRefused('user.not_approved', fn () => $this->validator->validate($this->base(['service_user' => 'root'])));
        $this->assertRefused('user.not_approved', fn () => $this->validator->validate($this->base(['service_user' => 'nobody'])));
        $this->assertRefused('user.bad_grammar', fn () => $this->validator->validate($this->base(['service_user' => 'www-data;id'])));
        $this->assertRefused('user.bad_grammar', fn () => $this->validator->validate($this->base(['service_user' => 'a b'])));
        $this->assertSame('www-data', $this->validator->validate($this->base(['service_user' => 'www-data']))->serviceUser);
    }

    public function testKeyPathMustBeApprovedAndTightlyPermissioned(): void
    {
        $this->assertRefused(
            'path.not_approved',
            fn () => $this->validator->validate($this->base(['ssh_key_path' => '/root/.ssh/id_rsa']))
        );
        $this->assertRefused(
            'path.not_approved',
            fn () => $this->validator->validate($this->base(['ssh_key_path' => $this->estate->configRoot . '/../../../etc/shadow']))
        );

        $loose = $this->estate->configRoot . '/loose-key';
        $this->writeFile($loose, 'k');
        chmod($loose, 0644);
        if ($this->runningAsRoot()) {
            $this->skip('mode checks are meaningless as root');
        }
        $this->assertRefused('key.permissive_mode', fn () => $this->validator->validate($this->base(['ssh_key_path' => $loose])));

        $tight = $this->estate->makeKey();
        $this->assertSame($tight, $this->validator->validate($this->base(['ssh_key_path' => $tight]))->sshKeyPath);
    }

    public function testHostMustBeSyntacticallyValidAndAllowlisted(): void
    {
        foreach (['10.9.7.102', '192.168.1.50', '127.0.0.1'] as $host) {
            $this->assertSame($host, $this->validator->validate($this->base(['destination_host' => $host]))->destinationHost);
        }
        $this->assertSame(
            'audio.lightsonfalcon.com',
            $this->validator->validate($this->base(['destination_host' => 'audio.lightsonfalcon.com']))->destinationHost
        );

        // Public address, syntactically fine, not approved.
        $this->assertRefused('host.not_approved', fn () => $this->validator->validate($this->base(['destination_host' => '8.8.8.8'])));
        // Suffix look-alike.
        $this->assertRefused(
            'host.not_approved',
            fn () => $this->validator->validate($this->base(['destination_host' => 'evil-lightsonfalcon.com.attacker.net']))
        );
        // Embedded user / port / path / option.
        $this->assertRefused('host.bad_grammar', fn () => $this->validator->validate($this->base(['destination_host' => 'root@10.9.7.102'])));
        $this->assertRefused('host.bad_grammar', fn () => $this->validator->validate($this->base(['destination_host' => '10.9.7.102:2222'])));
        $this->assertRefused('host.bad_grammar', fn () => $this->validator->validate($this->base(['destination_host' => '10.9.7.102/../x'])));
        $this->assertRefused('value.option_like', fn () => $this->validator->validate($this->base(['destination_host' => '-oProxyCommand=id'])));
        $this->assertRefused('value.control_char', fn () => $this->validator->validate($this->base(['destination_host' => "10.9.7.102\nrm -rf /"])));
    }

    public function testArmingDistributionRequiresACompleteRemoteDefinition(): void
    {
        $this->assertRefused(
            'distribution.host_required',
            fn () => $this->validator->validate($this->base(['distribution_enabled' => true]))
        );
        $this->assertRefused(
            'distribution.key_required',
            fn () => $this->validator->validate($this->base(['distribution_enabled' => true, 'destination_host' => '10.9.7.102']))
        );

        $settings = $this->validator->validate($this->base([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'ssh_key_path' => $this->estate->makeKey(),
        ]));
        $this->assertTrue($settings->distributionEnabled);
    }

    public function testHostAllowlistPrimitives(): void
    {
        $this->assertTrue(HostAllowlist::inCidr('10.9.7.102', '10.0.0.0/8'));
        $this->assertFalse(HostAllowlist::inCidr('11.9.7.102', '10.0.0.0/8'));
        $this->assertTrue(HostAllowlist::inCidr('172.16.0.1', '172.16.0.0/12'));
        $this->assertFalse(HostAllowlist::inCidr('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(HostAllowlist::inCidr('::1', '::1/128'));
        $this->assertFalse(HostAllowlist::inCidr('10.0.0.1', '::1/128'), 'Address families must not cross-match.');
        // A /0 entry would approve everything; it is refused by construction.
        $this->assertFalse(HostAllowlist::inCidr('8.8.8.8', '0.0.0.0/0'));

        $this->assertTrue(HostAllowlist::isValidHostname('audio.lightsonfalcon.com'));
        $this->assertFalse(HostAllowlist::isValidHostname('-leading.example.com'));
        $this->assertFalse(HostAllowlist::isValidHostname('under_score.example.com'));
        $this->assertFalse(HostAllowlist::isValidHostname('double..dot.com'));
        $this->assertFalse(HostAllowlist::isValidHostname(str_repeat('a', 64) . '.com'));
    }

    public function testCheckReportsRatherThanThrows(): void
    {
        [$settings, $errors] = $this->validator->check($this->base(['destination_port' => 'nope']));
        $this->assertSame(null, $settings);
        $this->assertCount(1, $errors);
        $this->assertSame('destination_port', $errors[0]['field']);
        $this->assertSame('type.not_integer', $errors[0]['code']);
    }
}
