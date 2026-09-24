<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\IntegrityException;
use LofAudioSupply\Support\Json;

/**
 * The publisher side of the frozen phone-audio cross-repository contract V1
 * (contract_sha256 a21c74cc0741b2a783a959aed9f77ba7de3eb278820f322758d76c87bd1e3b13).
 *
 * Documents are built and verified exactly as the contract's normative model
 * does it, in the same check order, so a publication this component writes is
 * refused here for the same first reason lof-core would refuse it. The three
 * schema files under contract/v1/ are byte copies of the frozen schemas and
 * are pinned by digest: an edited schema fails closed rather than quietly
 * changing what "valid" means.
 */
final class Contract
{
    public const CONTRACT_SHA256 = 'a21c74cc0741b2a783a959aed9f77ba7de3eb278820f322758d76c87bd1e3b13';

    public const GEN_RE = '/^\d{8}T\d{6}Z-[0-9a-f]{8}$/';
    public const UTC_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
    public const RID_RE = '/^[0-9a-f]{32}$/';

    public const COMPONENT = 'fpp-lof-audio-sync';
    public const ROLE_VIEWER = 'viewer-rendition';
    public const ROLE_SOURCE_MAP = 'private-source-map';
    public const RENDITION_EXT = '.m4a';
    public const MAX_RENDITION_BYTES = 268435456;

    public const SCHEMA_MANIFEST = 'viewer-rendition-manifest';
    public const SCHEMA_HEALTH = 'publication-health-pointer';
    public const SCHEMA_SOURCE_MAP = 'private-source-map';

    /** sha256 of each frozen schema file, from the contract's expected-digests.json. */
    public const SCHEMA_SHA256 = [
        self::SCHEMA_MANIFEST => '066873979ae405cb034fd72389e54268e6e199d3eebc98b6838949869a75ad90',
        self::SCHEMA_HEALTH => '47716f73b0763e6aadea17fb0c805dcac8e7e8573507db61b6dc799bca2be366',
        self::SCHEMA_SOURCE_MAP => '9d369121f35823e9ef40f1b9a00b720a942c1ece6fe09891cdaefd93750a0692',
    ];

    /** Keys that must never appear anywhere in a viewer manifest or health pointer. */
    public const FORBIDDEN_KEYS = [
        'album', 'artist', 'comment', 'file', 'filename', 'files', 'host', 'master', 'masters',
        'name', 'original', 'path', 'paths', 'publication_root', 'root', 'sequence', 'source',
        'source_name', 'source_path', 'source_rel', 'source_root', 'source_sha256', 'sources',
        'tags', 'title', 'upstream', 'url',
    ];

    /** @var array<string,object>|null */
    private static ?array $schemas = null;

    public static function schemaDir(): string
    {
        return \dirname(__DIR__, 2) . '/contract/v1';
    }

    /**
     * The frozen schemas, digest-checked on first use.
     *
     * @return array<string,object>
     */
    public static function schemas(): array
    {
        if (self::$schemas === null) {
            self::$schemas = self::loadSchemas(self::schemaDir());
        }

        return self::$schemas;
    }

    /**
     * Load and pin the three frozen schemas from $dir.
     *
     * @return array<string,object>
     */
    public static function loadSchemas(string $dir): array
    {
        $out = [];
        foreach (self::SCHEMA_SHA256 as $name => $digest) {
            $path = $dir . '/' . $name . '.v1.schema.json';
            $raw = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
            if ($raw === false || !hash_equals($digest, hash('sha256', $raw))) {
                throw new IntegrityException('viewer.schema_digest', 'A frozen contract schema is missing or altered.', ['schema' => $name]);
            }
            $schema = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (ContractSchema::unsupported($schema) !== []) {
                throw new IntegrityException('viewer.schema_unsupported', 'A contract schema uses an unsupported keyword.', ['schema' => $name]);
            }
            $out[$name] = $schema;
        }

        return $out;
    }

    /** The single V1 profile, taken from the manifest schema's `const`. */
    public static function profile(): array
    {
        $schemas = self::schemas();

        return json_decode(json_encode($schemas[self::SCHEMA_MANIFEST]->properties->profile->const, JSON_THROW_ON_ERROR), true);
    }

    /** @param mixed $v */
    public static function toObj($v)
    {
        return json_decode(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
    }

    /* ------------------------------------------------------ derivations */

    /**
     * Generation-scoped keyed opaque id: exactly 128 bits of
     * HMAC-SHA256(rid_key, canonical(["lof-rid",1,generation,profile_id,source_rel,source_sha256])).
     */
    public static function deriveRid(string $ridKey, string $generation, string $sourceRel, string $sourceSha256): string
    {
        $msg = Json::canonical(['lof-rid', 1, $generation, ViewerProfile::PROFILE_ID, $sourceRel, $sourceSha256]);

        return substr(hash_hmac('sha256', $msg, $ridKey), 0, 32);
    }

    /**
     * True when $rid is (a prefix of) a plain, unkeyed encoding of its source.
     *
     * @param array{source_rel:string,source_sha256:string,rendition_sha256:string} $entry
     */
    public static function ridDerivable(string $rid, array $entry): bool
    {
        $rel = (string) $entry['source_rel'];
        $base = basename($rel);
        $cands = [
            (string) $entry['source_sha256'], (string) $entry['rendition_sha256'],
            hash('sha256', $rel), hash('sha1', $rel), hash('md5', $rel), bin2hex($rel),
            hash('sha256', $base), hash('sha1', $base), hash('md5', $base), bin2hex($base),
            strtolower(self::b64u($rel)), strtolower(self::b64u($base)),
        ];
        foreach ($cands as $c) {
            if (str_contains($c, $rid) || str_contains($rid, substr($c, 0, 16))) {
                return true;
            }
        }

        return false;
    }

    public static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $m */
    public static function manifestDigest(array $m): string
    {
        unset($m['manifest_sha256']);

        return hash('sha256', Json::canonical($m));
    }

    /** @param array<string,mixed> $s */
    public static function sourceMapDigest(array $s): string
    {
        unset($s['map_sha256']);

        return hash('sha256', Json::canonical($s));
    }

    /* ------------------------------------------------------- builders */

    /**
     * @param array<string,array{size:int,sha256:string}> $renditions rid => facts
     * @return array<string,mixed>
     */
    public static function buildManifest(string $generation, string $createdUtc, array $renditions): array
    {
        ksort($renditions, SORT_STRING);
        $total = 0;
        foreach ($renditions as $r) {
            $total += $r['size'];
        }
        $m = [
            'manifest_version' => 1, 'role' => self::ROLE_VIEWER, 'component' => self::COMPONENT,
            'generation' => $generation, 'created_utc' => $createdUtc, 'algorithm' => 'sha256',
            'profile' => self::profile(), 'asset_count' => count($renditions), 'total_bytes' => $total,
            'renditions' => $renditions,
        ];
        $m['manifest_sha256'] = self::manifestDigest($m);

        return $m;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function buildHealth(string $generatedUtc, string $state, ?array $manifest, ?string $previousGeneration): array
    {
        return [
            'health_version' => 1, 'component' => self::COMPONENT, 'role' => self::ROLE_VIEWER,
            'generated_utc' => $generatedUtc, 'state' => $state,
            'current' => $manifest === null ? null : [
                'generation' => $manifest['generation'], 'manifest_sha256' => $manifest['manifest_sha256'],
                'asset_count' => $manifest['asset_count'], 'total_bytes' => $manifest['total_bytes'],
                'profile_id' => ViewerProfile::PROFILE_ID,
            ],
            'previous_generation' => $previousGeneration,
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,array{source_rel:string,source_size:int,source_sha256:string,rendition_size:int,rendition_sha256:string}> $entries
     * @return array<string,mixed>
     */
    public static function buildSourceMap(array $manifest, string $createdUtc, string $ridKeyId, string $supplyGeneration, string $supplyManifestSha256, array $entries): array
    {
        ksort($entries, SORT_STRING);
        $s = [
            'source_map_version' => 1, 'role' => self::ROLE_SOURCE_MAP, 'component' => self::COMPONENT,
            'generation' => $manifest['generation'], 'created_utc' => $createdUtc, 'algorithm' => 'sha256',
            'profile_id' => ViewerProfile::PROFILE_ID, 'rid_key_id' => $ridKeyId,
            'supply_generation' => $supplyGeneration, 'supply_manifest_sha256' => $supplyManifestSha256,
            'viewer_manifest_sha256' => $manifest['manifest_sha256'], 'entries' => $entries,
        ];
        $s['map_sha256'] = self::sourceMapDigest($s);

        return $s;
    }

    /* ---------------------------------------------- document validation */

    /** @param mixed $v */
    public static function forbiddenKey($v): ?string
    {
        if (!is_array($v)) {
            return null;
        }
        foreach ($v as $k => $child) {
            if (is_string($k) && in_array(strtolower($k), self::FORBIDDEN_KEYS, true)) {
                return $k;
            }
            $f = self::forbiddenKey($child);
            if ($f !== null) {
                return $f;
            }
        }

        return null;
    }

    /** `SafePath::assertRelative()` as a predicate. */
    public static function safeRelative(string $r): bool
    {
        if ($r === '' || strlen($r) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $r) === 1
            || $r[0] === '-' || $r[0] === '/' || preg_match('//u', $r) !== 1) {
            return false;
        }
        foreach (explode('/', $r) as $s) {
            if ($s === '' || $s === '.' || $s === '..' || $s[0] === '-' || strlen($s) > 255) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $m */
    public static function validateManifest(array $m): string
    {
        if (!array_key_exists('manifest_version', $m) || !is_int($m['manifest_version']) || $m['manifest_version'] !== 1) {
            return 'manifest_version';
        }
        if (self::forbiddenKey($m) !== null) {
            return 'manifest_source_leak';
        }
        if (($m['role'] ?? null) !== self::ROLE_VIEWER || ($m['component'] ?? null) !== self::COMPONENT) {
            return 'manifest_role';
        }
        if (($m['algorithm'] ?? null) !== 'sha256') {
            return 'manifest_algorithm';
        }
        if (!is_string($m['generation'] ?? null) || preg_match(self::GEN_RE, $m['generation']) !== 1) {
            return 'manifest_generation';
        }
        if (!is_array($m['renditions'] ?? null) || $m['renditions'] === [] || array_is_list($m['renditions'])) {
            return 'manifest_schema';
        }
        foreach (array_keys($m['renditions']) as $k) {
            if (!is_string($k) || preg_match(self::RID_RE, $k) !== 1) {
                return 'manifest_rendition_id';
            }
        }
        if (ContractSchema::errors(self::toObj($m), self::schemas()[self::SCHEMA_MANIFEST]) !== []) {
            return 'manifest_schema';
        }
        $bytes = 0;
        foreach ($m['renditions'] as $e) {
            $bytes += $e['size'];
        }
        if ($m['asset_count'] !== count($m['renditions']) || $m['total_bytes'] !== $bytes) {
            return 'manifest_counter';
        }
        if (!hash_equals(self::manifestDigest($m), (string) $m['manifest_sha256'])) {
            return 'manifest_digest';
        }

        return 'ok';
    }

    /** @param array<string,mixed> $h */
    public static function validateHealth(array $h): string
    {
        if (!array_key_exists('health_version', $h) || !is_int($h['health_version']) || $h['health_version'] !== 1) {
            return 'health_version';
        }
        if (self::forbiddenKey($h) !== null) {
            return 'health_source_leak';
        }
        if (($h['component'] ?? null) !== self::COMPONENT || ($h['role'] ?? null) !== self::ROLE_VIEWER) {
            return 'health_component';
        }
        if (is_array($h['current'] ?? null) && (!is_string($h['current']['generation'] ?? null) || preg_match(self::GEN_RE, $h['current']['generation']) !== 1)) {
            return 'health_generation';
        }
        if (ContractSchema::errors(self::toObj($h), self::schemas()[self::SCHEMA_HEALTH]) !== []) {
            return 'health_schema';
        }
        if ($h['state'] !== 'ok') {
            return 'health_state';
        }
        if ($h['current'] === null) {
            return 'health_no_current';
        }

        return 'ok';
    }

    /**
     * @param array<string,mixed> $h
     * @param array<string,mixed> $m
     */
    public static function checkPublication(array $h, array $m): string
    {
        $c = $h['current'];
        if (!hash_equals($c['generation'], $m['generation'])) {
            return 'publication_generation_drift';
        }
        if (!hash_equals($c['manifest_sha256'], $m['manifest_sha256'])) {
            return 'publication_health_digest';
        }
        if ($c['asset_count'] !== $m['asset_count'] || $c['total_bytes'] !== $m['total_bytes'] || $c['profile_id'] !== $m['profile']['profile_id']) {
            return 'publication_health_counter';
        }

        return 'ok';
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $m
     */
    public static function validateSourceMap(array $s, array $m): string
    {
        if (!array_key_exists('source_map_version', $s) || !is_int($s['source_map_version']) || $s['source_map_version'] !== 1) {
            return 'source_map_version';
        }
        if (($s['role'] ?? null) !== self::ROLE_SOURCE_MAP || ($s['component'] ?? null) !== self::COMPONENT) {
            return 'source_map_role';
        }
        if (($s['algorithm'] ?? null) !== 'sha256') {
            return 'source_map_algorithm';
        }
        if (ContractSchema::errors(self::toObj($s), self::schemas()[self::SCHEMA_SOURCE_MAP]) !== []) {
            return 'source_map_schema';
        }
        foreach ($s['entries'] as $e) {
            if (!self::safeRelative($e['source_rel'])) {
                return 'source_map_path';
            }
        }
        if (!hash_equals($m['generation'], $s['generation'])) {
            return 'source_map_generation';
        }
        if (!hash_equals($m['manifest_sha256'], $s['viewer_manifest_sha256'])) {
            return 'source_map_manifest_digest';
        }
        if (!hash_equals(self::sourceMapDigest($s), $s['map_sha256'])) {
            return 'source_map_digest';
        }
        $a = array_map('strval', array_keys($s['entries']));
        $b = array_map('strval', array_keys($m['renditions']));
        sort($a, SORT_STRING);
        sort($b, SORT_STRING);
        if ($a !== $b) {
            return 'source_map_coverage';
        }
        $seen = [];
        foreach ($s['entries'] as $rid => $e) {
            $r = $m['renditions'][$rid];
            if ($e['rendition_size'] !== $r['size'] || !hash_equals($r['sha256'], $e['rendition_sha256'])) {
                return 'source_map_rendition_drift';
            }
            if (isset($seen[$e['source_rel']])) {
                return 'source_map_duplicate_source';
            }
            $seen[$e['source_rel']] = true;
            if (hash_equals($e['source_sha256'], $e['rendition_sha256'])) {
                return 'source_map_not_transcoded';
            }
            if (self::ridDerivable((string) $rid, $e)) {
                return 'rid_derivable';
            }
        }

        return 'ok';
    }

    /**
     * @param array<string,mixed> $m
     * @param array{root_public:bool,entries:array<string,array{type:string,within_root:bool,size:int,sha256:string}>} $obs
     */
    public static function checkObservation(array $m, array $obs): string
    {
        if ($obs['root_public']) {
            return 'publication_root_public';
        }
        $rids = array_map('strval', array_keys($m['renditions']));
        sort($rids, SORT_STRING);
        foreach ($rids as $rid) {
            $name = $rid . self::RENDITION_EXT;
            $o = $obs['entries'][$name] ?? null;
            if ($o === null) {
                return 'file_missing';
            }
            if ($o['type'] === 'symlink') {
                return 'file_symlink';
            }
            if ($o['type'] !== 'file') {
                return 'file_type';
            }
            if (!$o['within_root']) {
                return 'file_outside_root';
            }
            if ($o['size'] !== $m['renditions'][$rid]['size']) {
                return 'file_size_drift';
            }
            if (!hash_equals($m['renditions'][$rid]['sha256'], $o['sha256'])) {
                return 'file_digest_drift';
            }
        }
        foreach (array_keys($obs['entries']) as $name) {
            $rid = substr((string) $name, 0, -strlen(self::RENDITION_EXT));
            if (!str_ends_with((string) $name, self::RENDITION_EXT) || !isset($m['renditions'][$rid])) {
                return 'file_unexpected';
            }
        }

        return 'ok';
    }

    /**
     * The contract's complete consumer sequence. A null document is absent.
     *
     * @param array<string,mixed>|null $h
     * @param array<string,mixed>|null $m
     * @param array<string,mixed>|null $s
     * @param array<string,mixed>|null $o
     * @return array{0:string,1:string} [stage, reason]; ['ok','ok'] only when every step holds
     */
    public static function pipeline(?array $h, ?array $m, ?array $s, ?array $o): array
    {
        if ($h === null) {
            return ['health', 'health_missing'];
        }
        $r = self::validateHealth($h);
        if ($r !== 'ok') {
            return ['health', $r];
        }
        if ($m === null) {
            return ['manifest', 'manifest_missing'];
        }
        $r = self::validateManifest($m);
        if ($r !== 'ok') {
            return ['manifest', $r];
        }
        $r = self::checkPublication($h, $m);
        if ($r !== 'ok') {
            return ['publication', $r];
        }
        if ($s === null) {
            return ['source_map', 'source_map_missing'];
        }
        $r = self::validateSourceMap($s, $m);
        if ($r !== 'ok') {
            return ['source_map', $r];
        }
        if ($o === null) {
            return ['observation', 'file_missing'];
        }
        $r = self::checkObservation($m, $o);
        if ($r !== 'ok') {
            return ['observation', $r];
        }

        return ['ok', 'ok'];
    }

    /**
     * Observe a rendition directory the way lof-core will: lstat each entry
     * (dotfiles included), real-path containment, size, and full digest.
     *
     * @return array{root_public:bool,entries:array<string,array{type:string,within_root:bool,size:int,sha256:string}>}|null
     */
    public static function observe(string $dir, bool $rootPublic): ?array
    {
        if (is_link($dir) || !is_dir($dir)) {
            return null;
        }
        $realDir = realpath($dir);
        $names = @scandir($dir);
        if ($realDir === false || $names === false) {
            return null;
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            $type = is_link($path) ? 'symlink' : (is_dir($path) ? 'dir' : (is_file($path) ? 'file' : 'other'));
            $real = realpath($path);
            $within = $real !== false && strncmp($real, $realDir . '/', strlen($realDir) + 1) === 0;
            $size = $type === 'file' ? (int) @filesize($path) : 0;
            $sha = $type === 'file' ? (string) @hash_file('sha256', $path) : '';
            $entries[$name] = ['type' => $type, 'within_root' => $within, 'size' => $size, 'sha256' => $sha];
        }

        return ['root_public' => $rootPublic, 'entries' => $entries];
    }
}
