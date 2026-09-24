<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

/**
 * Structural proof that a file is the V1 profile and carries no metadata.
 *
 * Pure PHP, no extra binary. The ISO-BMFF box tree is walked against an
 * allowlist: a box that is not needed to play one AAC track is refused, which
 * is what catches `udta`/`meta`/`ilst` tags, cover art, chapters, `uuid`
 * blobs and anything an encoder might add later. The sample entry is then
 * decoded down to the AudioSpecificConfig to prove AAC-LC, 2 ch, 44.1 kHz.
 */
final class M4aInspector implements RenditionInspector
{
    /** Boxes whose only purpose is metadata; their presence is a leak. */
    private const METADATA_BOXES = ['udta', 'meta', 'ilst', 'keys', 'chpl', 'covr', 'uuid', 'ID32', 'Xtra', 'tref', 'chap', 'name', 'cprt', 'data'];

    /** parent => allowed children. A parent not listed here is a leaf. */
    private const TREE = [
        '' => ['ftyp', 'moov', 'mdat', 'free'],
        'moov' => ['mvhd', 'trak', 'free'],
        'trak' => ['tkhd', 'edts', 'mdia'],
        'edts' => ['elst'],
        'mdia' => ['mdhd', 'hdlr', 'minf'],
        'minf' => ['smhd', 'dinf', 'stbl'],
        'dinf' => ['dref'],
        'stbl' => ['stsd', 'stts', 'stsc', 'stsz', 'stco', 'co64', 'sgpd', 'sbgp'],
    ];

    private const MAX_BOXES = 4096;

    /** @var list<string> */
    private array $problems = [];
    private int $boxes = 0;
    private int $tracks = 0;
    private ?string $sampleEntry = null;

    public function inspect(string $path): array
    {
        $this->problems = [];
        $this->boxes = 0;
        $this->tracks = 0;
        $this->sampleEntry = null;

        $size = @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($size === false || $handle === false || $size < 8) {
            return ['container_malformed'];
        }
        try {
            $this->walk($handle, 0, (int) $size, '', 0);
        } finally {
            fclose($handle);
        }
        if ($this->problems === []) {
            if ($this->tracks !== 1) {
                $this->problems[] = 'track_count';
            }
            if ($this->sampleEntry === null) {
                $this->problems[] = 'codec';
            }
        }

        return array_values(array_unique($this->problems));
    }

    /** @param resource $h */
    private function walk($h, int $start, int $end, string $parent, int $depth): void
    {
        $pos = $start;
        $isTopFirst = $parent === '';
        while ($pos < $end && $this->problems === []) {
            if (++$this->boxes > self::MAX_BOXES || $depth > 12 || $end - $pos < 8) {
                $this->problems[] = 'container_malformed';

                return;
            }
            $header = $this->read($h, $pos, 8);
            if ($header === null) {
                return;
            }
            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLen = 8;
            if ($boxSize === 1) {
                $large = $this->read($h, $pos + 8, 8);
                if ($large === null) {
                    return;
                }
                $parts = unpack('N2', $large);
                $boxSize = ($parts[1] << 32) | $parts[2];
                $headerLen = 16;
            } elseif ($boxSize === 0 && $parent === '') {
                $boxSize = $end - $pos;
            }
            if ($boxSize < $headerLen || $pos + $boxSize > $end) {
                $this->problems[] = 'container_malformed';

                return;
            }
            if ($isTopFirst) {
                $isTopFirst = false;
                if ($type !== 'ftyp') {
                    $this->problems[] = 'container_brand';

                    return;
                }
            }
            if (in_array($type, self::METADATA_BOXES, true) || ($type !== '' && $type[0] === "\xA9")) {
                $this->problems[] = 'metadata_box';

                return;
            }
            if (!in_array($type, self::TREE[$parent], true)) {
                $this->problems[] = 'unexpected_box';

                return;
            }
            $bodyStart = $pos + $headerLen;
            $bodyLen = $boxSize - $headerLen;
            $this->inspectLeaf($h, $type, $bodyStart, $bodyLen);
            if (isset(self::TREE[$type])) {
                if ($type === 'trak') {
                    $this->tracks++;
                }
                $this->walk($h, $bodyStart, $bodyStart + $bodyLen, $type, $depth + 1);
            }
            $pos += $boxSize;
        }
    }

    /** @param resource $h */
    private function inspectLeaf($h, string $type, int $at, int $len): void
    {
        switch ($type) {
            case 'ftyp':
                $body = $this->read($h, $at, min($len, 64));
                if ($body === null || substr($body, 0, 4) !== 'M4A ') {
                    $this->problems[] = 'container_brand';
                }
                break;
            case 'hdlr':
                // version/flags(4) pre_defined(4) handler_type(4) reserved(12) name
                $body = $this->read($h, $at, min($len, 256));
                if ($body === null || strlen($body) < 24 || substr($body, 8, 4) !== 'soun') {
                    $this->problems[] = 'track_kind';
                    break;
                }
                $name = rtrim(substr($body, 24), "\0");
                if ($name !== '' && $name !== 'SoundHandler') {
                    $this->problems[] = 'metadata_handler_name';
                }
                break;
            case 'dref':
                // A single self-contained `url ` reference (flags = 1), nothing external.
                $body = $this->read($h, $at, min($len, 64));
                if ($body === null || $len !== 20 || substr($body, 4, 4) !== "\0\0\0\1"
                    || substr($body, 12, 4) !== 'url ' || substr($body, 16, 4) !== "\0\0\0\1") {
                    $this->problems[] = 'external_reference';
                }
                break;
            case 'stsd':
                $this->inspectSampleDescription($h, $at, $len);
                break;
            case 'free':
                // Padding may exist (faststart, a neutralised tag skeleton) but may not hide bytes.
                if ($len > 1048576) {
                    $this->problems[] = 'metadata_free_payload';
                    break;
                }
                $body = $len === 0 ? '' : $this->read($h, $at, $len);
                if ($body === null || trim($body, "\0") !== '') {
                    $this->problems[] = 'metadata_free_payload';
                }
                break;
        }
    }

    /** @param resource $h */
    private function inspectSampleDescription($h, int $at, int $len): void
    {
        $body = $len <= 4096 ? $this->read($h, $at, $len) : null;
        if ($body === null || strlen($body) < 8 + 36 || unpack('N', substr($body, 4, 4))[1] !== 1) {
            $this->problems[] = 'codec';

            return;
        }
        $entry = substr($body, 8);
        $entrySize = unpack('N', substr($entry, 0, 4))[1];
        if (substr($entry, 4, 4) !== 'mp4a' || $entrySize !== strlen($entry) || $entrySize < 36) {
            $this->problems[] = 'codec';

            return;
        }
        // SampleEntry(8+8) + AudioSampleEntry: reserved(8) channelcount(2) samplesize(2) pre_defined(2) reserved(2) samplerate(4)
        $channels = unpack('n', substr($entry, 24, 2))[1];
        $rate = unpack('N', substr($entry, 32, 4))[1] >> 16;
        if ($channels !== ViewerProfile::CHANNELS) {
            $this->problems[] = 'channels';
        }
        if ($rate !== ViewerProfile::SAMPLE_RATE_HZ) {
            $this->problems[] = 'sample_rate';
        }
        $children = substr($entry, 36);
        $esds = null;
        $p = 0;
        while ($p + 8 <= strlen($children)) {
            $childSize = unpack('N', substr($children, $p, 4))[1];
            $childType = substr($children, $p + 4, 4);
            if ($childSize < 8 || $p + $childSize > strlen($children)) {
                $this->problems[] = 'container_malformed';

                return;
            }
            if ($childType === 'esds') {
                $esds = substr($children, $p + 8, $childSize - 8);
            } elseif ($childType !== 'btrt') {
                $this->problems[] = in_array($childType, self::METADATA_BOXES, true) ? 'metadata_box' : 'unexpected_box';

                return;
            }
            $p += $childSize;
        }
        if ($p !== strlen($children) || $esds === null || !$this->isAacLc(substr($esds, 4))) {
            $this->problems[] = 'codec';

            return;
        }
        $this->sampleEntry = 'mp4a';
    }

    /**
     * ES_Descriptor -> DecoderConfigDescriptor (objectType 0x40) ->
     * DecoderSpecificInfo -> AudioSpecificConfig: AOT 2 (LC), freq index 4
     * (44100), channel configuration 2.
     */
    private function isAacLc(string $d): bool
    {
        $p = 0;
        $es = $this->descriptor($d, $p, 0x03);
        if ($es === null || strlen($es) < 3) {
            return false;
        }
        $flags = ord($es[2]);
        $q = 3 + (($flags & 0x80) ? 2 : 0);
        if ($flags & 0x40) {
            if ($q >= strlen($es)) {
                return false;
            }
            $q += 1 + ord($es[$q]);
        }
        $q += ($flags & 0x20) ? 2 : 0;
        $dc = $this->descriptor($es, $q, 0x04);
        if ($dc === null || strlen($dc) < 13 || ord($dc[0]) !== 0x40) {
            return false;
        }
        $r = 13;
        $asc = $this->descriptor($dc, $r, 0x05);
        if ($asc === null || strlen($asc) < 2) {
            return false;
        }
        $bits = (ord($asc[0]) << 8) | ord($asc[1]);
        $aot = $bits >> 11;
        $freqIndex = ($bits >> 7) & 0x0F;
        $channelConfig = ($bits >> 3) & 0x0F;

        return $aot === 2 && $freqIndex === 4 && $channelConfig === ViewerProfile::CHANNELS;
    }

    private function descriptor(string $d, int &$p, int $tag): ?string
    {
        if ($p >= strlen($d) || ord($d[$p]) !== $tag) {
            return null;
        }
        $p++;
        $len = 0;
        for ($i = 0; $i < 4; $i++) {
            if ($p >= strlen($d)) {
                return null;
            }
            $b = ord($d[$p++]);
            $len = ($len << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                break;
            }
        }
        if ($p + $len > strlen($d)) {
            return null;
        }
        $body = substr($d, $p, $len);
        $p += $len;

        return $body;
    }

    /** @param resource $h */
    private function read($h, int $at, int $len): ?string
    {
        if ($len <= 0 || fseek($h, $at) !== 0) {
            $this->problems[] = 'container_malformed';

            return null;
        }
        $data = fread($h, $len);
        if ($data === false || strlen($data) !== $len) {
            $this->problems[] = 'container_malformed';

            return null;
        }

        return $data;
    }
}
