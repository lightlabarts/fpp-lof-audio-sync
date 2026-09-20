<?php

declare(strict_types=1);

namespace LofAudioSupply\Support;

use LofAudioSupply\LofAudioException;

/**
 * JSON that is safe to hash and safe to write.
 *
 * Canonical encoding (recursively key-sorted, no pretty printing, no escaped
 * slashes) is what makes a manifest digest reproducible across hosts and PHP
 * builds. Writes go through a same-directory temporary file plus fsync so a
 * crash mid-write cannot leave a half-parsed settings or manifest file behind.
 */
final class Json
{
    public const MAX_DEPTH = 64;
    public const MAX_BYTES = 33554432; // 32 MiB

    /** @param mixed $value */
    public static function canonical($value): string
    {
        $encoded = json_encode(
            self::sortRecursive($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            self::MAX_DEPTH
        );

        return $encoded;
    }

    /** @param mixed $value */
    public static function pretty($value): string
    {
        return json_encode(
            self::sortRecursive($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            self::MAX_DEPTH
        ) . "\n";
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortRecursive($v);
        }
        if (!$isList) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     * @throws LofAudioException on unreadable, oversized, or malformed input
     */
    public static function readFile(string $path): array
    {
        if (!is_file($path)) {
            throw new LofAudioException('json.missing', 'JSON file does not exist.', ['path' => basename($path)]);
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new LofAudioException('json.too_large', 'JSON file is too large.', ['path' => basename($path)]);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new LofAudioException('json.unreadable', 'JSON file could not be read.', ['path' => basename($path)]);
        }

        return self::decode($raw, basename($path));
    }

    /** @return array<string,mixed> */
    public static function decode(string $raw, string $label = 'payload'): array
    {
        try {
            $decoded = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LofAudioException('json.malformed', 'JSON could not be parsed.', ['source' => $label], $e);
        }
        if (!is_array($decoded)) {
            throw new LofAudioException('json.not_object', 'JSON root must be an object.', ['source' => $label]);
        }

        return $decoded;
    }

    /**
     * Write bytes atomically: temp file in the same directory, fsync, rename.
     */
    public static function writeFileAtomic(string $path, string $contents, int $mode = 0640): void
    {
        Fs::writeFileAtomic($path, $contents, $mode);
    }
}
