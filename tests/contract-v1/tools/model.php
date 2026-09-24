<?php
/**
 * Phone-audio cross-repository contract V1 — executable reference model.
 *
 * This file IS the contract's semantics in code. Both implementations
 * (`fpp-lof-audio-sync` producer, `lof-core` consumer) must produce the same
 * reason codes, statuses, headers, claims, tokens and digests this model
 * produces for every vector in ../vectors. Check order is normative: when a
 * document or request is wrong in several ways, the FIRST failing check names
 * the reason.
 *
 * PHP >= 8.1, core + hash + json + pcre only. No dependency.
 */

declare(strict_types=1);

namespace LofPhoneAudioContractV1;

final class Model
{
    public const CONTRACT = 'lof-phone-audio-cross-repo-contract';
    public const CONTRACT_VERSION = 1;

    public const GEN_RE = '/^\d{8}T\d{6}Z-[0-9a-f]{8}$/';
    public const UTC_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
    public const RID_RE = '/^[0-9a-f]{32}$/';
    public const HEX32_RE = '/^[0-9a-f]{32}$/';
    public const HEX64_RE = '/^[0-9a-f]{64}$/';
    public const SESSION_RE = '/^[A-Za-z0-9_-]{22,64}$/';
    public const B64U_RE = '/^[A-Za-z0-9_-]+$/';

    public const COMPONENT = 'fpp-lof-audio-sync';
    public const ROLE_VIEWER = 'viewer-rendition';
    public const ROLE_SOURCE_MAP = 'private-source-map';
    public const PROFILE_ID = 'lof-viewer-aac-lc-m4a-v1';
    public const RENDITION_EXT = '.m4a';

    public const MEDIA_PREFIX = '/wp-json/lof-core/v1/audio/media/';
    public const AUDIO_PATH = '/wp-json/lof-core/v1/audio/';
    public const GRANT_COOKIE = 'lof_ag';
    public const SESSION_COOKIE = 'lof_as';
    public const TOKEN_PREFIX = 'lofg1';
    public const GRANT_TYP = 'lof-audio-grant';
    public const TTL_CEILING_S = 1800;
    public const DISPOSITION = 'inline; filename="lof-listen.m4a"';
    public const MIME = 'audio/mp4';

    /** Keys that must never appear anywhere in a viewer manifest or health pointer. */
    public const FORBIDDEN_KEYS = [
        'album', 'artist', 'comment', 'file', 'filename', 'files', 'host', 'master', 'masters',
        'name', 'original', 'path', 'paths', 'publication_root', 'root', 'sequence', 'source',
        'source_name', 'source_path', 'source_rel', 'source_root', 'source_sha256', 'sources',
        'tags', 'title', 'upstream', 'url',
    ];

    /* ------------------------------------------------------------ encoding */

    /** Byte-identical to `LofAudioSupply\Support\Json::canonical()` and `LOF_Audio_Manifest::canonical()`. */
    public static function canonical($value): string
    {
        return json_encode(
            self::sortRec($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            64
        );
    }

    private static function sortRec($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortRec($v);
        }
        if (!$isList) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }

    public static function sha(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): ?string
    {
        if ($s === '' || preg_match(self::B64U_RE, $s) !== 1 || strlen($s) % 4 === 1) {
            return null;
        }
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return ($d === false || self::b64u($d) !== $s) ? null : $d;
    }

    /** Assoc array -> stdClass tree, for schema validation. */
    public static function toObj($v)
    {
        return json_decode(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
    }

    private static function without(array $doc, string $field): array
    {
        unset($doc[$field]);
        return $doc;
    }

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

    /** `LofAudioSupply\Support\SafePath::assertRelative()` as a predicate. */
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

    /* ------------------------------------------------- producer derivations */

    /** Generation-scoped keyed opaque id. Producer-only: lof-core never holds the rid key. */
    public static function deriveRid(string $ridKeyHex, string $generation, string $sourceRel, string $sourceSha256): string
    {
        $msg = self::canonical(['lof-rid', 1, $generation, self::PROFILE_ID, $sourceRel, $sourceSha256]);
        return substr(hash_hmac('sha256', $msg, (string) hex2bin($ridKeyHex)), 0, 32);
    }

    /** Is this rid a (prefix of a) plain, unkeyed encoding of its source? Consumer-checkable. */
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

    public static function manifestDigest(array $m): string
    {
        return self::sha(self::canonical(self::without($m, 'manifest_sha256')));
    }

    public static function sourceMapDigest(array $s): string
    {
        return self::sha(self::canonical(self::without($s, 'map_sha256')));
    }

    /* ---------------------------------------------- document validation */

    public static function validateManifest(array $m, $schema): string
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
        if (Schema::errors(self::toObj($m), $schema) !== []) {
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

    public static function validateHealth(array $h, $schema): string
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
        if (Schema::errors(self::toObj($h), $schema) !== []) {
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

    /** Health is the authority on which generation is current; the manifest must agree exactly. */
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

    public static function validateSourceMap(array $s, array $m, $schema): string
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
        if (Schema::errors(self::toObj($s), $schema) !== []) {
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
        $a = array_keys($s['entries']);
        $b = array_keys($m['renditions']);
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
     * What lof-core observed beneath `<viewer_root>/generations/<gen>/`:
     *   { root_public: bool, entries: { "<name>": { type: file|symlink|dir|other, within_root: bool, size: int, sha256: hex } } }
     */
    public static function checkObservation(array $m, array $obs): string
    {
        if ($obs['root_public']) {
            return 'publication_root_public';
        }
        $rids = array_keys($m['renditions']);
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
     * The complete lof-core read-only consumer sequence. Any null document is
     * "absent". Returns [stage, reason]; ['ok','ok'] only when every step holds.
     */
    public static function pipeline(?array $h, ?array $m, ?array $s, ?array $o, array $schemas): array
    {
        if ($h === null) {
            return ['health', 'health_missing'];
        }
        $r = self::validateHealth($h, $schemas['publication-health-pointer']);
        if ($r !== 'ok') {
            return ['health', $r];
        }
        if ($m === null) {
            return ['manifest', 'manifest_missing'];
        }
        $r = self::validateManifest($m, $schemas['viewer-rendition-manifest']);
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
        $r = self::validateSourceMap($s, $m, $schemas['private-source-map']);
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

    /* ------------------------------------------------------------ grants */

    public static function sub(string $grantKeyHex, string $session): string
    {
        return substr(hash_hmac('sha256', 'lof-sub|v1|' . $session, (string) hex2bin($grantKeyHex)), 0, 32);
    }

    public static function mint(array $claims, string $grantKeyHex): string
    {
        $body = self::b64u(self::canonical($claims));
        $mac = self::b64u(hash_hmac('sha256', self::TOKEN_PREFIX . '.' . $body, (string) hex2bin($grantKeyHex), true));
        return self::TOKEN_PREFIX . '.' . $body . '.' . $mac;
    }

    /** @return array{0:string,1:?array} reason, claims */
    public static function parseToken(string $token, string $grantKeyHex, string $kid, $claimsSchema): array
    {
        $p = explode('.', $token);
        if (count($p) !== 3) {
            return ['grant_malformed', null];
        }
        if ($p[0] !== self::TOKEN_PREFIX) {
            return ['grant_version', null];
        }
        $raw = self::unb64u($p[1]);
        $mac = self::unb64u($p[2]);
        if ($raw === null || $mac === null) {
            return ['grant_malformed', null];
        }
        if (!hash_equals(hash_hmac('sha256', self::TOKEN_PREFIX . '.' . $p[1], (string) hex2bin($grantKeyHex), true), $mac)) {
            return ['grant_signature', null];
        }
        $c = json_decode($raw, true, 16);
        if (!is_array($c) || array_is_list($c)) {
            return ['grant_malformed', null];
        }
        if (($c['typ'] ?? null) !== self::GRANT_TYP || ($c['ver'] ?? null) !== 1 || ($c['kid'] ?? null) !== $kid) {
            return ['grant_version', null];
        }
        if (Schema::errors(self::toObj($c), $claimsSchema) !== []) {
            return ['grant_malformed', null];
        }
        if (!($c['iat'] <= $c['nbf'] && $c['nbf'] < $c['exp'] && $c['exp'] - $c['iat'] <= self::TTL_CEILING_S)) {
            return ['grant_malformed', null];
        }
        return ['ok', $c];
    }

    /**
     * POST /audio/grant. The client names only its session and a context; the
     * server alone chooses the item and therefore the rendition.
     *
     * $ctx: constants (keys, kid, policy, map, index, sizes).  $state: live facts.
     */
    public static function issue(array $ctx, array $state, array $req): array
    {
        $p = $ctx['policy'];
        $now = $state['now'];
        $no = static function (string $reason): array {
            return ['ok' => false, 'reason' => $reason, 'claims' => null, 'token' => null, 'response' => [
                'status' => 200,
                'body' => ['ok' => false, 'reason' => $reason, 'fallback' => 'fm', 'autoplay' => false],
                'set_cookie' => [],
            ]];
        };
        if ($state['disabled']) {
            return $no('issue_disabled');
        }
        if (!$state['publication_ok']) {
            return $no('issue_publication');
        }
        $session = $req['session'] ?? null;
        if (!is_string($session) || preg_match(self::SESSION_RE, $session) !== 1) {
            return $no('issue_session');
        }
        $want = $req['ctx'] ?? null;
        if ($want !== 'current' && $want !== 'next') {
            return $no('issue_ctx');
        }
        $cur = $state['current'];
        if ($cur === null) {
            return $no('issue_nothing_playing');
        }
        $age = $now - $cur['observed_at'];
        if ($age < 0 || $age > $p['playhead_max_age_s']) {
            return $no('issue_playhead_stale');
        }
        if (!is_int($cur['remaining_s'])) {
            return $no('issue_remaining_unknown');
        }
        $remainingNow = max(0, $cur['remaining_s'] - $age);
        $target = $cur;
        if ($want === 'next') {
            $nx = $state['next'];
            if ($nx === null || $nx['authoritative'] !== true) {
                return $no('issue_next_not_authoritative');
            }
            if ($remainingNow > $p['preload_lead_s']) {
                return $no('issue_next_too_early');
            }
            $target = $nx;
        }
        $rel = $ctx['map'][$target['name']] ?? null;
        if (!is_string($rel)) {
            return $no('issue_unmapped');
        }
        $rid = $ctx['index'][$rel] ?? null;
        if (!is_string($rid) || !isset($ctx['sizes'][$rid])) {
            return $no('issue_unpublished');
        }
        if ($want === 'next') {
            $curRel = $ctx['map'][$cur['name']] ?? null;
            if (is_string($curRel) && ($ctx['index'][$curRel] ?? null) === $rid) {
                return $no('issue_next_same_rendition');
            }
        }
        $size = $ctx['sizes'][$rid];
        $win = $p['window_bytes'];
        if ($want === 'current') {
            $b = $p['current'];
            $bytes = intdiv($size * $b['bytes_num'], $b['bytes_den']) + $b['bytes_extra_windows'] * $win;
        } else {
            $b = $p['next'];
            $bytes = min($size, $b['bytes_windows'] * $win);
        }
        $exp = $now + min($p['max_ttl_s'], $remainingNow + $p['recovery_margin_s']);
        $claims = [
            'typ' => self::GRANT_TYP, 'ver' => 1, 'kid' => $ctx['kid'],
            'sub' => self::sub($ctx['grant_key_hex'], $session),
            'rid' => $rid, 'item' => $target['item_id'], 'ctx' => $want, 'gen' => $state['generation'],
            'iat' => $now, 'nbf' => $now, 'exp' => $exp, 'jti' => $state['next_jti'], 'epoch' => $state['epoch'],
            'bud' => ['req' => $b['req'], 'bytes' => $bytes, 'conc' => $b['conc'], 'rate_n' => $b['rate_n'], 'rate_s' => $b['rate_s'], 'win' => $win],
        ];
        $token = self::mint($claims, $ctx['grant_key_hex']);
        $media = self::MEDIA_PREFIX . $rid;
        return ['ok' => true, 'reason' => 'ok', 'claims' => $claims, 'token' => $token, 'response' => [
            'status' => 200,
            'body' => [
                'ok' => true, 'reason' => 'ok', 'ctx' => $want, 'media' => $media,
                'expires_at' => $exp, 'renew_at' => max($now, $exp - $p['renew_lead_s']), 'server_time' => $now,
                'item' => ['key' => $target['item_id'], 'title' => $target['title']],
                'fallback' => 'fm', 'autoplay' => false,
            ],
            'set_cookie' => [
                self::GRANT_COOKIE . '=' . $token . '; Path=' . $media . '; Max-Age=' . ($exp - $now) . '; Secure; HttpOnly; SameSite=Strict',
                self::SESSION_COOKIE . '=' . $session . '; Path=' . self::AUDIO_PATH . '; Max-Age=' . $p['session_cookie_max_age_s'] . '; Secure; HttpOnly; SameSite=Strict',
            ],
        ]];
    }

    /* ------------------------------------------------------------- media */

    /**
     * GET|HEAD /wp-json/lof-core/v1/audio/media/<rid>. Pure decision; the byte
     * pump serves exactly `range` of the verified file and nothing else.
     *
     * $ctx adds `own_origin`, `bytes` (rid => file bytes, for body digests).
     * $state: now, disabled, publication_ok, generation, epoch, revoked_jti[],
     *         current_item|null, next_item|null, usage{jti:{requests,bytes,in_flight,recent[]}}
     */
    public static function media(array $ctx, array $state, array $req, $claimsSchema): array
    {
        $h = [];
        foreach (($req['headers'] ?? []) as $k => $v) {
            $h[strtolower((string) $k)] = (string) $v;
        }
        $own = $ctx['own_origin'];
        $origin = $h['origin'] ?? null;
        $cors = [];
        if ($origin !== null) {
            $cors = strcasecmp($origin, $own) === 0 ? ['Access-Control-Allow-Origin' => $own, 'Vary' => 'Origin'] : ['Vary' => 'Origin'];
        }
        $claims = null;
        $no = static function (int $status, string $reason, int $spendReq = 0, ?int $size = null) use (&$cors, &$claims): array {
            $hd = $cors + [
                'Cache-Control' => 'private, no-store',
                'Content-Length' => '0',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($status === 416) {
                $hd['Accept-Ranges'] = 'bytes';
                $hd['Content-Range'] = 'bytes */' . $size;
            }
            ksort($hd, SORT_STRING);
            return [
                'status' => $status, 'reason' => $reason, 'headers' => $hd, 'range' => null,
                'spend' => ['requests' => $spendReq, 'bytes' => 0], 'body_sha256' => null,
                'telemetry' => ['event' => 'lof_audio_media', 'status' => $status, 'reason' => $reason, 'ctx' => $claims['ctx'] ?? null, 'bytes' => 0],
            ];
        };

        $method = (string) ($req['method'] ?? '');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $no(404, 'media_method');
        }
        if ($origin !== null && strcasecmp($origin, $own) !== 0) {
            return $no(403, 'media_cross_origin');
        }
        if (isset($h['sec-fetch-site']) && $h['sec-fetch-site'] !== 'same-origin') {
            return $no(403, 'media_cross_origin');
        }
        $path = (string) ($req['path'] ?? '');
        $seg = str_starts_with($path, self::MEDIA_PREFIX) ? substr($path, strlen(self::MEDIA_PREFIX)) : '';
        if (preg_match(self::RID_RE, $seg) !== 1) {
            return $no(404, 'media_id_shape');
        }
        if ($state['disabled']) {
            return $no(503, 'media_disabled');
        }
        if (!$state['publication_ok']) {
            return $no(503, 'media_publication_unavailable');
        }
        $token = $req['cookies'][self::GRANT_COOKIE] ?? '';
        if (!is_string($token) || $token === '') {
            return $no(404, 'grant_missing');
        }
        [$r, $c] = self::parseToken($token, $ctx['grant_key_hex'], $ctx['kid'], $claimsSchema);
        if ($r !== 'ok') {
            return $no(404, $r);
        }
        if (!hash_equals($c['rid'], $seg)) {
            return $no(404, 'grant_rid_mismatch');
        }
        $claims = $c;
        $session = $req['cookies'][self::SESSION_COOKIE] ?? '';
        if (!is_string($session) || preg_match(self::SESSION_RE, $session) !== 1 || !hash_equals($c['sub'], self::sub($ctx['grant_key_hex'], $session))) {
            return $no(403, 'grant_wrong_listener');
        }
        if ($c['epoch'] !== $state['epoch'] || in_array($c['jti'], $state['revoked_jti'], true)) {
            return $no(401, 'grant_revoked');
        }
        if ($c['gen'] !== $state['generation']) {
            return $no(401, 'grant_generation');
        }
        if (!isset($ctx['sizes'][$seg])) {
            return $no(404, 'media_unpublished');
        }
        $now = $state['now'];
        if ($now < $c['nbf']) {
            return $no(401, 'grant_not_yet_valid');
        }
        if ($now >= $c['exp']) {
            return $no(401, 'grant_expired');
        }
        $item = $c['ctx'] === 'current' ? $state['current_item'] : $state['next_item'];
        if (!is_string($item) || !hash_equals($item, $c['item'])) {
            return $no(401, 'grant_wrong_item');
        }
        $u = ($state['usage'][$c['jti']] ?? []) + ['requests' => 0, 'bytes' => 0, 'in_flight' => 0, 'recent' => []];
        $bud = $c['bud'];
        if ($u['requests'] >= $bud['req']) {
            return $no(429, 'budget_requests');
        }
        if ($u['bytes'] >= $bud['bytes']) {
            return $no(429, 'budget_bytes');
        }
        if ($u['in_flight'] >= $bud['conc']) {
            return $no(429, 'budget_concurrency');
        }
        $recent = 0;
        foreach ($u['recent'] as $t) {
            if ($now - $t < $bud['rate_s']) {
                $recent++;
            }
        }
        if ($recent >= $bud['rate_n']) {
            return $no(429, 'budget_rate');
        }

        $size = $ctx['sizes'][$seg];
        $range = trim((string) ($h['range'] ?? ''));
        if ($range === '') {
            return $no(416, 'range_required', 1, $size);
        }
        if (stripos($range, 'bytes=') !== 0) {
            return $no(416, 'range_unit', 1, $size);
        }
        $spec = trim(substr($range, 6));
        if (str_contains($spec, ',')) {
            return $no(416, 'range_multipart', 1, $size);
        }
        if (preg_match('/^(\d*)-(\d*)$/', $spec, $mm) !== 1 || ($mm[1] === '' && $mm[2] === '')) {
            return $no(416, 'range_malformed', 1, $size);
        }
        $cap = min($bud['win'], $bud['bytes'] - $u['bytes']);
        if ($mm[1] === '') {
            $n = (int) $mm[2];
            if ($n <= 0) {
                return $no(416, 'range_malformed', 1, $size);
            }
            $start = max(0, $size - $n);
            $end = $size - 1;
            if ($end - $start + 1 > $cap) {
                $start = $end - $cap + 1;
            }
        } else {
            $start = (int) $mm[1];
            if ($start >= $size) {
                return $no(416, 'range_unsatisfiable', 1, $size);
            }
            $end = $mm[2] === '' ? $size - 1 : min((int) $mm[2], $size - 1);
            if ($end < $start) {
                return $no(416, 'range_unsatisfiable', 1, $size);
            }
            if ($end - $start + 1 > $cap) {
                $end = $start + $cap - 1;
            }
        }
        $len = $end - $start + 1;
        $hd = $cors + [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => self::DISPOSITION,
            'Content-Length' => (string) $len,
            'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size),
            'Content-Type' => self::MIME,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];
        ksort($hd, SORT_STRING);
        $get = $method === 'GET';
        return [
            'status' => 206, 'reason' => 'ok', 'headers' => $hd,
            'range' => ['start' => $start, 'end' => $end, 'length' => $len],
            'spend' => ['requests' => 1, 'bytes' => $get ? $len : 0],
            'body_sha256' => $get ? self::sha(substr($ctx['bytes'][$seg], $start, $len)) : null,
            'telemetry' => ['event' => 'lof_audio_media', 'status' => 206, 'reason' => 'ok', 'ctx' => $c['ctx'], 'bytes' => $get ? $len : 0],
        ];
    }

    /** Apply a decision's spend to the per-grant usage record (sequences). */
    public static function applySpend(array $state, array $req, array $result, string $jti): array
    {
        if ($result['spend']['requests'] === 0) {
            return $state;
        }
        $u = ($state['usage'][$jti] ?? []) + ['requests' => 0, 'bytes' => 0, 'in_flight' => 0, 'recent' => []];
        $u['requests'] += $result['spend']['requests'];
        $u['bytes'] += $result['spend']['bytes'];
        $u['recent'][] = $state['now'];
        $state['usage'][$jti] = $u;
        return $state;
    }

    /* -------------------------------------------------------- mutations */

    /** ops: [{op:set|delete|rename, path:[...], value?, to?}] */
    public static function mutate(array $doc, array $ops): array
    {
        foreach ($ops as $op) {
            $path = $op['path'];
            $ref = &$doc;
            $last = array_pop($path);
            foreach ($path as $k) {
                $ref = &$ref[$k];
            }
            if ($op['op'] === 'set') {
                $ref[$last] = $op['value'];
            } elseif ($op['op'] === 'delete') {
                unset($ref[$last]);
            } elseif ($op['op'] === 'rename') {
                $v = $ref[$last];
                unset($ref[$last]);
                $ref[$op['to']] = $v;
            } else {
                throw new \RuntimeException('unknown op ' . $op['op']);
            }
            unset($ref);
        }
        return $doc;
    }
}

/**
 * The JSON Schema (2020-12) subset the contract schemas use. Any keyword
 * outside SUPPORTED is an error, so a schema can never silently mean more
 * here than it does in a full validator.
 */
final class Schema
{
    public const SUPPORTED = [
        '$schema', '$id', '$comment', 'title', 'description',
        'type', 'const', 'enum', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'required', 'properties', 'patternProperties', 'additionalProperties', 'minProperties', 'maxProperties',
        'items', 'minItems', 'maxItems', 'oneOf',
    ];

    public static function unsupported($schema, string $at = '#'): array
    {
        $bad = [];
        if (!is_object($schema)) {
            return [$at . ' is not a schema object'];
        }
        foreach (get_object_vars($schema) as $k => $v) {
            if (!in_array($k, self::SUPPORTED, true)) {
                $bad[] = $at . '/' . $k;
            }
        }
        foreach (['properties', 'patternProperties'] as $kw) {
            if (isset($schema->$kw)) {
                foreach (get_object_vars($schema->$kw) as $k => $sub) {
                    $bad = array_merge($bad, self::unsupported($sub, $at . '/' . $kw . '/' . $k));
                }
            }
        }
        foreach (['additionalProperties', 'items'] as $kw) {
            if (isset($schema->$kw) && is_object($schema->$kw)) {
                $bad = array_merge($bad, self::unsupported($schema->$kw, $at . '/' . $kw));
            }
        }
        if (isset($schema->oneOf)) {
            foreach ($schema->oneOf as $i => $sub) {
                $bad = array_merge($bad, self::unsupported($sub, $at . '/oneOf/' . $i));
            }
        }
        return $bad;
    }

    private static function typeOf($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v)) {
            return 'integer';
        }
        if (is_float($v)) {
            return 'number';
        }
        if (is_string($v)) {
            return 'string';
        }
        if ($v instanceof \stdClass) {
            return 'object';
        }
        return 'array';
    }

    private static function norm($x): string
    {
        return Model::canonical(json_decode(json_encode($x, JSON_THROW_ON_ERROR), true));
    }

    private static function re(string $p): string
    {
        return '/' . str_replace('/', '\/', $p) . '/u';
    }

    public static function errors($v, $s, string $at = '$'): array
    {
        $e = [];
        $t = self::typeOf($v);
        if (isset($s->type)) {
            $types = (array) $s->type;
            if (!in_array($t, $types, true)) {
                return [$at . ': type ' . $t];
            }
        }
        if (property_exists($s, 'const') && self::norm($v) !== self::norm($s->const)) {
            $e[] = $at . ': const';
        }
        if (isset($s->enum)) {
            $hit = false;
            foreach ($s->enum as $c) {
                $hit = $hit || self::norm($v) === self::norm($c);
            }
            if (!$hit) {
                $e[] = $at . ': enum';
            }
        }
        if ($t === 'string') {
            $len = preg_match_all('/./su', $v);
            if (isset($s->pattern) && preg_match(self::re($s->pattern), $v) !== 1) {
                $e[] = $at . ': pattern';
            }
            if (isset($s->minLength) && $len < $s->minLength) {
                $e[] = $at . ': minLength';
            }
            if (isset($s->maxLength) && $len > $s->maxLength) {
                $e[] = $at . ': maxLength';
            }
        }
        if ($t === 'integer') {
            if (isset($s->minimum) && $v < $s->minimum) {
                $e[] = $at . ': minimum';
            }
            if (isset($s->maximum) && $v > $s->maximum) {
                $e[] = $at . ': maximum';
            }
        }
        if ($t === 'object') {
            $props = get_object_vars($v);
            foreach (($s->required ?? []) as $r) {
                if (!array_key_exists($r, $props)) {
                    $e[] = $at . ': required ' . $r;
                }
            }
            if (isset($s->minProperties) && count($props) < $s->minProperties) {
                $e[] = $at . ': minProperties';
            }
            if (isset($s->maxProperties) && count($props) > $s->maxProperties) {
                $e[] = $at . ': maxProperties';
            }
            foreach ($props as $k => $pv) {
                $k = (string) $k;
                $matched = false;
                if (isset($s->properties) && property_exists($s->properties, $k)) {
                    $matched = true;
                    $e = array_merge($e, self::errors($pv, $s->properties->$k, $at . '.' . $k));
                }
                if (isset($s->patternProperties)) {
                    foreach (get_object_vars($s->patternProperties) as $pat => $ps) {
                        if (preg_match(self::re((string) $pat), $k) === 1) {
                            $matched = true;
                            $e = array_merge($e, self::errors($pv, $ps, $at . '.' . $k));
                        }
                    }
                }
                if (!$matched && isset($s->additionalProperties)) {
                    if ($s->additionalProperties === false) {
                        $e[] = $at . ': additional ' . $k;
                    } elseif (is_object($s->additionalProperties)) {
                        $e = array_merge($e, self::errors($pv, $s->additionalProperties, $at . '.' . $k));
                    }
                }
            }
        }
        if ($t === 'array') {
            if (isset($s->minItems) && count($v) < $s->minItems) {
                $e[] = $at . ': minItems';
            }
            if (isset($s->maxItems) && count($v) > $s->maxItems) {
                $e[] = $at . ': maxItems';
            }
            if (isset($s->items) && is_object($s->items)) {
                foreach ($v as $i => $iv) {
                    $e = array_merge($e, self::errors($iv, $s->items, $at . '[' . $i . ']'));
                }
            }
        }
        if (isset($s->oneOf)) {
            $n = 0;
            foreach ($s->oneOf as $sub) {
                $n += self::errors($v, $sub, $at) === [] ? 1 : 0;
            }
            if ($n !== 1) {
                $e[] = $at . ': oneOf matched ' . $n;
            }
        }
        return $e;
    }
}
