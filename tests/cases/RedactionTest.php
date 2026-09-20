<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Process\ProcessRunner;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Support\Redactor;
use LofTest\Estate;
use LofTest\TestCase;

final class RedactionTest extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
    }

    public function testPrivateKeyBlocksNeverSurvive(): void
    {
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\nSECRETMATERIAL\n-----END OPENSSH PRIVATE KEY-----";
        $redacted = Redactor::text("before\n" . $pem . "\nafter");

        $this->assertStringNotContains('SECRETMATERIAL', $redacted);
        $this->assertStringNotContains('BEGIN OPENSSH PRIVATE KEY', $redacted);
        $this->assertStringContains('before', $redacted);
        $this->assertStringContains('after', $redacted);

        // A truncated block, as a killed ssh -v would emit.
        $truncated = Redactor::text("-----BEGIN RSA PRIVATE KEY-----\nMIIEow" . str_repeat('A', 60));
        $this->assertStringNotContains('MIIEow', $truncated);
    }

    public function testCredentialPatternsAreStripped(): void
    {
        $cases = [
            'password=hunter2' => 'hunter2',
            'PASSPHRASE: correct-horse' => 'correct-horse',
            'api_key = sk-live-abcdef123456' => 'sk-live-abcdef123456',
            'token: eyJhbGciOiJIUzI1NiJ9' => 'eyJhbGciOiJIUzI1NiJ9',
            'Authorization: Bearer abcdef.ghijkl' => 'abcdef.ghijkl',
            'rsync://joe:s3cret@host/module' => 's3cret',
            'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyMaterialHere user@host' => 'AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyMaterialHere',
        ];
        foreach ($cases as $input => $secret) {
            $redacted = Redactor::text($input);
            $this->assertStringNotContains($secret, $redacted, 'Leaked from: ' . $input);
            $this->assertStringContains(Redactor::PLACEHOLDER, $redacted, 'No placeholder for: ' . $input);
        }
    }

    public function testSecretNamedStringFieldsAreBlankedButFlagsAreNot(): void
    {
        $redacted = Redactor::structure([
            'password' => 'hunter2',
            'ssh_private_key' => 'MIIEow...',
            'api_token' => 'abc',
            'nested' => ['client_secret' => 'shh'],
            // Names that merely resemble a credential but hold a flag.
            'browser_authorization' => false,
            'authorization_required' => true,
            'token_count' => 12,
        ]);

        $this->assertSame(Redactor::PLACEHOLDER, $redacted['password']);
        $this->assertSame(Redactor::PLACEHOLDER, $redacted['ssh_private_key']);
        $this->assertSame(Redactor::PLACEHOLDER, $redacted['api_token']);
        $this->assertSame(Redactor::PLACEHOLDER, $redacted['nested']['client_secret']);
        $this->assertSame(false, $redacted['browser_authorization'], 'A boolean cannot be credential material.');
        $this->assertSame(true, $redacted['authorization_required']);
        $this->assertSame(12, $redacted['token_count']);
    }

    public function testProcessOutputIsStrippedOfControlBytesAndBounded(): void
    {
        $noisy = "line one\x1b[2Jline two\x00\x07 end";
        $clean = Redactor::processOutput($noisy);
        $this->assertStringNotContains("\x00", $clean);
        $this->assertStringNotContains("\x07", $clean);
        $this->assertStringNotContains("\x1b", $clean);
        $this->assertStringContains('line one', $clean);
        // Newlines survive; a log needs them.
        $this->assertStringContains("\n", Redactor::processOutput("a\nb"));

        $long = Redactor::processOutput(str_repeat('x', 20000), 100);
        $this->assertStringContains('[truncated]', $long);
        $this->assertTrue(strlen($long) < 200);
    }

    public function testCapturedChildOutputIsRedactedAutomatically(): void
    {
        if (!is_executable('/bin/echo')) {
            $this->skip('/bin/echo is not available on this host');
        }
        $runner = new ProcessRunner(['/bin/echo']);
        $result = $runner->run(['/bin/echo', 'password=hunter2'], 10);

        $this->assertStringNotContains('hunter2', $result->stdout, 'ProcessRunner must redact on capture, not at the call site.');
        $this->assertStringContains(Redactor::PLACEHOLDER, $result->stdout);
    }

    public function testPublicSettingsViewNeverCarriesTheKeyPath(): void
    {
        $key = $this->estate->makeKey('secret-named-key');
        $settings = $this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'ssh_key_path' => $key,
        ]);

        $public = $settings->toPublicArray();
        $encoded = Json::pretty($public);

        $this->assertFalse(array_key_exists('ssh_key_path', $public));
        $this->assertTrue($public['ssh_key_configured']);
        $this->assertSame('secret-named-key', $public['ssh_key_name']);
        $this->assertStringNotContains($key, $encoded, 'The full key path must not reach a read-only surface.');
        $this->assertStringNotContains($this->estate->configRoot, $encoded);

        // The private view still has it, because the transport needs it.
        $this->assertSame($key, $settings->toArray()['ssh_key_path']);
    }

    public function testHealthRecordCarriesNoSecretOrKeyMaterial(): void
    {
        $key = $this->estate->makeKey();
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'ssh_key_path' => $key,
        ]);
        $this->estate->publisher($settings)->publish();

        $raw = (string) file_get_contents($this->estate->generations($settings)->healthPath());

        $this->assertStringNotContains($key, $raw, 'The key path must not be in the health record.');
        $this->assertStringNotContains('PRIVATE KEY', $raw);
        $this->assertStringNotContains('ZmFrZQ', $raw, 'No byte of the key file may appear.');
        // The operationally useful, non-secret facts are still there.
        $this->assertStringContains('"ssh_key_configured": true', $raw);
        $this->assertStringContains('10.9.7.102', $raw);
    }

    public function testHealthRecordIsWorldReadableButSettingsAreNot(): void
    {
        if ($this->runningAsRoot()) {
            $this->skip('mode assertions are meaningless as root');
        }
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $settings = $this->estate->settings();
        $this->estate->publisher($settings)->publish();

        $generations = $this->estate->generations($settings);
        // The health record is meant to be consumed by another component.
        $this->assertSame('0644', substr(sprintf('%o', fileperms($generations->healthPath())), -4));

        $store = $this->estate->store();
        $store->save($settings);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($store->path())), -4));
    }
}
