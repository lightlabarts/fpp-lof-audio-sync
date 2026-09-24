<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Process\ProcessRunner;
use LofAudioSupply\Support\Json;
use LofAudioSupply\Viewer\FfmpegEncoder;
use LofAudioSupply\Viewer\M4aInspector;
use LofAudioSupply\Viewer\SupplyMasterSource;
use LofAudioSupply\Viewer\TagSkeleton;
use LofAudioSupply\Viewer\ViewerProfile;
use LofAudioSupply\Viewer\ViewerPublisher;
use LofTest\TestCase;
use LofTest\ViewerEstate;

/**
 * The real, allowlisted encoder and the structural inspector.
 *
 * Masters are tiny tones generated on the spot, deliberately loaded with a
 * title, artist, comment, cover art and a hostile filename, so stripping is
 * proved against metadata that is really there. Tests that need an encoder
 * skip when none is installed; the argv and inspector rules never do.
 */
final class ViewerEncoderTest extends TestCase
{
    private const SECRETS = ['SECRETTITLE', 'SECRETARTIST', 'SECRETCOMMENT', 'SECRETALBUM', 'SECRETNAME'];

    private static function ffmpeg(): ?string
    {
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function requireFfmpeg(): string
    {
        $bin = self::ffmpeg();
        if ($bin === null) {
            $this->skip('no ffmpeg on this host; the real-encoder proof needs one');
        }

        return (string) $bin;
    }

    /** Generate a synthetic master through the same argv-only runner. */
    private function makeMaster(string $ffmpeg, string $path, string $kind): void
    {
        $tone = ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=1:sample_rate=48000'];
        $tags = ['-metadata', 'title=SECRETTITLE', '-metadata', 'artist=SECRETARTIST', '-metadata', 'comment=SECRETCOMMENT', '-metadata', 'album=SECRETALBUM'];
        $argv = [$ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error'];
        switch ($kind) {
            case 'mp3-art':
                $argv = array_merge($argv, $tone, ['-f', 'lavfi', '-i', 'color=c=red:s=16x16:d=1',
                    '-map', '0:a', '-map', '1:v', '-c:a', 'libmp3lame', '-c:v', 'mjpeg', '-frames:v', '1',
                    '-disposition:v', 'attached_pic', '-id3v2_version', '3'], $tags, ['-f', 'mp3', '-y', $path]);
                break;
            case 'flac':
                $argv = array_merge($argv, $tone, ['-c:a', 'flac'], $tags, ['-f', 'flac', '-y', $path]);
                break;
            case 'm4a-chapters':
                $meta = \dirname($path) . '/chapters.txt';
                file_put_contents($meta, ";FFMETADATA1\ntitle=SECRETTITLE\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=0\nEND=500\ntitle=SECRETNAME\n");
                $argv = array_merge($argv, $tone, ['-i', $meta, '-map', '0:a', '-map_metadata', '1', '-map_chapters', '1', '-c:a', 'aac'], $tags, ['-f', 'ipod', '-y', $path]);
                break;
            default:
                $argv = array_merge($argv, $tone, ['-ac', '1'], $tags, ['-f', 'wav', '-y', $path]);
        }
        $result = (new ProcessRunner([$ffmpeg]))->run($argv, 60);
        $this->assertTrue($result->succeeded() && is_file($path), 'fixture master ' . $kind . ' could not be generated');
    }

    public function testTheEncoderArgvIsFixedDeterministicAndShellFree(): void
    {
        $argv = ViewerProfile::encoderArgv('/usr/bin/ffmpeg', '/m/$(id); rm -rf ~ `x`.mp3', '/o/r.m4a.part');
        $this->assertSame([
            '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-protocol_whitelist', 'file', '-format_whitelist', 'mp3,wav,flac,mov,aac,ogg', '-threads', '1',
            '-i', 'file:/m/$(id); rm -rf ~ `x`.mp3',
            '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-map_metadata:s:a', '-1', '-map_chapters', '-1',
            '-c:a', 'aac', '-profile:a', 'aac_low', '-b:a', '128k', '-ac', '2', '-ar', '44100', '-threads', '1',
            '-fflags', '+bitexact', '-flags:a', '+bitexact', '-movflags', '+faststart', '-f', 'ipod',
            '-n', 'file:/o/r.m4a.part',
        ], $argv, 'A hostile name is one literal argv element behind file:.');
        $this->assertRefused('viewer.argv_not_absolute', static fn () => ViewerProfile::encoderArgv('/usr/bin/ffmpeg', 'relative.mp3', '/o/x'));
        $this->assertRefused('value.control_char', static fn () => ViewerProfile::encoderArgv('/usr/bin/ffmpeg', "/m/a\nb.mp3", '/o/x'));
        $this->assertRefused('value.option_like', static fn () => ViewerProfile::encoderArgv('-rf', '/m/a.mp3', '/o/x'));

        $runner = new ProcessRunner(['/usr/bin/rsync']);
        $encoder = new FfmpegEncoder($runner, '/usr/bin/ffmpeg', 60);
        $this->assertRefused('process.binary_not_allowed', static fn () => $encoder->encode('/m/a.mp3', '/o/a.m4a'));
        $missing = new FfmpegEncoder(new ProcessRunner(['/nonexistent/ffmpeg']), '/nonexistent/ffmpeg', 60);
        $this->assertRefused('viewer.encoder_missing', static fn () => $missing->encode('/m/a.mp3', '/o/a.m4a'));
    }

    public function testRealEncodeIsDeterministicStrippedAndTheV1Profile(): void
    {
        $ffmpeg = $this->requireFfmpeg();
        $dir = $this->makeTempRoot('enc');
        $encoder = new FfmpegEncoder(new ProcessRunner([$ffmpeg]), $ffmpeg, 60);
        $inspector = new M4aInspector();
        foreach (['wav-mono-48k' => 'SECRETNAME $(id) master.wav', 'mp3-art' => 'SECRETNAME art.mp3', 'flac' => 'SECRETNAME.flac', 'm4a-chapters' => 'SECRETNAME ch.m4a'] as $kind => $name) {
            $master = $dir . '/' . $name;
            $this->makeMaster($ffmpeg, $master, $kind);
            $masterBytes = (string) file_get_contents($master);
            $this->assertStringContains('SECRETTITLE', $masterBytes, $kind . ' fixture really carries tags');

            $encoder->encode($master, $dir . '/' . $kind . '-1.m4a');
            $encoder->encode($master, $dir . '/' . $kind . '-2.m4a');
            $one = (string) file_get_contents($dir . '/' . $kind . '-1.m4a');
            $this->assertSame($one, (string) file_get_contents($dir . '/' . $kind . '-2.m4a'), $kind . ': two runs, identical bytes');
            $this->assertNotSame(hash('sha256', $masterBytes), hash('sha256', $one), $kind . ': not the master');
            $this->assertSame([], $inspector->inspect($dir . '/' . $kind . '-1.m4a'), $kind . ': inspector');
            foreach (array_merge(self::SECRETS, ['udta', 'ilst', 'covr', 'chpl', 'Lavf', $dir]) as $needle) {
                $this->assertStringNotContains($needle, $one, $kind . ': stripped');
            }
            $this->assertSame('ftypM4A ', substr($one, 4, 8));
            $this->assertParseableWithLocalTools($dir . '/' . $kind . '-1.m4a', $kind);
        }
    }

    private function assertParseableWithLocalTools(string $file, string $label): void
    {
        $ffprobe = null;
        foreach (['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe'] as $candidate) {
            if (is_executable($candidate)) {
                $ffprobe = $candidate;
                break;
            }
        }
        if ($ffprobe !== null) {
            $probe = (new ProcessRunner([$ffprobe]))->run([$ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', '-show_chapters', $file], 60);
            $this->assertTrue($probe->succeeded(), $label . ': ffprobe parses it');
            $info = Json::decode($probe->stdout);
            $this->assertCount(1, $info['streams']);
            $s = $info['streams'][0];
            $this->assertSame(['aac', 'LC', 2, '44100'], [$s['codec_name'], $s['profile'], $s['channels'], $s['sample_rate']], $label);
            $this->assertSame([], $info['chapters'] ?? []);
            $tags = array_keys($info['format']['tags'] ?? []);
            sort($tags);
            $this->assertSame(['compatible_brands', 'major_brand', 'minor_version'], $tags, $label . ': container brand facts only');
            $decode = (new ProcessRunner([$this->requireFfmpeg()]))->run([$this->requireFfmpeg(), '-nostdin', '-v', 'error', '-i', $file, '-f', 'null', '-'], 60);
            $this->assertTrue($decode->succeeded() && $decode->stderr === '', $label . ': decodes cleanly');
        }
        if (is_executable('/usr/bin/afinfo')) {
            $af = (new ProcessRunner(['/usr/bin/afinfo']))->run(['/usr/bin/afinfo', $file], 60);
            $this->assertTrue($af->succeeded(), $label . ': afinfo parses it');
            $this->assertStringContains('2 ch,  44100 Hz, aac', $af->stdout, $label);
        }
    }

    public function testTheInspectorRefusesTagsWrongProfilesAndHiddenBytes(): void
    {
        $ffmpeg = $this->requireFfmpeg();
        $dir = $this->makeTempRoot('insp');
        $run = function (array $args, string $out) use ($ffmpeg, $dir): string {
            $argv = array_merge([$ffmpeg, '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=duration=0.3'], $args, ['-n', $dir . '/' . $out]);
            $this->assertTrue((new ProcessRunner([$ffmpeg]))->run($argv, 60)->succeeded(), $out);

            return $dir . '/' . $out;
        };
        $inspector = new M4aInspector();
        $tagged = $run(['-ac', '2', '-ar', '44100', '-c:a', 'aac', '-metadata', 'title=SECRETTITLE', '-f', 'ipod'], 'tagged.m4a');
        $this->assertSame(['metadata_box'], $inspector->inspect($tagged));
        $this->assertRefused('viewer.metadata_leak', static fn () => TagSkeleton::neutralise($tagged), 'Real tags are refused, not scrubbed.');
        $untagged = $run(['-ac', '2', '-ar', '44100', '-c:a', 'aac', '-f', 'ipod'], 'encoder-tag.m4a');
        $this->assertSame(['metadata_box'], $inspector->inspect($untagged), 'Even the encoder tag is metadata.');
        $this->assertRefused('viewer.metadata_leak', static fn () => TagSkeleton::neutralise($untagged));

        $exact = ['-fflags', '+bitexact', '-flags:a', '+bitexact', '-c:a', 'aac', '-f', 'ipod'];
        $this->assertContainsValue('channels', $inspector->inspect($run(array_merge(['-ac', '1', '-ar', '44100'], $exact), 'mono.m4a')));
        $this->assertContainsValue('sample_rate', $inspector->inspect($run(array_merge(['-ac', '2', '-ar', '48000'], $exact), '48k.m4a')));
        $skeleton = $run(array_merge(['-ac', '2', '-ar', '44100'], $exact), 'skeleton.m4a');
        $this->assertSame(['metadata_box'], $inspector->inspect($skeleton), 'The empty iTunes skeleton is still a tag atom...');
        $this->assertTrue(TagSkeleton::neutralise($skeleton));
        $this->assertSame([], $inspector->inspect($skeleton), '...until it is neutralised in place.');
        $this->assertFalse(TagSkeleton::neutralise($skeleton), 'Idempotent.');

        // A free box may pad but may not hide bytes.
        $bytes = (string) file_get_contents($skeleton);
        $at = strrpos($bytes, 'free'); // the neutralised skeleton: moov is last in a non-faststart file
        $hidden = substr_replace($bytes, 'SECRETNAME', $at + 4, 10);
        file_put_contents($dir . '/hidden.m4a', $hidden);
        $this->assertSame(['metadata_free_payload'], $inspector->inspect($dir . '/hidden.m4a'));

        file_put_contents($dir . '/garbage.m4a', str_repeat("\x00\x00\x00\x08junk", 8));
        $this->assertNotSame([], $inspector->inspect($dir . '/garbage.m4a'));
        file_put_contents($dir . '/short.m4a', 'abc');
        $this->assertSame(['container_malformed'], $inspector->inspect($dir . '/short.m4a'));
        $mp3 = $dir . '/x.mp3';
        $this->makeMaster($ffmpeg, $mp3, 'mp3-art');
        $this->assertNotSame([], $inspector->inspect($mp3), 'A master is not a rendition.');
    }

    public function testDisguisedAndUndecodableMastersAreRefused(): void
    {
        $ffmpeg = $this->requireFfmpeg();
        $dir = $this->makeTempRoot('evil');
        $encoder = new FfmpegEncoder(new ProcessRunner([$ffmpeg]), $ffmpeg, 60);
        file_put_contents($dir . '/playlist.mp3', "#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nhttp://127.0.0.1:9/x.ts\n#EXT-X-ENDLIST\n");
        file_put_contents($dir . '/concat.mp3', "ffconcat version 1.0\nfile /etc/hosts\n");
        file_put_contents($dir . '/noise.wav', random_bytes(4096));
        foreach (['playlist.mp3', 'concat.mp3', 'noise.wav'] as $name) {
            $e = $this->assertRefused('viewer.encode_failed', static fn () => $encoder->encode($dir . '/' . $name, $dir . '/' . $name . '.m4a'), $name);
            $this->assertStringNotContains($name, json_encode($e->context()) . $e->getMessage());
        }
    }

    public function testEndToEndFromAVerifiedSupplyGenerationThroughTheCli(): void
    {
        $ffmpeg = $this->requireFfmpeg();
        $v = new ViewerEstate($this->makeTempRoot('e2e'), ['encoder' => $ffmpeg], bin2hex(random_bytes(32)));
        $e = $v->estate;
        $this->makeMaster($ffmpeg, $e->musicRoot . '/SECRETNAME Overture.wav', 'wav-mono-48k');
        mkdir($e->musicRoot . '/Masters', 0755);
        $this->makeMaster($ffmpeg, $e->musicRoot . '/Masters/SECRETNAME Theme.mp3', 'mp3-art');
        file_put_contents($e->musicRoot . '/playlist-notes.json', '{"not":"audio"}');

        $policyFile = $e->pluginDir . '/policy.json';
        $policy = ViewerEstate::policyFor($e, $v->viewerBlock($ffmpeg))->toArray();
        file_put_contents($policyFile, Json::pretty($policy));
        $settingsFile = $e->pluginDir . '/settings.json';
        $run = function (array $args) use ($policyFile, $settingsFile): array {
            $argv = array_merge([PHP_BINARY, LOF_AUDIO_SUPPLY_ROOT . '/bin/lof-audio-supply'], $args);
            $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                ['PATH' => '/usr/bin:/bin', 'LOF_AUDIO_POLICY' => $policyFile, 'LOF_AUDIO_SETTINGS' => $settingsFile]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        };

        $this->assertSame(0, $run(['init', '--json'])['code']);
        $settings = Json::readFile($settingsFile);
        $settings['enabled'] = true;
        file_put_contents($settingsFile, Json::pretty($settings));

        // The timer's publish: media supply first, then the viewer lane.
        $published = $run(['publish', '--json', '--force']);
        $this->assertSame(0, $published['code'], 'publish: ' . $published['stderr']);
        $out = Json::decode($published['stdout']);
        $this->assertSame('published', $out['outcome']);
        $this->assertSame('published', $out['viewer_rendition']['outcome']);
        $this->assertSame(2, $out['viewer_rendition']['asset_count'], 'Two audio masters; the JSON asset is not a master.');
        $first = $out['viewer_rendition']['generation'];

        $verify = $run(['viewer-verify', '--json']);
        $this->assertSame(0, $verify['code'], $verify['stdout'] . $verify['stderr']);
        $status = Json::decode($run(['status', '--json'])['stdout']);
        $this->assertSame('published', $status['viewer_rendition']['outcome']);

        // The viewer tree holds renditions and visitor-safe documents only.
        $viewerBytes = ViewerEstate::treeBytes($v->viewerRoot);
        foreach (array_merge(self::SECRETS, ['Overture', 'Theme', 'Masters', '.wav', '.mp3', 'playlist-notes', $e->root]) as $needle) {
            $this->assertStringNotContains($needle, $viewerBytes, 'viewer tree leaks ' . $needle);
        }
        foreach (['publish', 'viewer-verify', 'status'] as $cmd) {
            $this->assertStringNotContains('SECRETNAME', $run([$cmd, '--json'])['stderr']);
        }
        $inspector = new M4aInspector();
        foreach (glob($v->viewerRoot . '/generations/' . $first . '/*') as $file) {
            $this->assertSame(1, preg_match('/\/[0-9a-f]{32}\.m4a$/', $file));
            $this->assertSame([], $inspector->inspect($file));
        }
        $map = Json::readFile($v->privateRoot . '/source-maps/' . $first . '.json');
        $rels = array_values(array_map(static fn (array $x): string => $x['source_rel'], $map['entries']));
        sort($rels, SORT_STRING);
        $this->assertSame(['Masters/SECRETNAME Theme.mp3', 'SECRETNAME Overture.wav'], $rels, 'The private map holds the source facts.');
        $supplyManifest = Json::readFile($e->publicationRoot . '/manifests/' . $map['supply_generation'] . '.json');
        $this->assertSame($supplyManifest['manifest_sha256'], $map['supply_manifest_sha256'], 'Provenance binds the verified supply generation.');

        // A second supply generation: unchanged masters re-encode to identical bytes under new ids.
        $this->makeMaster($ffmpeg, $e->musicRoot . '/SECRETNAME Encore.flac', 'flac');
        $again = Json::decode($run(['publish', '--json', '--force'])['stdout']);
        $second = $again['viewer_rendition']['generation'];
        $this->assertSame('published', $again['viewer_rendition']['outcome']);
        $this->assertSame($first, $again['viewer_rendition']['previous_generation']);
        $firstMap = $map;
        $secondMap = Json::readFile($v->privateRoot . '/source-maps/' . $second . '.json');
        $bySource = static function (array $m): array {
            $out = [];
            foreach ($m['entries'] as $rid => $entry) {
                $out[$entry['source_rel']] = [$rid, $entry['rendition_sha256']];
            }

            return $out;
        };
        $a = $bySource($firstMap);
        $b = $bySource($secondMap);
        foreach ($a as $rel => [$rid, $sha]) {
            $this->assertSame($sha, $b[$rel][1], 'determinism across runs');
            $this->assertNotSame($rid, $b[$rel][0], 'generation-scoped ids');
        }

        // Unchanged masters: the lane reports unchanged; rollback and verify via the CLI.
        $unchanged = Json::decode($run(['publish', '--json', '--force'])['stdout']);
        $this->assertSame('unchanged', $unchanged['viewer_rendition']['outcome']);
        $rolled = $run(['viewer-rollback', '--json']);
        $this->assertSame(0, $rolled['code'], $rolled['stderr']);
        $this->assertSame($first, Json::decode($rolled['stdout'])['generation']);
        $this->assertSame(0, $run(['viewer-verify', '--json'])['code']);

        // A tampered rendition is caught by the CLI verify.
        $victim = glob($v->viewerRoot . '/generations/' . $first . '/*.m4a')[0];
        file_put_contents($victim, 'x', FILE_APPEND);
        $bad = $run(['viewer-verify', '--json']);
        $this->assertSame(4, $bad['code']);
        $this->assertSame('file_size_drift', Json::decode($bad['stdout'])['reason']);
        $this->assertSame(ViewerPublisher::LAST_RUN_FILE, basename((string) glob($e->publicationRoot . '/viewer-last-run.json')[0]));
        $this->assertTrue(is_file((new SupplyMasterSource($v->supply()))->load()->masters['SECRETNAME Overture.wav']['path']));
    }
}
