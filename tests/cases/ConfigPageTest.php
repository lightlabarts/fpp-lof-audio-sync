<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Security\Csrf;
use LofAudioSupply\Support\Json;
use LofTest\Estate;
use LofTest\TestCase;

/**
 * Exercises config.php itself, in-process.
 *
 * The page is included with $_SERVER, $_SESSION, and $_POST set the way a
 * browser would set them, and its output is captured. No web server, no live
 * FPP, and every path it touches is inside a temporary estate.
 */
final class ConfigPageTest extends TestCase
{
    private Estate $estate;
    private string $settingsFile;
    private string $policyFile;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('estate'));
        $this->settingsFile = $this->estate->pluginDir . '/settings.json';
        $this->policyFile = $this->estate->pluginDir . '/policy.json';
        file_put_contents($this->policyFile, Json::pretty([
            'source_roots' => [$this->estate->musicRoot, $this->estate->uploadRoot],
            'publication_roots' => [$this->estate->publicationRoot],
            'key_roots' => [$this->estate->configRoot],
            'destination_roots' => ['/var/www/lof-audio'],
            'known_hosts_path' => $this->estate->configRoot . '/lof-audio-known_hosts',
        ]));
        putenv('LOF_AUDIO_SETTINGS=' . $this->settingsFile);
        putenv('LOF_AUDIO_POLICY=' . $this->policyFile);
    }

    public function tearDown(): void
    {
        putenv('LOF_AUDIO_SETTINGS');
        putenv('LOF_AUDIO_POLICY');
        $_POST = [];
        unset($_SESSION['fppUser']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $post
     * @param array<string,mixed> $session
     */
    private function render(array $server = [], array $post = [], array $session = []): string
    {
        $_SERVER = array_merge($_SERVER, [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'fpp.local',
            // FPP includes the page from plugin.php; anything else is a direct hit.
            'SCRIPT_FILENAME' => '/opt/fpp/www/plugin.php',
        ], $server);
        $_POST = $post;
        // Merge: the CSRF secret already in the session must survive, exactly
        // as it would across two requests from the same browser.
        $_SESSION = array_merge($_SESSION ?? [], $session);

        ob_start();
        try {
            include LOF_AUDIO_SUPPLY_ROOT . '/config.php';
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function testDirectAccessIsRefused(): void
    {
        $output = $this->render(['SCRIPT_FILENAME' => LOF_AUDIO_SUPPLY_ROOT . '/config.php']);
        $this->assertStringContains('must be opened through the FPP plugin menu', $output);
        $this->assertStringNotContains('<form', $output, 'No form may be rendered on a direct hit.');
    }

    public function testUnauthenticatedVisitorGetsAReadOnlyPage(): void
    {
        $output = $this->render();

        $this->assertStringContains('Read-only', $output);
        $this->assertStringContains('No authenticated FPP administrator', $output);
        // Every button is disabled and no usable token is minted.
        $this->assertTrue(substr_count($output, 'disabled') >= 5, 'Every action button must be disabled.');
        $this->assertStringContains('name="csrf_token" value=""', $output, 'No token may be minted without authority.');
        $this->assertSame(0, preg_match('/csrf_token" value="[0-9a-f]{64}"/', $output), 'No real token may reach an unauthenticated page.');
    }

    public function testUnauthenticatedPostCannotChangeSettings(): void
    {
        $before = is_file($this->settingsFile) ? (string) file_get_contents($this->settingsFile) : null;

        $output = $this->render(
            ['REQUEST_METHOD' => 'POST'],
            [
                'action' => 'save_settings',
                'enabled' => '1',
                'source_path' => $this->estate->musicRoot,
                'publication_root' => $this->estate->publicationRoot,
            ]
        );

        $this->assertStringContains('Refused', $output);
        $this->assertStringContains('FPP administrator authentication is required', $output);
        $after = is_file($this->settingsFile) ? (string) file_get_contents($this->settingsFile) : null;
        $this->assertSame($before, $after, 'An unauthenticated POST must not write settings.');
    }

    public function testAuthenticatedPostWithoutATokenIsRefused(): void
    {
        $output = $this->render(
            ['REQUEST_METHOD' => 'POST', 'PHP_AUTH_USER' => 'joe'],
            ['action' => 'save_settings', 'source_path' => $this->estate->musicRoot],
            ['fppUser' => 'joe']
        );

        $this->assertStringContains('Refused', $output);
        $this->assertFalse(is_file($this->settingsFile), 'No settings file may be created without a CSRF token.');
    }

    public function testAuthenticatedPostWithAValidTokenSaves(): void
    {
        if (!$this->startSession()) {
            $this->skip('a PHP session could not be started in this process');
        }
        $token = (new Csrf())->token('save_settings');

        $output = $this->render(
            ['REQUEST_METHOD' => 'POST', 'PHP_AUTH_USER' => 'joe', 'HTTP_ORIGIN' => 'http://fpp.local'],
            [
                'action' => 'save_settings',
                'csrf_token' => $token,
                'enabled' => '1',
                'source_path' => $this->estate->musicRoot,
                'publication_root' => $this->estate->publicationRoot,
                'destination_path' => '/var/www/lof-audio',
                'service_user' => 'lof-audio',
                'destination_port' => '22',
                'sync_interval_seconds' => '60',
                'retain_generations' => '3',
                'quarantine_retention_days' => '30',
            ]
        );

        $this->assertStringContains('Settings saved and validated against policy', $output);
        $this->assertTrue(is_file($this->settingsFile));
        $saved = Json::readFile($this->settingsFile);
        $this->assertTrue($saved['enabled']);
        $this->assertSame($this->estate->musicRoot, $saved['source_path']);
        $this->assertFalse($saved['distribution_enabled'], 'An unchecked box must stay off.');
    }

    public function testAValidTokenForAnotherActionIsRefused(): void
    {
        if (!$this->startSession()) {
            $this->skip('a PHP session could not be started in this process');
        }
        $output = $this->render(
            ['REQUEST_METHOD' => 'POST', 'PHP_AUTH_USER' => 'joe'],
            [
                'action' => 'save_settings',
                'csrf_token' => (new Csrf())->token('rollback'),
                'source_path' => $this->estate->musicRoot,
            ]
        );

        $this->assertStringContains('CSRF token is missing or invalid', $output);
        $this->assertFalse(is_file($this->settingsFile));
    }

    public function testRejectedValuesAreReportedWithTheirCodeAndNotExecuted(): void
    {
        if (!$this->startSession()) {
            $this->skip('a PHP session could not be started in this process');
        }
        $output = $this->render(
            ['REQUEST_METHOD' => 'POST', 'PHP_AUTH_USER' => 'joe'],
            [
                'action' => 'save_settings',
                'csrf_token' => (new Csrf())->token('save_settings'),
                'source_path' => '/etc',
                'destination_host' => '8.8.8.8',
                'service_user' => 'root',
            ]
        );

        $this->assertStringContains('path.not_approved', $output);
        $this->assertFalse(is_file($this->settingsFile), 'A rejected save must write nothing at all.');
    }

    public function testOutputIsEscaped(): void
    {
        // Policy text is rendered into the page; it must be escaped like
        // anything else, even though only root can edit it.
        file_put_contents($this->policyFile, Json::pretty([
            'source_roots' => [$this->estate->musicRoot],
            'publication_roots' => [$this->estate->publicationRoot],
            'key_roots' => [$this->estate->configRoot],
            'destination_roots' => ['/var/www/lof-audio'],
            'known_hosts_path' => $this->estate->configRoot . '/lof-audio-known_hosts',
            'host_allowlist' => ['10.0.0.0/8', '"><script>alert(1)</script>'],
        ]));

        $output = $this->render();

        $this->assertStringNotContains('<script>alert(1)</script>', $output);
        $this->assertStringContains('&lt;script&gt;', $output);
    }

    public function testUnrecognisedActionsAreRejected(): void
    {
        $output = $this->render(
            ['REQUEST_METHOD' => 'POST', 'PHP_AUTH_USER' => 'joe'],
            ['action' => 'exec_shell', 'cmd' => 'id']
        );
        $this->assertStringContains('Unrecognised action', $output);
    }

    public function testPageNeverRendersKeyMaterialOrAKeyPath(): void
    {
        if (!$this->startSession()) {
            $this->skip('a PHP session could not be started in this process');
        }
        $key = $this->estate->makeKey('show-key');
        $store = $this->estate->store();
        $store->save($this->estate->settings([
            'distribution_enabled' => true,
            'destination_host' => '10.9.7.102',
            'ssh_key_path' => $key,
        ]));

        $output = $this->render([], [], ['fppUser' => 'joe']);

        // The configured key path appears exactly once, in the form field the
        // operator edits - never in the status block, and never its contents.
        $this->assertStringNotContains('PRIVATE KEY', $output);
        $this->assertStringNotContains('ZmFrZQ', $output);
        $this->assertSame(1, substr_count($output, $key), 'The key path belongs only in its own input.');
        $this->assertStringContains('name="ssh_key_path"', $output);
    }

    private function startSession(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (headers_sent()) {
            return false;
        }

        return @session_start();
    }
}
