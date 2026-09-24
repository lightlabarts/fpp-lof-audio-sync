<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\SafePath;

/**
 * The one viewer rendition profile, `lof-viewer-aac-lc-m4a-v1`, and the one
 * encoder invocation that produces it.
 *
 * The argv is fixed. The only variable elements are two absolute paths, each
 * behind a `file:` protocol prefix so neither can be read as an option or as a
 * different protocol. Everything that makes the output deterministic and
 * metadata-free is spelled out:
 *
 *   -protocol_whitelist file      a master cannot make the decoder open a URL
 *   -format_whitelist ...         a master that is really a playlist/concat
 *                                 script is refused instead of followed
 *   -map 0:a:0 -vn -sn -dn        first audio stream only: no cover art,
 *                                 subtitles, or data streams
 *   -map_metadata -1 (global and per-stream), -map_chapters -1
 *                                 no title/artist/comment/chapter survives
 *   -fflags +bitexact -flags:a +bitexact
 *                                 no encoder tag, no creation time: two runs
 *                                 over the same master give the same bytes
 *   -threads 1                    no scheduling-dependent output
 *   -f ipod                       M4A brand; -n never overwrites
 */
final class ViewerProfile
{
    public const PROFILE_ID = 'lof-viewer-aac-lc-m4a-v1';
    public const CODEC = 'aac-lc';
    public const CHANNELS = 2;
    public const SAMPLE_RATE_HZ = 44100;
    public const BITRATE_BPS = 128000;

    /** Masters the lane accepts. Anything else in a supply generation is not audio and is not a master. */
    public const SOURCE_EXTENSIONS = ['mp3', 'ogg', 'wav', 'flac', 'm4a', 'aac'];

    /** Demuxers a master may be decoded with; mirrors SOURCE_EXTENSIONS. */
    public const INPUT_FORMATS = 'mp3,wav,flac,mov,aac,ogg';

    public static function isMasterName(string $relative): bool
    {
        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::SOURCE_EXTENSIONS, true);
    }

    /**
     * @return list<string>
     */
    public static function encoderArgv(string $binary, string $source, string $output): array
    {
        foreach (['encoder' => $binary, 'source' => $source, 'output' => $output] as $field => $path) {
            SafePath::assertSafeValue($path, 'viewer_' . $field);
            if ($path[0] !== '/') {
                throw new PolicyViolationException('viewer.argv_not_absolute', 'Encoder paths must be absolute.', ['field' => $field]);
            }
        }

        return [
            $binary,
            '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-protocol_whitelist', 'file',
            '-format_whitelist', self::INPUT_FORMATS,
            '-threads', '1',
            '-i', 'file:' . $source,
            '-map', '0:a:0', '-vn', '-sn', '-dn',
            '-map_metadata', '-1', '-map_metadata:s:a', '-1', '-map_chapters', '-1',
            '-c:a', 'aac', '-profile:a', 'aac_low',
            '-b:a', (string) intdiv(self::BITRATE_BPS, 1000) . 'k',
            '-ac', (string) self::CHANNELS, '-ar', (string) self::SAMPLE_RATE_HZ,
            '-threads', '1',
            '-fflags', '+bitexact', '-flags:a', '+bitexact',
            '-movflags', '+faststart',
            '-f', 'ipod',
            '-n', 'file:' . $output,
        ];
    }
}
