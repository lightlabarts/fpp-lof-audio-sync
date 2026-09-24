<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\IntegrityException;
use LofAudioSupply\Support\Fs;

/**
 * Removes the one metadata structure ffmpeg's MP4 muxer always writes.
 *
 * Even with every tag mapped away, the muxer emits an empty iTunes skeleton:
 * `moov/udta/meta { hdlr 'mdir', ilst (no children) }`. It carries no value,
 * but it is still a tag atom, and the contract says a rendition carries none.
 *
 * The skeleton is retyped in place to a `free` box with a zeroed payload, so
 * no chunk offset moves and the result stays deterministic. Anything other
 * than that exact empty skeleton is refused rather than scrubbed: if real tag
 * data ever reaches a rendition, the encoder invocation is wrong, and the run
 * must fail instead of quietly editing the evidence.
 */
final class TagSkeleton
{
    /** @return bool true when a skeleton was neutralised */
    public static function neutralise(string $path): bool
    {
        $size = @filesize($path);
        $h = @fopen($path, 'r+b');
        if ($size === false || $h === false) {
            throw new IntegrityException('viewer.partial_rendition', 'Rendition could not be opened for inspection.');
        }
        try {
            $changed = false;
            foreach (self::children($h, 0, (int) $size) as [$type, $at, $len]) {
                if ($type !== 'moov') {
                    continue;
                }
                foreach (self::children($h, $at + 8, $at + $len) as [$childType, $childAt, $childLen]) {
                    if ($childType !== 'udta') {
                        continue;
                    }
                    if (!self::isEmptySkeleton($h, $childAt, $childLen)) {
                        throw new IntegrityException('viewer.metadata_leak', 'Rendition carries tag data.');
                    }
                    fseek($h, $childAt + 4);
                    fwrite($h, 'free' . str_repeat("\0", $childLen - 8));
                    $changed = true;
                }
            }
            if ($changed) {
                fflush($h);
                Fs::fsyncHandle($h);
            }

            return $changed;
        } finally {
            fclose($h);
        }
    }

    /**
     * @param resource $h
     * @return list<array{0:string,1:int,2:int}> [type, offset, size]
     */
    private static function children($h, int $start, int $end): array
    {
        $out = [];
        $pos = $start;
        while ($pos + 8 <= $end) {
            fseek($h, $pos);
            $header = (string) fread($h, 8);
            if (strlen($header) !== 8) {
                break;
            }
            $len = unpack('N', substr($header, 0, 4))[1];
            if ($len < 8 || $pos + $len > $end) {
                // 64-bit or to-EOF sizes never occur inside moov; the inspector refuses what this skips.
                break;
            }
            $out[] = [substr($header, 4, 4), $pos, $len];
            $pos += $len;
        }

        return $out;
    }

    /** @param resource $h */
    private static function isEmptySkeleton($h, int $at, int $len): bool
    {
        $inner = self::children($h, $at + 8, $at + $len);
        if (count($inner) !== 1 || $inner[0][0] !== 'meta' || $inner[0][1] + $inner[0][2] !== $at + $len) {
            return false;
        }
        [, $metaAt, $metaLen] = $inner[0];
        // meta is a full box: 4 bytes of version/flags before its children.
        $parts = self::children($h, $metaAt + 12, $metaAt + $metaLen);
        if (count($parts) !== 2 || $parts[0][0] !== 'hdlr' || $parts[1][0] !== 'ilst' || $parts[1][2] !== 8
            || $parts[1][1] + 8 !== $metaAt + $metaLen) {
            return false;
        }
        fseek($h, $parts[0][1] + 8);
        $hdlr = (string) fread($h, $parts[0][2] - 8);

        // version/flags, pre_defined, handler 'mdir', reserved (manufacturer 'appl' is allowed), empty name.
        return strlen($hdlr) >= 24 && substr($hdlr, 8, 4) === 'mdir' && trim(substr($hdlr, 24), "\0") === '';
    }
}
