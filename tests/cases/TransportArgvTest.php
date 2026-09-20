<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\Process\ProcessRunner;
use LofAudioSupply\Transport\SshRsyncTransport;
use LofTest\Estate;
use LofTest\TestCase;

/**
 * Asserts the exact argument vector the distribution leg would use.
 *
 * No process is started anywhere in this file. The point is to prove, without
 * a live host, that the vector is a list of literals plus validated values -
 * that `--delete` is absent, that nothing can be smuggled in as an option, and
 * that the ssh side pins away every ambient source of authority.
 */
final class TransportArgvTest extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
    }

    private function transport(?Settings $settings = null, ?Policy $policy = null): SshRsyncTransport
    {
        $policy = $policy ?? $this->estate->policy;
        $settings = $settings ?? $this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'ssh_key_path' => $this->estate->makeKey(),
        ]);

        return new SshRsyncTransport($settings, $policy, new ProcessRunner($policy->allowedBinaries));
    }

    public function testRsyncVectorNeverContainsADeleteFlag(): void
    {
        $argv = $this->transport()->buildRsyncArgv($this->estate->publicationRoot, '/var/www/lof-audio', '/tmp/list');

        foreach ($argv as $arg) {
            $this->assertStringNotContains('--delete', $arg, 'No argument may request deletion.');
            $this->assertStringNotContains('--remove-source-files', $arg);
        }
        $this->assertNotContainsValue('--delete', $argv);
        $this->assertNotContainsValue('--delete-after', $argv);
        $this->assertNotContainsValue('--delete-excluded', $argv);
    }

    public function testRsyncVectorShape(): void
    {
        $argv = $this->transport()->buildRsyncArgv($this->estate->publicationRoot, '/var/www/lof-audio', '/tmp/list');

        $this->assertSame('/usr/bin/rsync', $argv[0]);
        $this->assertContainsValue('--from0', $argv, 'File names must be NUL separated.');
        $this->assertContainsValue('--files-from=/tmp/list', $argv, 'The file set is an explicit list, never a pattern.');
        $this->assertContainsValue('--protect-args', $argv, 'The remote shell must not re-split names.');
        $this->assertContainsValue('--checksum', $argv);
        $this->assertContainsValue('--partial', $argv, 'An interrupted transfer must be resumable.');
        $this->assertContainsValue('--', $argv, 'A separator must end option parsing.');

        // The two positional operands come last, after the separator.
        $separator = array_search('--', $argv, true);
        $this->assertSame(count($argv) - 3, $separator);
        $this->assertSame($this->estate->publicationRoot . '/', $argv[count($argv) - 2]);
        $this->assertSame('lof-audio@10.9.7.102:/var/www/lof-audio/', $argv[count($argv) - 1]);
    }

    public function testSshTransportArgumentPinsAwayAmbientAuthority(): void
    {
        $rsh = $this->transport()->buildSshTransportArgument();

        $this->assertStringContains('BatchMode=yes', $rsh, 'No interactive prompt may ever appear.');
        $this->assertStringContains('IdentitiesOnly=yes', $rsh, 'Only the named key may be offered.');
        $this->assertStringContains('PasswordAuthentication=no', $rsh);
        $this->assertStringContains('KbdInteractiveAuthentication=no', $rsh);
        $this->assertStringContains('StrictHostKeyChecking=yes', $rsh, 'No trust on first use.');
        $this->assertStringContains('UserKnownHostsFile=' . $this->estate->configRoot . '/lof-audio-known_hosts', $rsh);
        $this->assertStringContains('ClearAllForwardings=yes', $rsh);

        // rsync word-splits this value itself; a metacharacter must never survive.
        $this->assertSame(1, preg_match('/^[A-Za-z0-9 \/_.=:@-]+$/', $rsh), 'The -e value must be metacharacter-free: ' . $rsh);
    }

    public function testProbeUsesAFixedRemoteCommandFromAClosedList(): void
    {
        $transport = $this->transport();
        $argv = $transport->buildProbeArgv();

        $this->assertSame('/usr/bin/ssh', $argv[0]);
        $this->assertSame('true', $argv[count($argv) - 1], 'The remote command is a constant.');
        $this->assertSame('--', $argv[count($argv) - 2]);
        $this->assertSame('lof-audio@10.9.7.102', $argv[count($argv) - 3]);
        $this->assertContainsValue('-n', $argv, 'stdin must be detached.');

        $this->assertRefused(
            'transport.unknown_remote_command',
            static fn () => $transport->buildProbeArgv('rm -rf /')
        );
        $this->assertRefused(
            'transport.unknown_remote_command',
            static fn () => $transport->buildProbeArgv('probe; id')
        );
    }

    public function testEveryGeneratedArgumentPassesTheRunnersOwnScreen(): void
    {
        $runner = new ProcessRunner($this->estate->policy->allowedBinaries);
        $transport = $this->transport();

        // assertArgvAcceptable does not execute; it only screens.
        $runner->assertArgvAcceptable($transport->buildRsyncArgv($this->estate->publicationRoot, '/var/www/lof-audio', '/tmp/list'));
        $runner->assertArgvAcceptable($transport->buildProbeArgv());
    }

    public function testKeyPathWithWhitespaceIsRefused(): void
    {
        $spaced = $this->estate->configRoot . '/key with space';
        $this->writeFile($spaced, 'k');
        chmod($spaced, 0600);

        // Validation accepts the path (it is inside an approved root and 0600),
        // so the transport has to be the backstop for the -e word-splitting
        // hazard specifically.
        $settings = $this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'ssh_key_path' => $spaced,
        ]);
        $transport = $this->transport($settings);

        $this->assertRefused('transport.whitespace_in_argument', static fn () => $transport->buildSshTransportArgument());
    }

    public function testColonInADestinationPathIsRefused(): void
    {
        $transport = $this->transport();
        // rsync reads the first colon as the host separator, so a colon in the
        // path could redirect the whole transfer.
        $this->assertRefused(
            'transport.colon_in_path',
            static fn () => $transport->remoteSpec('/var/www/lof-audio:/tmp')
        );
    }

    public function testTransferRefusesWhenDistributionIsDisarmed(): void
    {
        $settings = $this->estate->settings(['distribution_enabled' => false]);
        $transport = $this->transport($settings);

        $this->assertRefused(
            'transport.distribution_disarmed',
            static fn () => $transport->transfer('/tmp', '/var/www/lof-audio', [])
        );
    }

    public function testFileListIsNulSeparatedAndValidated(): void
    {
        $transport = $this->transport();
        $listPath = $this->estate->root . '/list.bin';

        $transport->writeFileList($listPath, ['a.mp3', 'nested/b.mp3']);
        $this->assertSame("a.mp3\0nested/b.mp3\0", (string) file_get_contents($listPath));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($listPath)), -4));

        $this->assertRefused(
            'relative.dot_segment',
            static fn () => $transport->writeFileList($listPath, ['../../etc/passwd'])
        );
        $this->assertRefused(
            'value.option_like',
            static fn () => $transport->writeFileList($listPath, ['--exclude=*'])
        );
    }

    public function testIpv6DestinationIsBracketed(): void
    {
        $settings = $this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '::1',
            'ssh_key_path' => $this->estate->makeKey(),
        ]);
        $spec = $this->transport($settings)->remoteSpec('/var/www/lof-audio');
        $this->assertSame('lof-audio@[::1]:/var/www/lof-audio/', $spec);
    }
}
