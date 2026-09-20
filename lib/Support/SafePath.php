<?php

declare(strict_types=1);

namespace LofAudioSupply\Support;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\ValidationException;

/**
 * Path handling that refuses to leave an approved root.
 *
 * Three separate defences, because each one alone is bypassable:
 *   1. character screening  - NUL, control bytes, and option-looking values;
 *   2. lexical containment  - `..` is resolved before the filesystem is asked
 *                             anything, so a non-existent path is still judged;
 *   3. real containment     - realpath() of the deepest existing ancestor is
 *                             compared against realpath() of the root, which is
 *                             what catches a symlink pointing outside.
 */
final class SafePath
{
    public const MAX_PATH_BYTES = 4096;
    public const MAX_SEGMENT_BYTES = 255;

    /**
     * Reject bytes that have no business in a configured path or argument.
     *
     * Control characters are the payload carrier for argument smuggling and for
     * log injection, and a leading dash turns a value into an option.
     */
    public static function assertSafeValue(string $value, string $field): void
    {
        if ($value === '') {
            throw new ValidationException($field, 'value.empty', 'Value must not be empty.');
        }
        if (strlen($value) > self::MAX_PATH_BYTES) {
            throw new ValidationException($field, 'value.too_long', 'Value exceeds the maximum length.');
        }
        if (strpos($value, "\0") !== false) {
            throw new ValidationException($field, 'value.nul_byte', 'Value contains a NUL byte.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ValidationException($field, 'value.control_char', 'Value contains a control character.');
        }
        if ($value[0] === '-') {
            throw new ValidationException($field, 'value.option_like', 'Value must not begin with "-".');
        }
        if (!self::isValidUtf8($value)) {
            throw new ValidationException($field, 'value.not_utf8', 'Value is not valid UTF-8.');
        }
    }

    private static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * Resolve `.` and `..` without consulting the filesystem.
     *
     * A `..` that would climb above `/` is clamped at `/`, so the result is
     * always a well-formed absolute path and containment can be decided purely
     * on the string.
     */
    public static function normalizeAbsolute(string $path, string $field = 'path'): string
    {
        self::assertSafeValue($path, $field);
        if ($path[0] !== '/') {
            throw new ValidationException($field, 'path.not_absolute', 'Path must be absolute.');
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
                throw new ValidationException($field, 'path.segment_too_long', 'Path segment is too long.');
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    /**
     * Validate a manifest-relative path.
     *
     * Manifest keys are attacker-reachable (an upstream file name ends up in
     * one), so they are held to a stricter grammar than configured paths: no
     * absolute form, no traversal, no dot segments, no leading dash.
     */
    public static function assertRelative(string $relative, string $field = 'relative_path'): void
    {
        self::assertSafeValue($relative, $field);
        if ($relative[0] === '/') {
            throw new ValidationException($field, 'relative.absolute', 'Relative path must not be absolute.');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                throw new ValidationException($field, 'relative.empty_segment', 'Relative path has an empty segment.');
            }
            if ($segment === '.' || $segment === '..') {
                throw new ValidationException($field, 'relative.dot_segment', 'Relative path contains a dot segment.');
            }
            if ($segment[0] === '-') {
                throw new ValidationException($field, 'relative.option_like', 'Path segment must not begin with "-".');
            }
            if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
                throw new ValidationException($field, 'relative.segment_too_long', 'Path segment is too long.');
            }
        }
    }

    public static function isContainedIn(string $root, string $candidate): bool
    {
        $root = rtrim($root, '/');
        if ($root === '') {
            // Containment in "/" is not a meaningful approval.
            return false;
        }

        return $candidate === $root || strncmp($candidate, $root . '/', strlen($root) + 1) === 0;
    }

    /**
     * Lexical containment check. Use when the path need not exist yet.
     *
     * @throws PolicyViolationException when the normalized path escapes $root
     */
    public static function assertWithin(string $root, string $path, string $field = 'path'): string
    {
        $normalizedRoot = self::normalizeAbsolute($root, $field . '.root');
        $normalized = self::normalizeAbsolute($path, $field);
        if (!self::isContainedIn($normalizedRoot, $normalized)) {
            throw new PolicyViolationException(
                'path.outside_root',
                'Path is outside the approved root.',
                ['field' => $field]
            );
        }

        return $normalized;
    }

    /**
     * Resolve symlinks and assert the result is still inside $root.
     *
     * The deepest existing ancestor is resolved with realpath(); the remaining
     * segments are appended lexically. That lets a not-yet-created directory be
     * approved while still catching `<root>/link -> /etc`.
     *
     * @throws PolicyViolationException when the resolved path escapes $root
     */
    public static function assertRealWithin(string $root, string $path, string $field = 'path'): string
    {
        $normalizedRoot = self::normalizeAbsolute($root, $field . '.root');
        $realRoot = realpath($normalizedRoot);
        if ($realRoot === false) {
            throw new PolicyViolationException(
                'path.root_missing',
                'Approved root does not exist.',
                ['field' => $field]
            );
        }

        $normalized = self::assertWithin($normalizedRoot, $path, $field);
        $resolved = self::resolveDeepest($normalized);

        if (!self::isContainedIn($realRoot, $resolved)) {
            throw new PolicyViolationException(
                'path.symlink_escape',
                'Path resolves outside the approved root.',
                ['field' => $field]
            );
        }

        return $resolved;
    }

    /**
     * realpath() the longest existing prefix, then re-attach the tail.
     */
    public static function resolveDeepest(string $normalizedAbsolute): string
    {
        $real = realpath($normalizedAbsolute);
        if ($real !== false) {
            return $real;
        }

        $tail = [];
        $current = $normalizedAbsolute;
        while (true) {
            $parent = \dirname($current);
            $tail[] = basename($current);
            if ($parent === $current) {
                break;
            }
            $realParent = realpath($parent);
            if ($realParent !== false) {
                return rtrim($realParent, '/') . '/' . implode('/', array_reverse($tail));
            }
            $current = $parent;
        }

        return $normalizedAbsolute;
    }

    /**
     * True when $path is a symlink whose target leaves $root.
     *
     * Called per entry while walking a source tree: a symlinked asset that
     * points at /etc/shadow must never reach a manifest.
     */
    public static function isSymlinkEscape(string $root, string $path): bool
    {
        if (!is_link($path)) {
            return false;
        }
        $realRoot = realpath($root);
        $realTarget = realpath($path);
        if ($realRoot === false || $realTarget === false) {
            // A dangling symlink resolves nowhere; treat it as an escape so it
            // is excluded rather than silently manifested as a zero-byte asset.
            return true;
        }

        return !self::isContainedIn($realRoot, $realTarget);
    }

    public static function join(string $root, string $relative): string
    {
        return rtrim($root, '/') . '/' . ltrim($relative, '/');
    }
}
