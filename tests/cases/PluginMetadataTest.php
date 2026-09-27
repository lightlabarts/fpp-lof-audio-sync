<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Support\Json;
use LofTest\TestCase;

/**
 * Development/staging install metadata for the FPP Plugin Manager.
 *
 * The selection rule is FPP's own (fpp-plugin-Template PLUGININFO_FORMAT.md;
 * FPP 10.1.2 www/api/controllers/plugin.php:1455-1480): the LAST versions[]
 * entry whose range matches wins, and an open max ("0") is open only within
 * the major named by minFPPVersion. Read-only and deterministic: no network,
 * no git state.
 */
final class PluginMetadataTest extends TestCase
{
    private const SRC_URL = 'https://github.com/lightlabarts/fpp-lof-audio-sync.git';
    private const BRANCH = 'claude/fpp10-plugin-metadata-staging';

    /** @return array<string,mixed> */
    private function info(): array
    {
        return Json::readFile(LOF_AUDIO_SUPPLY_ROOT . '/pluginInfo.json');
    }

    private static function cmp(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));
        for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
            $d = ($pa[$i] ?? 0) <=> ($pb[$i] ?? 0);
            if ($d !== 0) {
                return $d;
            }
        }

        return 0;
    }

    /**
     * FPP's rule, as documented: last matching entry; an open max is capped at
     * (current major - 1).999 when the entry's minimum major differs.
     *
     * @return array{index:int,untested:bool}|null
     */
    private function select(string $fpp): ?array
    {
        $major = (int) explode('.', $fpp)[0];
        $chosen = null;
        foreach ($this->info()['versions'] as $i => $v) {
            $min = (string) ($v['minFPPVersion'] ?? '0');
            $max = (string) ($v['maxFPPVersion'] ?? '');
            $untested = false;
            if ($max === '0' || $max === '0.0' || $max === '') {
                if ((int) explode('.', $min)[0] !== $major) {
                    $max = ($major - 1) . '.999';
                    $untested = true;
                } else {
                    $max = '';
                }
            }
            if (self::cmp($min, $fpp) <= 0 && ($max === '' || self::cmp($fpp, $max) <= 0)) {
                $chosen = ['index' => $i, 'untested' => $untested];
            }
        }

        return $chosen;
    }

    public function testSourceIsTheWritableLightLabArtsRepositoryNotTheOldOrigin(): void
    {
        $info = $this->info();
        $this->assertSame(self::SRC_URL, $info['srcURL']);
        foreach (['homeURL', 'srcURL', 'bugURL'] as $k) {
            $this->assertStringNotContains('ljhaydn', $info[$k], $k);
        }
    }

    public function testEveryVersionEntryTargetsThisCandidateBranchNotMain(): void
    {
        foreach ($this->info()['versions'] as $i => $v) {
            $this->assertSame(self::BRANCH, $v['branch'], 'entry ' . $i);
            $this->assertSame('', $v['sha'], 'entry ' . $i . ': latest commit on the dedicated branch');
            $this->assertContainsValue('rsync', $v['dependencies']['packages']);
            $this->assertNotContainsValue('lsyncd', $v['dependencies']['packages']);
        }
    }

    public function testFpp10SelectsAnExplicitTestedEntryAndOlderMajorsKeepTheirs(): void
    {
        $fpp10 = $this->select('10.1.2');
        $this->assertTrue($fpp10 !== null, 'FPP 10.1.2 must match an entry');
        $this->assertSame(1, $fpp10['index']);
        $this->assertFalse($fpp10['untested'], 'FPP 10 must not show "Not updated for FPP 10"');
        $this->assertFalse($this->select('10.0')['untested']);

        foreach (['8.0', '8.5', '9.0', '9.5.3'] as $old) {
            $pick = $this->select($old);
            $this->assertTrue($pick !== null, $old . ' must stay supported');
            $this->assertSame(0, $pick['index'], $old);
            $this->assertFalse($pick['untested'], $old);
        }
        $this->assertSame(null, $this->select('7.5'), 'below the supported floor');

        // A future major is capped at 10.999 and matches nothing: never silently claimed.
        $this->assertSame(null, $this->select('11.0'));
    }
}
