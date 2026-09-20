<?php

declare(strict_types=1);

namespace LofAudioSupply\Transport;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Config\Settings;
use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Process\ProcessRunner;
use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\TransportException;

/**
 * The distribution leg: push an already-verified generation to the remote.
 *
 * Everything hostile about the old implementation is structurally impossible
 * here:
 *
 *   - the argv is a fixed list of literals plus a handful of validated values,
 *     assembled as an array and handed to execvp; there is no command string;
 *   - `--delete` is absent and there is no code path that can add it;
 *   - the file set is passed as a NUL-separated `--files-from` list, so an
 *     asset name can never be reinterpreted as an option or a second path;
 *   - `--secluded-args` stops the remote shell from re-splitting remote names;
 *   - the ssh side pins BatchMode, IdentitiesOnly, a known_hosts file, and
 *     StrictHostKeyChecking, so there is no password prompt, no agent, and no
 *     trust-on-first-use.
 *
 * buildRsyncArgv() and buildSshTransportArgument() are public specifically so a
 * test can assert the exact vector produced for a hostile input without a
 * process ever being started.
 */
final class SshRsyncTransport implements Transport
{
    /** Remote commands this component may ever run. Nothing dynamic. */
    public const REMOTE_PROBE = 'probe';
    private const REMOTE_COMMANDS = [
        self::REMOTE_PROBE => ['true'],
    ];

    private Settings $settings;
    private Policy $policy;
    private ProcessRunner $runner;
    private string $rsyncBinary;
    private string $sshBinary;

    public function __construct(
        Settings $settings,
        Policy $policy,
        ProcessRunner $runner,
        string $rsyncBinary = '/usr/bin/rsync',
        string $sshBinary = '/usr/bin/ssh'
    ) {
        $this->settings = $settings;
        $this->policy = $policy;
        $this->runner = $runner;
        $this->rsyncBinary = $rsyncBinary;
        $this->sshBinary = $sshBinary;
    }

    public function name(): string
    {
        return 'ssh-rsync';
    }

    /**
     * rsync splits the -e value on whitespace itself and execs the result
     * directly, but a path containing a space would still be torn in half, so
     * anything that reaches that argument is required to be whitespace-free.
     */
    private function assertNoWhitespace(string $value, string $field): void
    {
        if (preg_match('/\s/', $value) === 1) {
            throw new PolicyViolationException(
                'transport.whitespace_in_argument',
                'Value must not contain whitespace.',
                ['field' => $field]
            );
        }
    }

    public function buildSshTransportArgument(): string
    {
        $key = $this->settings->sshKeyPath;
        if ($key === '') {
            throw new PolicyViolationException('transport.no_key', 'No SSH key is configured.');
        }
        $this->assertNoWhitespace($key, 'ssh_key_path');
        SafePath::assertSafeValue($key, 'ssh_key_path');

        $knownHosts = $this->policy->knownHostsPath;
        $this->assertNoWhitespace($knownHosts, 'known_hosts_path');

        $port = $this->settings->destinationPort;
        if ($port < 1 || $port > 65535) {
            throw new PolicyViolationException('transport.bad_port', 'Port is out of range.');
        }

        $parts = [
            $this->sshBinary,
            '-p', (string) $port,
            '-i', $key,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'PasswordAuthentication=no',
            '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . $knownHosts,
            '-o', 'ClearAllForwardings=yes',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=4',
        ];
        foreach ($parts as $part) {
            $this->assertNoWhitespace($part, 'ssh_transport');
            if (preg_match('/[;&|`$(){}<>\\\\\'"*?\[\]!#~]/', $part) === 1) {
                throw new PolicyViolationException('transport.metacharacter', 'Transport argument contains a shell metacharacter.');
            }
        }

        return implode(' ', $parts);
    }

    public function remoteSpec(string $destinationRoot): string
    {
        $user = $this->settings->serviceUser;
        $host = $this->settings->destinationHost;
        if ($user === '' || $host === '') {
            throw new PolicyViolationException('transport.incomplete_remote', 'Remote user and host are both required.');
        }
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1) {
            throw new PolicyViolationException('transport.bad_user', 'Remote user is not a valid POSIX user name.');
        }
        if (preg_match('/[^A-Za-z0-9.:\[\]-]/', $host) === 1) {
            throw new PolicyViolationException('transport.bad_host', 'Remote host contains an unexpected character.');
        }
        $root = rtrim(SafePath::normalizeAbsolute($destinationRoot, 'destination_root'), '/');
        if (strpos($root, ':') !== false) {
            // rsync reads the first colon as the host/path separator.
            throw new PolicyViolationException('transport.colon_in_path', 'Destination path must not contain a colon.');
        }
        $bracketedHost = (strpos($host, ':') !== false && $host[0] !== '[') ? '[' . $host . ']' : $host;

        return $user . '@' . $bracketedHost . ':' . $root . '/';
    }

    /**
     * @return list<string>
     */
    public function buildRsyncArgv(string $sourceRoot, string $destinationRoot, string $fileListPath): array
    {
        $source = rtrim(SafePath::normalizeAbsolute($sourceRoot, 'transfer_source'), '/') . '/';
        SafePath::assertSafeValue($fileListPath, 'file_list');

        return [
            $this->rsyncBinary,
            '--archive',
            '--no-owner',
            '--no-group',
            '--omit-dir-times',
            // --archive implies --perms, so --chmod decides the landed modes.
            '--chmod=D750,F640',
            // Resume support: a killed transfer leaves bytes in a side
            // directory, never a half-written file at the real name.
            '--partial',
            '--partial-dir=.lof-partial',
            // The manifest is the local proof; --checksum is what makes the
            // remote copy provably equal to it rather than merely same-sized.
            '--checksum',
            // Old name for --secluded-args, understood by the rsync in FPP 8
            // through 10. Stops the remote shell re-splitting remote names.
            '--protect-args',
            '--from0',
            '--files-from=' . $fileListPath,
            '--timeout=' . $this->policy->transferTimeoutSeconds,
            '--rsh=' . $this->buildSshTransportArgument(),
            // No --delete. Removal is the quarantine step's decision, never a
            // transfer's side effect.
            '--',
            $source,
            $this->remoteSpec($destinationRoot),
        ];
    }

    /** @return list<string> */
    public function buildProbeArgv(string $command = self::REMOTE_PROBE): array
    {
        if (!isset(self::REMOTE_COMMANDS[$command])) {
            throw new PolicyViolationException('transport.unknown_remote_command', 'Remote command is not on the allowlist.');
        }
        $key = $this->settings->sshKeyPath;
        if ($key === '') {
            throw new PolicyViolationException('transport.no_key', 'No SSH key is configured.');
        }

        $argv = [
            $this->sshBinary,
            '-p', (string) $this->settings->destinationPort,
            '-i', $key,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'PasswordAuthentication=no',
            '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . $this->policy->knownHostsPath,
            '-o', 'ClearAllForwardings=yes',
            '-o', 'ConnectTimeout=10',
            '-n',
            $this->settings->serviceUser . '@' . $this->settings->destinationHost,
            '--',
        ];

        return array_merge($argv, self::REMOTE_COMMANDS[$command]);
    }

    /**
     * Write the NUL-separated file list rsync will read.
     *
     * @param list<string> $relativePaths
     */
    public function writeFileList(string $path, array $relativePaths): void
    {
        $payload = '';
        foreach ($relativePaths as $relative) {
            SafePath::assertRelative($relative);
            $payload .= $relative . "\0";
        }
        Fs::writeFileAtomic($path, $payload, 0600);
    }

    /** @param list<string> $relativePaths */
    public function transfer(string $sourceRoot, string $destinationRoot, array $relativePaths): TransferReport
    {
        if (!$this->settings->distributionEnabled) {
            throw new PolicyViolationException('transport.distribution_disarmed', 'Distribution is not enabled in settings.');
        }
        $listPath = sys_get_temp_dir() . '/lof-audio-files.' . bin2hex(random_bytes(8));
        $this->writeFileList($listPath, $relativePaths);
        try {
            $argv = $this->buildRsyncArgv($sourceRoot, $destinationRoot, $listPath);
            $result = $this->runner->run($argv, $this->policy->transferTimeoutSeconds);
        } finally {
            @unlink($listPath);
        }

        if (!$result->succeeded()) {
            throw new TransportException(
                $result->timedOut ? 'transport.timeout' : 'transport.rsync_failed',
                'Remote transfer did not complete.',
                ['exit_code' => $result->exitCode]
            );
        }

        return new TransferReport(
            $this->name(),
            count($relativePaths),
            0,
            $result->durationSeconds,
            [],
            $result->stdout
        );
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return [
            'transport' => $this->name(),
            'host' => $this->settings->destinationHost,
            'port' => $this->settings->destinationPort,
            'user' => $this->settings->serviceUser,
            'destination_path' => $this->settings->destinationPath,
            'ssh_key_configured' => $this->settings->sshKeyPath !== '',
            'deletes_remote_files' => false,
        ];
    }
}
