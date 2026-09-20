<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Support\Fs;
use LofAudioSupply\Support\SafePath;
use LofTest\TestCase;

final class SafePathTest extends TestCase
{
    public function testRejectsNulAndControlCharacters(): void
    {
        $this->assertRefused('value.nul_byte', static fn () => SafePath::assertSafeValue("/home/fpp/media\0/evil", 'p'));
        $this->assertRefused('value.control_char', static fn () => SafePath::assertSafeValue("/home/fpp/media\nrm -rf /", 'p'));
        $this->assertRefused('value.control_char', static fn () => SafePath::assertSafeValue("/home/fpp/\r\nmedia", 'p'));
        $this->assertRefused('value.control_char', static fn () => SafePath::assertSafeValue("/home/fpp/media\x1b[2J", 'p'));
    }

    public function testRejectsOptionLookingValues(): void
    {
        // An argument that starts with a dash is how a path becomes a flag.
        $this->assertRefused('value.option_like', static fn () => SafePath::assertSafeValue('--delete', 'p'));
        $this->assertRefused('value.option_like', static fn () => SafePath::assertSafeValue('-e ssh', 'p'));
        $this->assertRefused('value.option_like', static fn () => SafePath::assertSafeValue('--rsh=/bin/sh', 'p'));
    }

    public function testNormalisesTraversalBeforeTouchingTheFilesystem(): void
    {
        $this->assertSame('/home/fpp/media/music', SafePath::normalizeAbsolute('/home/fpp/media/./music/'));
        $this->assertSame('/etc', SafePath::normalizeAbsolute('/home/fpp/media/../../../etc'));
        $this->assertSame('/', SafePath::normalizeAbsolute('/../../..'));
        $this->assertSame('/a/b', SafePath::normalizeAbsolute('/a//b'));
    }

    public function testRejectsRelativeAndEmptyPaths(): void
    {
        $this->assertRefused('path.not_absolute', static fn () => SafePath::normalizeAbsolute('home/fpp/media', 'p'));
        $this->assertRefused('value.empty', static fn () => SafePath::normalizeAbsolute('', 'p'));
    }

    public function testContainmentIsNotAPrefixMatch(): void
    {
        // "/home/fpp/media-evil" must not count as inside "/home/fpp/media".
        $this->assertTrue(SafePath::isContainedIn('/home/fpp/media', '/home/fpp/media'));
        $this->assertTrue(SafePath::isContainedIn('/home/fpp/media', '/home/fpp/media/music'));
        $this->assertFalse(SafePath::isContainedIn('/home/fpp/media', '/home/fpp/media-evil'));
        $this->assertFalse(SafePath::isContainedIn('/home/fpp/media', '/home/fpp'));
        $this->assertFalse(SafePath::isContainedIn('/', '/anything'), 'Containment in / is never an approval.');
    }

    public function testTraversalOutOfAnApprovedRootIsRefused(): void
    {
        $root = $this->makeTempRoot();
        $this->assertRefused('path.outside_root', static fn () => SafePath::assertWithin($root, $root . '/../escape', 'p'));
        $this->assertRefused('path.outside_root', static fn () => SafePath::assertWithin($root, '/etc/shadow', 'p'));
        $this->assertSame($root . '/inside', SafePath::assertWithin($root, $root . '/sub/../inside', 'p'));
    }

    public function testSymlinkEscapeIsRefused(): void
    {
        $root = $this->makeTempRoot();
        mkdir($root . '/approved', 0755, true);
        $outside = $root . '-outside';
        mkdir($outside, 0755, true);
        $this->writeFile($outside . '/secret.txt', 'secret');
        symlink($outside, $root . '/approved/link');

        // Lexically the path is inside; only resolving the link catches it.
        $this->assertSame(
            $root . '/approved/link/secret.txt',
            SafePath::assertWithin($root . '/approved', $root . '/approved/link/secret.txt', 'p')
        );
        $this->assertRefused(
            'path.symlink_escape',
            static fn () => SafePath::assertRealWithin($root . '/approved', $root . '/approved/link/secret.txt', 'p')
        );

        TestCase::removeTree($outside);
    }

    public function testWalkSkipsEscapingSymlinksAndSpecialFiles(): void
    {
        $root = $this->makeTempRoot();
        $source = $root . '/source';
        mkdir($source . '/nested', 0755, true);
        $this->writeFile($source . '/a.mp3', 'a');
        $this->writeFile($source . '/nested/b.mp3', 'b');

        $outside = $root . '-outside';
        mkdir($outside, 0755, true);
        $this->writeFile($outside . '/passwd', 'root:x:0:0');
        symlink($outside . '/passwd', $source . '/escape.mp3');
        symlink($source . '/a.mp3', $source . '/inside-link.mp3');
        symlink($outside . '/does-not-exist', $source . '/dangling.mp3');

        $skipped = [];
        $found = Fs::walkFiles($source, $skipped);

        $this->assertContainsValue('a.mp3', $found);
        $this->assertContainsValue('nested/b.mp3', $found);
        $this->assertContainsValue('inside-link.mp3', $found, 'A symlink that stays inside the root is still an asset.');
        $this->assertNotContainsValue('escape.mp3', $found, 'A symlink to outside the root must never be manifested.');
        $this->assertNotContainsValue('dangling.mp3', $found, 'A dangling symlink must never be manifested.');
        $this->assertSame(2, count($skipped), 'Both refused links should be reported.');

        TestCase::removeTree($outside);
    }

    public function testRelativePathGrammar(): void
    {
        SafePath::assertRelative('music/track 01.mp3');
        SafePath::assertRelative('a/b/c.mp3');

        $this->assertRefused('relative.absolute', static fn () => SafePath::assertRelative('/etc/passwd'));
        $this->assertRefused('relative.dot_segment', static fn () => SafePath::assertRelative('../../etc/passwd'));
        $this->assertRefused('relative.dot_segment', static fn () => SafePath::assertRelative('music/../../etc/passwd'));
        $this->assertRefused('relative.empty_segment', static fn () => SafePath::assertRelative('music//track.mp3'));
        $this->assertRefused('relative.option_like', static fn () => SafePath::assertRelative('music/-rf'));
        $this->assertRefused('value.option_like', static fn () => SafePath::assertRelative('--exclude=*'));
        $this->assertRefused('value.nul_byte', static fn () => SafePath::assertRelative("music/track\0.mp3"));
    }

    public function testRemoveTreeRefusesToLeaveItsGuardRoot(): void
    {
        $root = $this->makeTempRoot();
        mkdir($root . '/guard/inner', 0755, true);
        $this->writeFile($root . '/guard/inner/file.txt', 'x');
        $outside = $root . '/outside';
        mkdir($outside, 0755, true);
        $this->writeFile($outside . '/keep.txt', 'keep');

        $this->assertRefused('fs.remove_outside_guard', static fn () => Fs::removeTree($outside, $root . '/guard'));
        $this->assertRefused('fs.remove_outside_guard', static fn () => Fs::removeTree($root . '/guard', $root . '/guard'));
        $this->assertTrue(is_file($outside . '/keep.txt'));

        Fs::removeTree($root . '/guard/inner', $root . '/guard');
        $this->assertFalse(is_dir($root . '/guard/inner'));
    }

    public function testRemoveTreeUnlinksSymlinksRatherThanFollowingThem(): void
    {
        $root = $this->makeTempRoot();
        mkdir($root . '/guard', 0755, true);
        $treasure = $root . '/treasure';
        mkdir($treasure, 0755, true);
        $this->writeFile($treasure . '/master.mp3', 'irreplaceable');
        symlink($treasure, $root . '/guard/planted');

        Fs::removeTree($root . '/guard/planted', $root . '/guard');

        $this->assertFalse(is_link($root . '/guard/planted'));
        $this->assertTrue(is_file($treasure . '/master.mp3'), 'The link target must survive.');
    }

    public function testAtomicWriteLeavesNoPartialFile(): void
    {
        $root = $this->makeTempRoot();
        $target = $root . '/settings.json';
        Fs::writeFileAtomic($target, '{"a":1}', 0600);
        $this->assertSame('{"a":1}', (string) file_get_contents($target));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($target)), -4));

        Fs::writeFileAtomic($target, '{"a":2}', 0640);
        $this->assertSame('{"a":2}', (string) file_get_contents($target));

        $leftovers = array_values(array_filter(
            (array) scandir($root),
            static fn (string $entry): bool => strpos($entry, '.tmp.') !== false
        ));
        $this->assertCount(0, $leftovers, 'No temporary file should survive a successful write.');
    }
}
