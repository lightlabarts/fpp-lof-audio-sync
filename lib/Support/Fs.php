<?php

declare(strict_types=1);

namespace LofAudioSupply\Support;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\PublishException;

/**
 * Filesystem primitives with durability and containment built in.
 *
 * Every mutation here is either atomic or explicitly reversible, because the
 * active media generation must survive a power cut in the middle of a publish.
 */
final class Fs
{
    /** Refuse to walk a source tree deeper than this. */
    public const MAX_WALK_DEPTH = 24;

    /** Refuse to manifest more assets than this in one generation. */
    public const MAX_WALK_ENTRIES = 100000;

    public static function ensureDir(string $path, int $mode = 0750): void
    {
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path)) {
            throw new PublishException('fs.not_a_directory', 'Path exists and is not a directory.', ['path' => basename($path)]);
        }
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new PublishException('fs.mkdir_failed', 'Directory could not be created.', ['path' => basename($path)]);
        }
    }

    /**
     * Write via a same-directory temp file, fsync the data, then rename.
     *
     * The rename is the atomic step; the fsync before it is what makes the
     * rename meaningful after a crash.
     */
    public static function writeFileAtomic(string $path, string $contents, int $mode = 0640): void
    {
        $dir = \dirname($path);
        self::ensureDir($dir);
        $tmp = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));

        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new PublishException('fs.temp_open_failed', 'Temporary file could not be created.', ['path' => basename($path)]);
        }
        try {
            $written = @fwrite($handle, $contents);
            if ($written === false || $written !== strlen($contents)) {
                throw new PublishException('fs.write_failed', 'File could not be written in full.', ['path' => basename($path)]);
            }
            if (!@fflush($handle)) {
                throw new PublishException('fs.flush_failed', 'File could not be flushed.', ['path' => basename($path)]);
            }
            self::fsyncHandle($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);

        if (!@chmod($tmp, $mode)) {
            @unlink($tmp);
            throw new PublishException('fs.chmod_failed', 'File mode could not be set.', ['path' => basename($path)]);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new PublishException('fs.rename_failed', 'File could not be published atomically.', ['path' => basename($path)]);
        }
        self::fsyncDir($dir);
    }

    /** @param resource $handle */
    public static function fsyncHandle($handle): void
    {
        // fsync() landed in PHP 8.1; on an older build the fflush above is the
        // best available guarantee and the rename is still atomic.
        if (function_exists('fsync')) {
            @fsync($handle);
        }
    }

    /**
     * fsync the directory so a rename survives a power cut.
     */
    public static function fsyncDir(string $dir): void
    {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($dir, 'r');
        if ($handle === false) {
            return;
        }
        @fsync($handle);
        fclose($handle);
    }

    /**
     * Atomically point $linkPath at $target.
     *
     * A symlink cannot be rewritten in place, so a uniquely named sibling link
     * is created and renamed over the existing one. Readers either see the old
     * generation or the new one, never a missing path.
     */
    public static function atomicSymlink(string $target, string $linkPath): void
    {
        $dir = \dirname($linkPath);
        self::ensureDir($dir);
        if (file_exists($linkPath) && !is_link($linkPath) && is_dir($linkPath)) {
            throw new PublishException(
                'fs.symlink_target_is_dir',
                'Refusing to replace a real directory with a symlink.',
                ['path' => basename($linkPath)]
            );
        }
        $tmp = $dir . '/.' . basename($linkPath) . '.tmp.' . bin2hex(random_bytes(8));
        if (!@symlink($target, $tmp)) {
            throw new PublishException('fs.symlink_failed', 'Symlink could not be created.', ['path' => basename($linkPath)]);
        }
        if (!@rename($tmp, $linkPath)) {
            @unlink($tmp);
            throw new PublishException('fs.symlink_swap_failed', 'Symlink could not be swapped atomically.', ['path' => basename($linkPath)]);
        }
        self::fsyncDir($dir);
    }

    public static function readSymlink(string $linkPath): ?string
    {
        if (!is_link($linkPath)) {
            return null;
        }
        $target = @readlink($linkPath);

        return $target === false ? null : $target;
    }

    /**
     * Enumerate regular files under $root as root-relative paths.
     *
     * Symlinks that resolve outside $root are skipped and reported; devices,
     * sockets, and FIFOs are skipped too. Nothing that is not a plain readable
     * file can enter a manifest.
     *
     * @param list<string> $skipped receives entries that were refused
     * @return list<string> sorted, root-relative paths
     */
    public static function walkFiles(string $root, array &$skipped = []): array
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw new PublishException('fs.source_missing', 'Source directory does not exist.', ['path' => basename($root)]);
        }

        $files = [];
        $stack = [['', 0]];
        $seen = 0;

        while ($stack !== []) {
            [$relativeDir, $depth] = array_pop($stack);
            if ($depth > self::MAX_WALK_DEPTH) {
                $skipped[] = $relativeDir . ' (max depth)';
                continue;
            }
            $absoluteDir = $relativeDir === '' ? $realRoot : $realRoot . '/' . $relativeDir;
            $entries = @scandir($absoluteDir);
            if ($entries === false) {
                $skipped[] = $relativeDir . ' (unreadable)';
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $relative = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;
                $absolute = $absoluteDir . '/' . $entry;

                if (++$seen > self::MAX_WALK_ENTRIES) {
                    throw new PublishException('fs.too_many_entries', 'Source tree exceeds the supported asset count.');
                }
                if (SafePath::isSymlinkEscape($realRoot, $absolute)) {
                    $skipped[] = $relative . ' (symlink outside source root)';
                    continue;
                }
                if (is_dir($absolute)) {
                    $stack[] = [$relative, $depth + 1];
                    continue;
                }
                if (!is_file($absolute)) {
                    $skipped[] = $relative . ' (not a regular file)';
                    continue;
                }
                try {
                    SafePath::assertRelative($relative);
                } catch (\Throwable $e) {
                    $skipped[] = $relative . ' (unsafe name)';
                    continue;
                }
                $files[] = $relative;
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Copy a file durably, preserving mtime, creating parents as needed.
     */
    public static function copyFileDurable(string $source, string $destination): void
    {
        self::ensureDir(\dirname($destination));
        $tmp = $destination . '.part.' . bin2hex(random_bytes(6));

        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new PublishException('fs.source_unreadable', 'Source file could not be opened.', ['path' => basename($source)]);
        }
        $out = @fopen($tmp, 'xb');
        if ($out === false) {
            fclose($in);
            throw new PublishException('fs.dest_unwritable', 'Destination file could not be created.', ['path' => basename($destination)]);
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new PublishException('fs.read_failed', 'Source file could not be read.', ['path' => basename($source)]);
                }
                if ($chunk === '') {
                    continue;
                }
                $written = @fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new PublishException('fs.short_write', 'Destination write was short (disk full?).', ['path' => basename($destination)]);
                }
            }
            if (!@fflush($out)) {
                throw new PublishException('fs.flush_failed', 'Destination could not be flushed.', ['path' => basename($destination)]);
            }
            self::fsyncHandle($out);
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($in);
        fclose($out);

        $mtime = @filemtime($source);
        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            throw new PublishException('fs.rename_failed', 'Destination could not be published.', ['path' => basename($destination)]);
        }
        @chmod($destination, 0640);
        if ($mtime !== false) {
            @touch($destination, $mtime);
        }
    }

    /**
     * Recursively delete $path, refusing anything outside $guardRoot.
     *
     * Symlinks are unlinked rather than followed, so a planted link cannot turn
     * a quarantine prune into a delete of the real media tree.
     */
    public static function removeTree(string $path, string $guardRoot): void
    {
        $normalizedGuard = SafePath::normalizeAbsolute($guardRoot, 'guard_root');
        $normalized = SafePath::normalizeAbsolute($path, 'remove_path');
        if ($normalized === $normalizedGuard || !SafePath::isContainedIn($normalizedGuard, $normalized)) {
            throw new PolicyViolationException(
                'fs.remove_outside_guard',
                'Refusing to delete a path outside the guarded root.',
                ['path' => basename($path)]
            );
        }
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::removeTree($path . '/' . $entry, $guardRoot);
            }
        }
        @rmdir($path);
    }

    public static function freeBytes(string $path): ?float
    {
        $probe = is_dir($path) ? $path : \dirname($path);
        $free = @disk_free_space($probe);

        return $free === false ? null : (float) $free;
    }

    public static function sha256File(string $path): string
    {
        $digest = @hash_file('sha256', $path);
        if ($digest === false) {
            throw new PublishException('fs.hash_failed', 'File could not be hashed.', ['path' => basename($path)]);
        }

        return $digest;
    }
}
