<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Config\Policy;
use LofAudioSupply\Support\Json;
use LofTest\Estate;
use LofTest\TestCase;

/**
 * FPP 10 compatibility and runtime constraints.
 *
 * These assert the shape of the deployed artefact - the paths it defaults to,
 * the PHP it needs, the absence of a composer dependency - and drive the real
 * CLI end to end against a simulated FPP 10 tree. Nothing here touches a live
 * FPP, a real /home/fpp, or a network.
 */
final class Fpp10Test extends TestCase
{
    private Estate $estate;

    public function setUp(): void
    {
        $this->estate = new Estate($this->makeTempRoot('fpp10'));
    }

    public function testDefaultPolicyMatchesTheFpp10MediaLayout(): void
    {
        $policy = Policy::default();

        $this->assertSame('/home/fpp/media', Policy::FPP_MEDIA_ROOT);
        $this->assertContainsValue('/home/fpp/media/music', $policy->sourceRoots);
        $this->assertContainsValue('/home/fpp/media/upload', $policy->sourceRoots);
        $this->assertSame('/home/fpp/media/lof-audio-supply', $policy->defaultPublicationRoot());
        $this->assertContainsValue('/home/fpp/media/config', $policy->keyRoots);
        $this->assertSame('/home/fpp/media/config/lof-audio-known_hosts', $policy->knownHostsPath);

        // Binaries are at the Debian paths FPP 8/9/10 all use.
        $this->assertContainsValue('/usr/bin/rsync', $policy->allowedBinaries);
        $this->assertContainsValue('/usr/bin/ssh', $policy->allowedBinaries);
        foreach ($policy->allowedBinaries as $binary) {
            $this->assertSame('/', $binary[0], 'Allowlisted binaries must be absolute.');
        }
    }

    public function testEveryApprovedRootStaysInsideTheFppEstate(): void
    {
        $policy = Policy::default();
        foreach (array_merge($policy->sourceRoots, $policy->publicationRoots) as $root) {
            $this->assertSame(0, strpos($root, '/home/fpp/'), $root . ' must live under the FPP home.');
        }
        foreach ($policy->keyRoots as $root) {
            $this->assertSame(0, strpos($root, '/home/fpp/'), $root . ' must live under the FPP home.');
        }
        // No approved root may be a filesystem root or /etc.
        foreach ($policy->destinationRoots as $root) {
            $this->assertNotSame('/', $root);
            $this->assertNotSame('/etc', $root);
        }
    }

    public function testPluginRunsOnThePhpFppShips(): void
    {
        // FPP 8 (Bullseye) ships PHP 8.1; FPP 9/10 ship 8.2/8.3. The library
        // must not need anything newer than 8.1.
        $this->assertTrue(PHP_VERSION_ID >= 80100, 'PHP 8.1+ is the floor.');
        foreach (['json', 'hash', 'pcre', 'SPL'] as $extension) {
            $this->assertTrue(extension_loaded($extension), 'Required extension missing: ' . $extension);
        }
        // No composer autoloader anywhere: FPP images do not have composer.
        $this->assertFalse(is_dir(LOF_AUDIO_SUPPLY_ROOT . '/vendor'), 'The plugin must not depend on composer.');
        $this->assertFalse(is_file(LOF_AUDIO_SUPPLY_ROOT . '/composer.json'));
    }

    public function testTheLsyncdDeleteBasedSyncIsFullyRemoved(): void
    {
        // The two divergent start_sync.sh copies are gone...
        $this->assertFalse(is_file(LOF_AUDIO_SUPPLY_ROOT . '/start_sync.sh'));
        $this->assertFalse(is_file(LOF_AUDIO_SUPPLY_ROOT . '/scripts/start_sync.sh'));
        $this->assertTrue(is_file(LOF_AUDIO_SUPPLY_ROOT . '/scripts/lof_audio_supply.sh'));

        // ...and no shipped file still generates lsyncd Lua or asks rsync to
        // delete. The install hook and the README are exempt: they name the
        // retired artefacts in order to retire and document them.
        $mayNameTheRetiredSetup = ['scripts/fpp_install.sh', 'README.md'];
        foreach ($this->shippedFiles() as $file) {
            $relative = substr($file, strlen(LOF_AUDIO_SUPPLY_ROOT) + 1);
            if (strpos($relative, 'tests/') === 0 || in_array($relative, $mayNameTheRetiredSetup, true)) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContains('default.rsync', $contents, $relative . ' still generates lsyncd Lua.');
            $this->assertStringNotContains('lsyncd-lof.conf.lua', $contents, $relative . ' still references the generated Lua.');

            if (substr($relative, -4) === '.php' || $relative === 'bin/lof-audio-supply') {
                // A comment may explain why --delete is absent; only a real
                // string literal could put it on a command line.
                foreach (self::stringLiterals($contents) as $literal) {
                    $this->assertStringNotContains('--delete', $literal, $relative . ' has a --delete string literal.');
                    $this->assertStringNotContains('--remove-source-files', $literal, $relative . ' has a destructive rsync literal.');
                }
                continue;
            }
            $this->assertStringNotContains('--delete', $contents, $relative . ' still mentions --delete.');
        }

        // The exempt files must only *retire* lsyncd, never start it.
        $install = (string) file_get_contents(LOF_AUDIO_SUPPLY_ROOT . '/scripts/fpp_install.sh');
        $this->assertStringContains('systemctl stop lsyncd', $install);
        $this->assertStringContains('systemctl disable lsyncd', $install);
        $this->assertStringNotContains('apt-get install -y lsyncd', $install);
        $this->assertStringNotContains('cat > "$LSYNCD_CONFIG"', $install);
    }

    public function testNoShippedPhpFileCallsAShell(): void
    {
        // Tokenised rather than grepped: a doc comment is allowed to mention
        // `system` or use backticks for markup, but a real call or a real
        // shell-exec operator is not.
        $banned = ['exec', 'shell_exec', 'passthru', 'system', 'popen', 'proc_open', 'pcntl_exec'];

        $sources = array_merge(
            $this->shippedFiles('php'),
            [LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply']
        );
        $checked = 0;
        foreach ($sources as $file) {
            $relative = substr($file, strlen(LOF_AUDIO_SUPPLY_ROOT) + 1);
            if (strpos($relative, 'tests/') === 0) {
                continue;
            }
            // ProcessRunner is the single place a child may be started, and it
            // uses the argv-array form of proc_open, which never invokes a shell.
            $exempt = $relative === 'lib/Process/ProcessRunner.php';
            $checked++;

            $tokens = token_get_all((string) file_get_contents($file));
            $previousSignificant = null;
            foreach ($tokens as $index => $token) {
                if ($token === '`') {
                    throw new \LofTest\AssertionFailed($relative . ' uses the backtick shell-exec operator.');
                }
                if (!is_array($token)) {
                    $previousSignificant = $token;
                    continue;
                }
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($token[0] === T_STRING && in_array(strtolower($token[1]), $banned, true)) {
                    // Skip method calls and class constants of the same name.
                    $isMemberAccess = is_array($previousSignificant)
                        && in_array($previousSignificant[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                    $next = self::nextSignificant($tokens, $index);
                    if (!$isMemberAccess && $next === '(' && !$exempt) {
                        throw new \LofTest\AssertionFailed($relative . ' calls ' . $token[1] . '() directly.');
                    }
                }
                $previousSignificant = $token;
            }
        }
        $this->assertTrue($checked > 15, 'The scan should have covered the whole library, saw ' . $checked);
    }

    /**
     * Every string literal in a PHP source, comments excluded.
     *
     * @return list<string>
     */
    private static function stringLiterals(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            // T_INLINE_HTML is deliberately excluded: markup outside <?php is
            // prose on a page, and prose is allowed to name --delete in order
            // to say that the plugin never passes it.
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function nextSignificant(array $tokens, int $from)
    {
        for ($i = $from + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @return list<string> */
    private function shippedFiles(string $extension = ''): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(LOF_AUDIO_SUPPLY_ROOT, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            $path = $entry->getPathname();
            if (strpos($path, '/.git/') !== false) {
                continue;
            }
            if ($extension !== '' && strtolower((string) $entry->getExtension()) !== $extension) {
                continue;
            }
            $out[] = $path;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    public function testFullPublishCycleUnderASimulatedFpp10Tree(): void
    {
        // Paths shaped exactly like the real estate, under a temp prefix.
        $this->assertStringContains('/home/fpp/media/music', $this->estate->musicRoot);
        $this->assertStringContains('/home/fpp/media/lof-audio-supply', $this->estate->publicationRoot);

        $this->estate->seedMusic([
            'Wizards in Winter.mp3' => str_repeat('a', 4096),
            'Carol of the Bells.mp3' => str_repeat('b', 2048),
            'sub/Intro.wav' => str_repeat('c', 512),
        ]);

        $settings = $this->estate->settings();
        $result = $this->estate->publisher($settings)->publish();
        $this->assertSame('published', $result->outcome);
        $this->assertSame(3, $result->assetCount);
        $this->assertSame(6656, $result->totalBytes);

        $generations = $this->estate->generations($settings);
        $this->assertTrue(is_file($generations->currentLink() . '/Wizards in Winter.mp3'));
        $this->assertTrue(is_file($generations->currentLink() . '/sub/Intro.wav'));
        $this->assertCount(0, $this->estate->publisher($settings)->verifyCurrent());
    }

    public function testCliDrivesTheWholeLifecycle(): void
    {
        $php = PHP_BINARY;
        if (!is_executable($php)) {
            $this->skip('PHP binary is not resolvable for a subprocess run');
        }

        $policyFile = $this->estate->pluginDir . '/policy.json';
        file_put_contents($policyFile, Json::pretty([
            'source_roots' => [$this->estate->musicRoot, $this->estate->uploadRoot],
            'publication_roots' => [$this->estate->publicationRoot],
            'key_roots' => [$this->estate->configRoot],
            'destination_roots' => ['/var/www/lof-audio'],
            'known_hosts_path' => $this->estate->configRoot . '/lof-audio-known_hosts',
        ]));
        $settingsFile = $this->estate->pluginDir . '/settings.json';

        $run = function (array $args) use ($php, $policyFile, $settingsFile): array {
            $argv = array_merge([$php, LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply'], $args);
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $env = ['PATH' => '/usr/bin:/bin', 'LOF_AUDIO_POLICY' => $policyFile, 'LOF_AUDIO_SETTINGS' => $settingsFile];
            $process = proc_open($argv, $descriptors, $pipes, null, $env);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        };

        // init writes a valid, disarmed settings file.
        $init = $run(['init', '--json']);
        $this->assertSame(0, $init['code'], 'init failed: ' . $init['stderr']);
        $this->assertTrue(is_file($settingsFile));
        $settings = Json::readFile($settingsFile);
        $this->assertFalse($settings['enabled']);
        $this->assertFalse($settings['distribution_enabled']);

        // Disabled means nothing is published.
        $this->estate->seedMusic(['a.mp3' => 'one']);
        $skipped = $run(['publish', '--json']);
        $this->assertSame(0, $skipped['code']);
        $this->assertStringContains('"outcome": "skipped"', $skipped['stdout']);

        // Enable, then publish for real.
        $settings['enabled'] = true;
        file_put_contents($settingsFile, Json::pretty($settings));
        $published = $run(['publish', '--json', '--force']);
        $this->assertSame(0, $published['code'], 'publish failed: ' . $published['stderr']);
        $this->assertStringContains('"outcome": "published"', $published['stdout']);

        // verify, status, and self-check all agree.
        $verify = $run(['verify', '--json']);
        $this->assertSame(0, $verify['code'], 'verify failed: ' . $verify['stderr']);
        $this->assertStringContains('"problem_count": 0', $verify['stdout']);

        $status = $run(['status', '--json']);
        $this->assertSame(0, $status['code']);
        $this->assertStringContains('"state": "ok"', $status['stdout']);
        $this->assertStringContains('"role": "media-supply"', $status['stdout']);

        // status reports the settings actually in force, not the ones that
        // happened to be current at the last publish.
        $settings['retain_generations'] = 7;
        file_put_contents($settingsFile, Json::pretty($settings));
        $restated = $run(['status', '--json']);
        $this->assertStringContains('"retain_generations": 7', $restated['stdout']);
        $this->assertStringContains('"outcome": "published"', $restated['stdout'], 'The last run outcome is carried forward.');

        $selfCheck = $run(['self-check', '--json']);
        $this->assertSame(0, $selfCheck['code']);
        $this->assertStringContains('"settings_valid": true', $selfCheck['stdout']);

        // The interval gate holds a second publish back without --force.
        $throttled = $run(['publish', '--json']);
        $this->assertStringContains('"outcome": "skipped"', $throttled['stdout']);

        // Unknown commands and malformed flags are refused, not guessed at.
        $this->assertSame(64, $run(['definitely-not-a-command'])['code']);
        $this->assertSame(64, $run(['status', '-rf', '/'])['code']);
        $this->assertSame(64, $run(['status', '--older-than-seconds=; id'])['code']);

        // Quarantine pruning refuses without explicit confirmation.
        $unconfirmed = $run(['prune-quarantine', '--retention-days=1']);
        $this->assertSame(1, $unconfirmed['code']);
        $this->assertStringContains('quarantine.not_confirmed', $unconfirmed['stderr']);

        // Distribution stays refused while disarmed.
        $distribute = $run(['distribute', '--json']);
        $this->assertSame(1, $distribute['code']);
        $this->assertStringContains('distribute.disarmed', $distribute['stderr']);
    }

    public function testCliRefusesToRunUnderAWebServer(): void
    {
        $source = (string) file_get_contents(LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply');
        $this->assertStringContains("PHP_SAPI !== 'cli'", $source, 'The CLI must refuse a web SAPI.');
    }

    public function testPluginMetadataDeclaresTheSupportedFppRange(): void
    {
        $info = Json::readFile(LOF_AUDIO_SUPPLY_ROOT . '/pluginInfo.json');
        $this->assertSame('fpp-lof-audio-sync', $info['repoName']);
        $this->assertTrue(isset($info['versions'][0]['minFPPVersion']));
        $this->assertSame('8.0', $info['versions'][0]['minFPPVersion']);

        // lsyncd is no longer a dependency of this plugin.
        $packages = $info['versions'][0]['dependencies']['packages'];
        $this->assertNotContainsValue('lsyncd', $packages);
        $this->assertContainsValue('rsync', $packages);
    }

    public function testInstallAndUninstallHooksAreSyntacticallyValid(): void
    {
        foreach (['scripts/fpp_install.sh', 'scripts/fpp_uninstall.sh', 'scripts/lof_audio_supply.sh'] as $script) {
            $path = LOF_AUDIO_SUPPLY_ROOT . '/' . $script;
            $this->assertTrue(is_file($path), $script . ' must exist.');
            $this->assertTrue(is_executable($path), $script . ' must be executable.');

            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(['/bin/bash', '-n', $path], $descriptors, $pipes, null, ['PATH' => '/usr/bin:/bin']);
            if (!is_resource($process)) {
                $this->skip('bash is not available for a syntax check');
            }
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $script . ' has a syntax error: ' . $stderr);
        }
    }
}
