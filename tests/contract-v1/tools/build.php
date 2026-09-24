<?php
/**
 * Deterministic fixture builder for the V1 contract. Re-running it must
 * reproduce every fixture byte for byte. Every vector carries a hand-authored
 * expectation (`want`), and the build aborts if the reference model disagrees;
 * the model's complete output is then frozen as `expect`.
 *
 *   php tools/build.php     (from the contract directory)
 *
 * All keys below are FIXTURE-ONLY and must never be deployed.
 */

declare(strict_types=1);

namespace LofPhoneAudioContractV1;

require __DIR__ . '/model.php';

$DIR = dirname(__DIR__);
$schemas = [];
foreach (['viewer-rendition-manifest', 'publication-health-pointer', 'private-source-map', 'grant-claims', 'media-decision', 'grant-response'] as $n) {
    $schemas[$n] = json_decode((string) file_get_contents("$DIR/schemas/$n.v1.schema.json"), false, 64, JSON_THROW_ON_ERROR);
}

function synth(string $label, int $size): string
{
    $out = '';
    for ($i = 0; strlen($out) < $size; $i++) {
        $out .= hash('sha256', 'lof-phone-audio-contract-v1|' . $label . '|' . $i, true);
    }
    return substr($out, 0, $size);
}
function h32(string $s): string
{
    return substr(hash('sha256', $s), 0, 32);
}
function pretty($v): string
{
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}
/** How fpp-lof-audio-sync writes documents: Json::pretty (recursively key-sorted). */
function fppPretty(array $v): string
{
    return json_encode(sortRec($v), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}
function sortRec($v)
{
    return json_decode(Model::canonical($v), true);
}
function put(string $path, string $bytes): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $bytes);
}
function must(bool $c, string $msg): void
{
    if (!$c) {
        fwrite(STDERR, "BUILD ABORT: $msg\n");
        exit(1);
    }
}

/* ------------------------------------------------------------ constants */

$T0 = gmmktime(2, 10, 0, 9, 23, 2026);
$GEN = '20260923T020000Z-5e1f0a3c';
$GEN_PREV = '20260922T020000Z-0b7d3e21';
$GEN_NEXT = '20260924T020000Z-77c0d9aa';
$GRANT_KEY = hash('sha256', 'lof-phone-audio-contract-v1 FIXTURE grant key - never deploy');
$OTHER_KEY = hash('sha256', 'lof-phone-audio-contract-v1 FIXTURE wrong grant key');
$RID_KEY = hash('sha256', 'lof-phone-audio-contract-v1 FIXTURE rid key - never deploy');
$KID = 'fixture-g1';
$OWN = 'https://lights.example.test';

$assets = [
    'A' => ['source_rel' => 'Masters/Midnight Overture (MASTER).wav', 'source_size' => 61440, 'rendition_size' => 24576,
            'sequence' => 'Midnight_Overture.fseq', 'title' => 'Overture at Midnight'],
    'B' => ['source_rel' => 'Northern_Lights_Theme.mp3', 'source_size' => 51200, 'rendition_size' => 20480,
            'sequence' => 'Northern_Lights_Theme.fseq', 'title' => 'Aurora Theme'],
];
$bytes = [];
foreach ($assets as $k => &$a) {
    $a['source_bytes'] = synth("source-$k", $a['source_size']);
    $a['rendition_bytes'] = synth("rendition-$k", $a['rendition_size']);
    $a['source_sha256'] = Model::sha($a['source_bytes']);
    $a['rendition_sha256'] = Model::sha($a['rendition_bytes']);
    $a['rid'] = Model::deriveRid($RID_KEY, $GEN, $a['source_rel'], $a['source_sha256']);
    $bytes[$a['rid']] = $a['rendition_bytes'];
}
unset($a);
$RA = $assets['A']['rid'];
$RB = $assets['B']['rid'];

$ITEM = ['A1' => h32('fixture item instance A#1'), 'A0' => h32('fixture item instance A#0 (previous play)'),
         'B1' => h32('fixture item instance B#1'), 'A2' => h32('fixture item instance A#2 (repeat)')];
$SESS = ['L1' => 'listener-one_4f9c2a7b8e1d3c5a', 'L2' => 'listener-two_9e8d7c6b5a4f3e2d'];
$jti = static fn (int $n): string => h32("fixture jti $n");

$policyProd = [
    'max_ttl_s' => 300, 'recovery_margin_s' => 20, 'preload_lead_s' => 30, 'playhead_max_age_s' => 5,
    'renew_lead_s' => 15, 'window_bytes' => 262144, 'session_cookie_max_age_s' => 43200, 'revocation_bound_s' => 5,
    'current' => ['req' => 200, 'conc' => 2, 'rate_n' => 20, 'rate_s' => 10, 'bytes_num' => 3, 'bytes_den' => 2, 'bytes_extra_windows' => 2],
    'next' => ['req' => 8, 'conc' => 1, 'rate_n' => 8, 'rate_s' => 10, 'bytes_windows' => 2],
];
$policy = $policyProd;
$policy['window_bytes'] = 4096; // fixture scale only; every other number is the production default

$map = [
    $assets['A']['sequence'] => $assets['A']['source_rel'],
    $assets['B']['sequence'] => $assets['B']['source_rel'],
    'Unreleased_Encore.fseq' => 'Encore_Final.flac',
];
$index = [$assets['A']['source_rel'] => $RA, $assets['B']['source_rel'] => $RB];
$sizes = [$RA => $assets['A']['rendition_size'], $RB => $assets['B']['rendition_size']];

$ctx = ['grant_key_hex' => $GRANT_KEY, 'kid' => $KID, 'policy' => $policy, 'map' => $map, 'index' => $index,
        'sizes' => $sizes, 'own_origin' => $OWN, 'bytes' => $bytes];

/* ---------------------------------------------------------- publication */

$renditions = [];
foreach ($assets as $a) {
    $renditions[$a['rid']] = ['size' => $a['rendition_size'], 'sha256' => $a['rendition_sha256']];
}
$profile = json_decode(json_encode($schemas['viewer-rendition-manifest']->properties->profile->const), true);
$M = [
    'manifest_version' => 1, 'role' => Model::ROLE_VIEWER, 'component' => Model::COMPONENT,
    'generation' => $GEN, 'created_utc' => '2026-09-23T02:00:00Z', 'algorithm' => 'sha256', 'profile' => $profile,
    'asset_count' => 2, 'total_bytes' => $assets['A']['rendition_size'] + $assets['B']['rendition_size'],
    'renditions' => $renditions,
];
$M['manifest_sha256'] = Model::manifestDigest($M);

$H = [
    'health_version' => 1, 'component' => Model::COMPONENT, 'role' => Model::ROLE_VIEWER,
    'generated_utc' => '2026-09-23T02:00:07Z', 'state' => 'ok',
    'current' => ['generation' => $GEN, 'manifest_sha256' => $M['manifest_sha256'], 'asset_count' => $M['asset_count'],
                  'total_bytes' => $M['total_bytes'], 'profile_id' => Model::PROFILE_ID],
    'previous_generation' => $GEN_PREV,
];

$entries = [];
foreach ($assets as $a) {
    $entries[$a['rid']] = ['source_rel' => $a['source_rel'], 'source_size' => $a['source_size'], 'source_sha256' => $a['source_sha256'],
                           'rendition_size' => $a['rendition_size'], 'rendition_sha256' => $a['rendition_sha256']];
}
$S = [
    'source_map_version' => 1, 'role' => Model::ROLE_SOURCE_MAP, 'component' => Model::COMPONENT,
    'generation' => $GEN, 'created_utc' => '2026-09-23T02:00:00Z', 'algorithm' => 'sha256',
    'profile_id' => Model::PROFILE_ID, 'rid_key_id' => 'fixture-r1',
    'supply_generation' => '20260923T015500Z-a41c9e07', 'supply_manifest_sha256' => hash('sha256', 'fixture media-supply manifest'),
    'viewer_manifest_sha256' => $M['manifest_sha256'], 'entries' => $entries,
];
$S['map_sha256'] = Model::sourceMapDigest($S);

$PUB = "$DIR/fixtures/publication";
put("$PUB/viewer-root/health.json", fppPretty($H));
put("$PUB/viewer-root/manifests/$GEN.json", fppPretty($M));
foreach ($assets as $a) {
    put("$PUB/viewer-root/generations/$GEN/{$a['rid']}.m4a", $a['rendition_bytes']);
}
put("$PUB/private-root/source-maps/$GEN.json", fppPretty($S));

$O = ['root_public' => false, 'entries' => []];
foreach ($assets as $a) {
    $O['entries'][$a['rid'] . '.m4a'] = ['type' => 'file', 'within_root' => true, 'size' => $a['rendition_size'], 'sha256' => $a['rendition_sha256']];
}
must(Model::pipeline($H, $M, $S, $O, $schemas) === ['ok', 'ok'], 'valid publication does not pass');

/* --------------------------------------------------------------- grants */

$issueBase = [
    'now' => $T0, 'disabled' => false, 'publication_ok' => true, 'generation' => $GEN, 'epoch' => 7, 'next_jti' => $jti(1),
    'current' => ['name' => $assets['A']['sequence'], 'title' => $assets['A']['title'], 'item_id' => $ITEM['A1'], 'observed_at' => $T0 - 2, 'remaining_s' => 100],
    'next' => ['name' => $assets['B']['sequence'], 'title' => $assets['B']['title'], 'item_id' => $ITEM['B1'], 'authoritative' => true],
];
$issueNearEnd = Model::mutate($issueBase, [
    ['op' => 'set', 'path' => ['now'], 'value' => $T0 + 80],
    ['op' => 'set', 'path' => ['current', 'observed_at'], 'value' => $T0 + 79],
    ['op' => 'set', 'path' => ['current', 'remaining_s'], 'value' => 20],
    ['op' => 'set', 'path' => ['next_jti'], 'value' => $jti(2)],
]);
$issueAfterTransition = Model::mutate($issueBase, [
    ['op' => 'set', 'path' => ['now'], 'value' => $T0 + 100],
    ['op' => 'set', 'path' => ['current'], 'value' => ['name' => $assets['B']['sequence'], 'title' => $assets['B']['title'], 'item_id' => $ITEM['B1'], 'observed_at' => $T0 + 100, 'remaining_s' => 212]],
    ['op' => 'set', 'path' => ['next'], 'value' => null],
    ['op' => 'set', 'path' => ['next_jti'], 'value' => $jti(3)],
]);
$issuePrevPlay = Model::mutate($issueBase, [
    ['op' => 'set', 'path' => ['now'], 'value' => $T0 - 240],
    ['op' => 'set', 'path' => ['current', 'item_id'], 'value' => $ITEM['A0']],
    ['op' => 'set', 'path' => ['current', 'observed_at'], 'value' => $T0 - 241],
    ['op' => 'set', 'path' => ['current', 'remaining_s'], 'value' => 200],
    ['op' => 'set', 'path' => ['next_jti'], 'value' => $jti(4)],
]);

$grants = [];
foreach ([
    'G_CUR_A' => [$issueBase, ['session' => $SESS['L1'], 'ctx' => 'current'], 'current listener L1, item A#1'],
    'G_NEXT_B' => [$issueNearEnd, ['session' => $SESS['L1'], 'ctx' => 'next'], 'bounded next-item preparation, item B#1'],
    'G_CUR_B' => [$issueAfterTransition, ['session' => $SESS['L1'], 'ctx' => 'current'], 'after the A->B transition'],
    'G_PREV_A0' => [$issuePrevPlay, ['session' => $SESS['L1'], 'ctx' => 'current'], 'an earlier play of the same rendition (replay source)'],
] as $id => [$st, $rq, $note]) {
    $r = Model::issue($ctx, $st, $rq);
    must($r['ok'], "grant $id not issued: {$r['reason']}");
    $grants[$id] = ['note' => $note, 'state' => $st, 'request' => $rq, 'claims' => $r['claims'], 'token' => $r['token'], 'response' => $r['response']];
}
must($grants['G_CUR_A']['claims']['exp'] === $T0 + 118, 'clamp G_CUR_A');
must($grants['G_NEXT_B']['claims']['exp'] === $T0 + 119, 'clamp G_NEXT_B');
must($grants['G_CUR_B']['claims']['exp'] === $T0 + 100 + 232, 'clamp G_CUR_B');

/* ------------------------------------------------------------- constants file */

$constants = [
    'contract' => Model::CONTRACT, 'contract_version' => Model::CONTRACT_VERSION,
    'label' => 'FIXTURE ONLY - synthetic bytes, fixture keys; never deploy any value in this file',
    't0' => $T0, 't0_utc' => gmdate('Y-m-d\TH:i:s\Z', $T0),
    'generation' => $GEN, 'previous_generation' => $GEN_PREV,
    'grant_key_hex' => $GRANT_KEY, 'grant_kid' => $KID, 'rid_key_hex' => $RID_KEY, 'rid_key_id' => 'fixture-r1',
    'own_origin' => $OWN,
    'synthetic_bytes' => 'bytes(label,size) = first size bytes of concat_i sha256_raw("lof-phone-audio-contract-v1|" + label + "|" + i), i = 0,1,2,...; labels source-A, source-B, rendition-A, rendition-B. Not decodable audio.',
    'assets' => array_map(static fn ($a) => [
        'rid' => $a['rid'], 'source_rel' => $a['source_rel'], 'source_size' => $a['source_size'], 'source_sha256' => $a['source_sha256'],
        'rendition_size' => $a['rendition_size'], 'rendition_sha256' => $a['rendition_sha256'], 'sequence' => $a['sequence'], 'display_title' => $a['title'],
    ], $assets),
    'item_ids' => $ITEM, 'sessions' => $SESS,
    'lof_core_item_map' => $map,
    'policy_production_default' => $policyProd, 'policy_fixture' => $policy,
    'leak_denylist' => [
        'Masters/Midnight Overture (MASTER).wav', 'Midnight Overture (MASTER).wav', 'Midnight Overture (MASTER)', 'Midnight Overture',
        'Northern_Lights_Theme.mp3', 'Northern_Lights_Theme', 'Midnight_Overture.fseq', 'Midnight_Overture', 'Northern_Lights_Theme.fseq',
        'Unreleased_Encore.fseq', 'Encore_Final.flac', '.fseq', '.wav', '.mp3', '.flac', 'Masters/', '/home/fpp', 'fpp-show.local',
        $assets['A']['source_sha256'], $assets['B']['source_sha256'],
    ],
];
put("$DIR/fixtures/constants.json", pretty($constants));
put("$DIR/fixtures/grants.json", pretty($grants));

/* ------------------------------------------------------- document vectors */

$redigestM = static function (array $m): array { $m['manifest_sha256'] = Model::manifestDigest($m); return $m; };
$redigestS = static function (array $s): array { $s['map_sha256'] = Model::sourceMapDigest($s); return $s; };
$docVectors = [];
$dv = static function (string $id, string $covers, array $docs, string $want) use (&$docVectors, $H, $M, $S, $O, $schemas): void {
    $d = $docs + ['health' => $H, 'manifest' => $M, 'source_map' => $S, 'observation' => $O];
    [$stage, $reason] = Model::pipeline($d['health'], $d['manifest'], $d['source_map'], $d['observation'], $schemas);
    must($reason === $want, "doc vector $id: want $want, model $reason");
    $out = ['id' => $id, 'covers' => $covers];
    foreach (['health', 'manifest', 'source_map', 'observation'] as $k) {
        $out[$k] = array_key_exists($k, $docs) ? $docs[$k] : 'base';
    }
    $out['expect'] = ['stage' => $stage, 'reason' => $reason];
    $docVectors[] = $out;
};
$m = static fn (array $ops) => Model::mutate($M, $ops);
$s = static fn (array $ops) => Model::mutate($S, $ops);
$hh = static fn (array $ops) => Model::mutate($H, $ops);
$o = static fn (array $ops) => Model::mutate($O, $ops);
$set = static fn (array $p, $v) => ['op' => 'set', 'path' => $p, 'value' => $v];
$del = static fn (array $p) => ['op' => 'delete', 'path' => $p];
$ren = static fn (array $p, string $to) => ['op' => 'rename', 'path' => $p, 'to' => $to];
$fA = "$RA.m4a";

$dv('health_version_2', 'version', ['health' => $hh([$set(['health_version'], 2)])], 'health_version');
$dv('health_version_string', 'version', ['health' => $hh([$set(['health_version'], '1')])], 'health_version');
$dv('health_carries_publication_root', 'source/path leak', ['health' => $hh([$set(['publication_root'], '/home/fpp/media/lof-audio-viewer')])], 'health_source_leak');
$dv('media_supply_health_read_as_viewer', 'role confusion', ['health' => $hh([$set(['role'], 'media-supply')])], 'health_component');
$dv('health_generation_shape', 'generation', ['health' => $hh([$set(['current', 'generation'], 'latest')])], 'health_generation');
$dv('health_extra_field', 'schema', ['health' => $hh([$set(['manifest_error'], 'unreadable')])], 'health_schema');
$dv('health_degraded', 'fail closed', ['health' => $hh([$set(['state'], 'degraded')])], 'health_state');
$dv('health_no_current', 'fail closed', ['health' => $hh([$set(['current'], null)])], 'health_no_current');
$dv('health_digest_mismatch', 'health digest', ['health' => $hh([$set(['current', 'manifest_sha256'], hash('sha256', 'other'))])], 'publication_health_digest');
$dv('health_points_elsewhere', 'generation', ['health' => $hh([$set(['current', 'generation'], $GEN_NEXT)])], 'publication_generation_drift');
$dv('health_counter_mismatch', 'size drift', ['health' => $hh([$set(['current', 'asset_count'], 3)])], 'publication_health_counter');

$dv('manifest_version_2', 'version', ['manifest' => $redigestM($m([$set(['manifest_version'], 2)]))], 'manifest_version');
$dv('manifest_version_string', 'version', ['manifest' => $m([$set(['manifest_version'], '1')])], 'manifest_version');
$dv('manifest_algorithm_sha1', 'algorithm', ['manifest' => $redigestM($m([$set(['algorithm'], 'sha1')]))], 'manifest_algorithm');
$dv('manifest_generation_shape', 'generation', ['manifest' => $redigestM($m([$set(['generation'], '2026-09-23')]))], 'manifest_generation');
$dv('manifest_self_digest_stale', 'self-digest', ['manifest' => $m([$set(['created_utc'], '2026-09-23T02:00:01Z')])], 'manifest_digest');
$dv('manifest_self_digest_forged', 'self-digest', ['manifest' => $m([$set(['manifest_sha256'], str_repeat('0', 64))])], 'manifest_digest');
$dv('manifest_counter_forged', 'size drift', ['manifest' => $redigestM($m([$set(['asset_count'], 3)]))], 'manifest_counter');
$dv('manifest_total_bytes_forged', 'size drift', ['manifest' => $redigestM($m([$set(['total_bytes'], 1)]))], 'manifest_counter');
$dv('manifest_id_is_filename', 'path/id shape; source-name leak', ['manifest' => $redigestM($m([$ren(['renditions', $RA], 'Midnight Overture (MASTER).wav')]))], 'manifest_rendition_id');
$dv('manifest_id_traversal', 'path traversal declaration', ['manifest' => $redigestM($m([$ren(['renditions', $RA], '../../etc/passwd')]))], 'manifest_rendition_id');
$dv('manifest_id_uppercase', 'path/id shape', ['manifest' => $redigestM($m([$ren(['renditions', $RA], strtoupper($RA))]))], 'manifest_rendition_id');
$dv('manifest_id_short', 'path/id shape (<128 bits)', ['manifest' => $redigestM($m([$ren(['renditions', $RA], substr($RA, 0, 31))]))], 'manifest_rendition_id');
$dv('manifest_id_with_extension', 'path/id shape', ['manifest' => $redigestM($m([$ren(['renditions', $RA], "$RA.m4a")]))], 'manifest_rendition_id');
$dv('manifest_entry_source_rel', 'source-name leak', ['manifest' => $redigestM($m([$set(['renditions', $RA, 'source_rel'], $assets['A']['source_rel'])]))], 'manifest_source_leak');
$dv('manifest_title_field', 'title/artist leak', ['manifest' => $redigestM($m([$set(['title'], 'Overture at Midnight')]))], 'manifest_source_leak');
$dv('manifest_artist_field', 'title/artist leak', ['manifest' => $redigestM($m([$set(['renditions', $RB, 'artist'], 'Someone')]))], 'manifest_source_leak');
$legacy = ['manifest_version' => 1, 'generation' => $GEN, 'created_utc' => '2026-09-23T02:00:00Z', 'source_root' => '/home/fpp/media/music',
           'algorithm' => 'sha256', 'asset_count' => 1, 'total_bytes' => 61440,
           'assets' => [$assets['A']['source_rel'] => ['size' => 61440, 'sha256' => $assets['A']['source_sha256']]]];
$legacy['manifest_sha256'] = Model::sha(Model::canonical($legacy));
$dv('media_supply_manifest_read_as_viewer', 'source-name leak; role confusion (fpp-lof-audio-sync@588418f media-supply manifest shape)', ['manifest' => $legacy], 'manifest_source_leak');
$dv('manifest_role_media_supply', 'role confusion', ['manifest' => $redigestM($m([$set(['role'], 'media-supply')]))], 'manifest_role');
$dv('manifest_second_codec', 'single codec/profile only', ['manifest' => $redigestM($m([$set(['profile', 'codec'], 'opus')]))], 'manifest_schema');
$dv('manifest_zero_size', 'size drift', ['manifest' => $redigestM($m([$set(['renditions', $RA, 'size'], 0), $set(['total_bytes'], $assets['B']['rendition_size'])]))], 'manifest_schema');
$dv('manifest_upper_hex_digest', 'digest shape', ['manifest' => $redigestM($m([$set(['renditions', $RA, 'sha256'], strtoupper($assets['A']['rendition_sha256']))]))], 'manifest_schema');

$dv('source_map_missing', 'fail closed', ['source_map' => null], 'source_map_missing');
$dv('source_map_version_2', 'version', ['source_map' => $redigestS($s([$set(['source_map_version'], 2)]))], 'source_map_version');
$dv('source_map_role', 'role confusion', ['source_map' => $redigestS($s([$set(['role'], 'viewer-rendition')]))], 'source_map_role');
$dv('source_map_algorithm', 'algorithm', ['source_map' => $redigestS($s([$set(['algorithm'], 'md5')]))], 'source_map_algorithm');
$dv('source_map_traversal', 'path traversal declaration', ['source_map' => $redigestS($s([$set(['entries', $RA, 'source_rel'], '../../etc/shadow')]))], 'source_map_path');
$dv('source_map_absolute', 'path declaration', ['source_map' => $redigestS($s([$set(['entries', $RA, 'source_rel'], '/home/fpp/media/music/x.wav')]))], 'source_map_path');
$dv('source_map_option_like', 'path declaration', ['source_map' => $redigestS($s([$set(['entries', $RA, 'source_rel'], '-rf.wav')]))], 'source_map_path');
$dv('source_map_other_generation', 'generation', ['source_map' => $redigestS($s([$set(['generation'], $GEN_PREV)]))], 'source_map_generation');
$dv('source_map_other_manifest', 'digest binding', ['source_map' => $redigestS($s([$set(['viewer_manifest_sha256'], hash('sha256', 'x'))]))], 'source_map_manifest_digest');
$dv('source_map_self_digest_stale', 'self-digest', ['source_map' => $s([$set(['created_utc'], '2026-09-23T02:00:01Z')])], 'source_map_digest');
$dv('source_map_missing_entry', 'coverage', ['source_map' => $redigestS($s([$del(['entries', $RB])]))], 'source_map_coverage');
$dv('source_map_rendition_size_drift', 'size drift', ['source_map' => $redigestS($s([$set(['entries', $RA, 'rendition_size'], $assets['A']['rendition_size'] + 1)]))], 'source_map_rendition_drift');
$dv('source_map_rendition_digest_drift', 'digest drift', ['source_map' => $redigestS($s([$set(['entries', $RA, 'rendition_sha256'], $assets['B']['rendition_sha256'])]))], 'source_map_rendition_drift');
$dv('source_map_duplicate_source', 'one source, one rendition', ['source_map' => $redigestS($s([$set(['entries', $RB, 'source_rel'], $assets['A']['source_rel'])]))], 'source_map_duplicate_source');
$dv('source_map_not_transcoded', 'master reached viewer tree', ['source_map' => $redigestS($s([$set(['entries', $RA, 'source_sha256'], $assets['A']['rendition_sha256'])]))], 'source_map_not_transcoded');

// A publication that is internally consistent but whose id is a source hash / filename encoding.
foreach (['rid_is_source_hash' => substr($assets['A']['source_sha256'], 0, 32), 'rid_is_hex_filename' => substr(bin2hex(basename($assets['A']['source_rel'])), 0, 32)] as $vid => $bad) {
    $m2 = $redigestM($m([$ren(['renditions', $RA], $bad)]));
    $h2 = $hh([$set(['current', 'manifest_sha256'], $m2['manifest_sha256'])]);
    $s2 = $redigestS($s([$ren(['entries', $RA], $bad), $set(['viewer_manifest_sha256'], $m2['manifest_sha256'])]));
    $o2 = $o([$ren(['entries', $fA], "$bad.m4a")]);
    $dv($vid, 'opaque id is not a source hash or reversible filename encoding', ['health' => $h2, 'manifest' => $m2, 'source_map' => $s2, 'observation' => $o2], 'rid_derivable');
}

$dv('viewer_root_under_web_root', 'static-server bypass', ['observation' => $o([$set(['root_public'], true)])], 'publication_root_public');
$dv('rendition_is_symlink', 'symlink declaration', ['observation' => $o([$set(['entries', $fA, 'type'], 'symlink')])], 'file_symlink');
$dv('rendition_escapes_root', 'path traversal (realpath escape)', ['observation' => $o([$set(['entries', $fA, 'within_root'], false)])], 'file_outside_root');
$dv('rendition_size_drift', 'size drift', ['observation' => $o([$set(['entries', $fA, 'size'], 24000)])], 'file_size_drift');
$dv('rendition_digest_drift', 'digest drift', ['observation' => $o([$set(['entries', $fA, 'sha256'], hash('sha256', 'tampered'))])], 'file_digest_drift');
$dv('rendition_missing', 'partial publication', ['observation' => $o([$del(['entries', $fA])])], 'file_missing');
$dv('rendition_is_directory', 'type', ['observation' => $o([$set(['entries', $fA, 'type'], 'dir')])], 'file_type');
$dv('original_master_in_viewer_tree', 'no master beneath viewer root', ['observation' => $o([$set(['entries', 'Midnight Overture (MASTER).wav'], ['type' => 'file', 'within_root' => true, 'size' => 61440, 'sha256' => $assets['A']['source_sha256']])])], 'file_unexpected');
$dv('interrupted_generation_marker', 'interrupted publication', ['observation' => $o([$set(['entries', '.incomplete'], ['type' => 'file', 'within_root' => true, 'size' => 64, 'sha256' => hash('sha256', 'marker')])])], 'file_unexpected');

put("$DIR/vectors/documents.json", pretty(['label' => 'consumer pipeline: health -> manifest -> publication -> source_map -> observation; first failure wins; "base" = the valid fixture document', 'vectors' => $docVectors]));

/* ---------------------------------------------------------- issue vectors */

$issueVectors = [];
$iv = static function (string $id, string $covers, array $state, array $req, string $want, ?int $wantExpOffset = null) use (&$issueVectors, $ctx, $T0, $issueBase): void {
    $r = Model::issue($ctx, $state, $req);
    must($r['reason'] === $want, "issue vector $id: want $want, model {$r['reason']}");
    if ($wantExpOffset !== null) {
        must($r['claims']['exp'] === $state['now'] + $wantExpOffset, "issue vector $id: exp");
    }
    $issueVectors[] = ['id' => $id, 'covers' => $covers, 'state' => $state === $issueBase ? 'issue_base' : $state, 'request' => $req, 'expect' => $r];
};
$st = static fn (array $ops) => Model::mutate($issueBase, $ops);
$L1 = $SESS['L1'];
$iv('clamp_to_item_remaining', 'expiry = remaining 98 + margin 20', $issueBase, ['session' => $L1, 'ctx' => 'current'], 'ok', 118);
$iv('clamp_to_max_ttl', 'expiry capped by max_ttl 300', $st([$set(['current', 'remaining_s'], 1000)]), ['session' => $L1, 'ctx' => 'current'], 'ok', 300);
$iv('clamp_item_ending_now', 'remaining 0 -> margin only', $st([$set(['current', 'remaining_s'], 0), $set(['current', 'observed_at'], $T0)]), ['session' => $L1, 'ctx' => 'current'], 'ok', 20);
$iv('clamp_observation_age', 'remaining measured at observation, aged 5 s', $st([$set(['current', 'remaining_s'], 50), $set(['current', 'observed_at'], $T0 - 5)]), ['session' => $L1, 'ctx' => 'current'], 'ok', 65);
$iv('client_named_asset_ignored', 'client cannot choose rid/item/asset (future item)', $issueBase, ['session' => $L1, 'ctx' => 'current', 'rid' => $RB, 'asset' => 'Northern_Lights_Theme.mp3', 'item' => $ITEM['B1']], 'ok', 118);
$iv('disabled', 'emergency disable', $st([$set(['disabled'], true)]), ['session' => $L1, 'ctx' => 'current'], 'issue_disabled');
$iv('publication_unverified', 'fail closed', $st([$set(['publication_ok'], false)]), ['session' => $L1, 'ctx' => 'current'], 'issue_publication');
$iv('session_too_short', 'listener id >= 128 bits', $issueBase, ['session' => 'abc12345', 'ctx' => 'current'], 'issue_session');
$iv('session_with_space', 'validated, never sanitised', $issueBase, ['session' => 'listener one 4f9c2a7b8e1d3c5a', 'ctx' => 'current'], 'issue_session');
$iv('ctx_previous', 'previous item cannot be requested', $issueBase, ['session' => $L1, 'ctx' => 'previous'], 'issue_ctx');
$iv('nothing_playing', 'fail closed', $st([$set(['current'], null)]), ['session' => $L1, 'ctx' => 'current'], 'issue_nothing_playing');
$iv('playhead_stale', 'timing unknown', $st([$set(['current', 'observed_at'], $T0 - 6)]), ['session' => $L1, 'ctx' => 'current'], 'issue_playhead_stale');
$iv('playhead_from_future', 'timing unknown', $st([$set(['current', 'observed_at'], $T0 + 1)]), ['session' => $L1, 'ctx' => 'current'], 'issue_playhead_stale');
$iv('remaining_unknown', 'cannot clamp -> refuse', $st([$set(['current', 'remaining_s'], null)]), ['session' => $L1, 'ctx' => 'current'], 'issue_remaining_unknown');
$iv('next_not_authoritative', 'future item', $st([$set(['next', 'authoritative'], false), $set(['current', 'remaining_s'], 20)]), ['session' => $L1, 'ctx' => 'next'], 'issue_next_not_authoritative');
$iv('next_unknown', 'future item', $st([$set(['next'], null), $set(['current', 'remaining_s'], 20)]), ['session' => $L1, 'ctx' => 'next'], 'issue_next_not_authoritative');
$iv('next_too_early', 'future item beyond preload lead', $issueBase, ['session' => $L1, 'ctx' => 'next'], 'issue_next_too_early');
$iv('item_unmapped', 'mapping unknown', $st([$set(['current', 'name'], 'Pixel_Test.fseq')]), ['session' => $L1, 'ctx' => 'current'], 'issue_unmapped');
$iv('item_unpublished', 'unpublished item', $st([$set(['current', 'name'], 'Unreleased_Encore.fseq')]), ['session' => $L1, 'ctx' => 'current'], 'issue_unpublished');
$iv('next_same_rendition', 'repeat play reuses current grant, never overwrites it', $st([$set(['current', 'remaining_s'], 20), $set(['next', 'name'], $assets['A']['sequence']), $set(['next', 'item_id'], $ITEM['A2'])]), ['session' => $L1, 'ctx' => 'next'], 'issue_next_same_rendition');
put("$DIR/vectors/issue.json", pretty(['label' => 'POST /audio/grant; issue_base = fixtures/grants.json G_CUR_A.state', 'vectors' => $issueVectors]));

/* ---------------------------------------------------------- media vectors */

$mediaBaseArr = ['now' => $T0 + 1, 'disabled' => false, 'publication_ok' => true, 'generation' => $GEN, 'epoch' => 7,
                 'revoked_jti' => [], 'current_item' => $ITEM['A1'], 'next_item' => $ITEM['B1'], 'usage' => []];
$TOK = $grants['G_CUR_A']['token'];
$CA = $grants['G_CUR_A']['claims'];
$req = static fn (array $over = []) => array_replace_recursive([
    'method' => 'GET', 'path' => Model::MEDIA_PREFIX . $RA,
    'headers' => ['Range' => 'bytes=0-1', 'Sec-Fetch-Site' => 'same-origin'],
    'cookies' => [Model::GRANT_COOKIE => $TOK, Model::SESSION_COOKIE => $SESS['L1']],
], $over);
$mediaVectors = [];
$mv = static function (string $id, string $covers, array $state, array $request, int $wantStatus, string $want) use (&$mediaVectors, $ctx, $schemas, $mediaBaseArr): void {
    $r = Model::media($ctx, $state, $request, $schemas['grant-claims']);
    must($r['reason'] === $want && $r['status'] === $wantStatus, "media vector $id: want $wantStatus $want, model {$r['status']} {$r['reason']}");
    $mediaVectors[] = ['id' => $id, 'covers' => $covers, 'state' => $state === $mediaBaseArr ? 'media_base' : $state, 'request' => $request, 'expect' => $r];
};
$ms = static fn (array $ops) => Model::mutate($mediaBaseArr, $ops);
$signed = static fn (array $claimOps, ?string $key = null) => Model::mint(Model::mutate($CA, $claimOps), $key ?? $GRANT_KEY);
$noCookies = static function (array $r): array { $r['cookies'] = []; return $r; };
$noHeader = static function (array $r, string $h): array { unset($r['headers'][$h]); return $r; };

$mv('ok_safari_probe', 'Safari first probe bytes=0-1', $mediaBaseArr, $req(), 206, 'ok');
$mv('ok_open_ended_is_windowed', 'bytes=0- never authorises the whole entity', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=0-']]), 206, 'ok');
$mv('ok_bounded_seek', 'seek window', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=10000-10999']]), 206, 'ok');
$mv('ok_suffix_windowed_from_end', 'iOS trailer read, clamped from the end', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=-5000']]), 206, 'ok');
$mv('ok_end_past_entity', 'end clamped to entity', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=24000-99999']]), 206, 'ok');
$mv('ok_same_origin_cors', 'same-origin Origin echoed, never *', $mediaBaseArr, $req(['headers' => ['Origin' => $OWN]]), 206, 'ok');
$mv('ok_no_fetch_metadata', 'older Safari sends no Sec-Fetch-Site', $mediaBaseArr, $noHeader($req(), 'Sec-Fetch-Site'), 206, 'ok');
$mv('ok_head', 'HEAD spends a request, no bytes', $mediaBaseArr, $req(['method' => 'HEAD']), 206, 'ok');
$mv('ok_last_bytes_of_budget', 'window shrinks to remaining byte budget', $ms([$set(['usage', $CA['jti']], ['requests' => 10, 'bytes' => $CA['bud']['bytes'] - 100, 'in_flight' => 0, 'recent' => []])]), $req(['headers' => ['Range' => 'bytes=0-']]), 206, 'ok');

$mv('method_post', 'method', $mediaBaseArr, $req(['method' => 'POST']), 404, 'media_method');
$mv('cross_origin_origin', 'cross-origin', $mediaBaseArr, $req(['headers' => ['Origin' => 'https://evil.example']]), 403, 'media_cross_origin');
$mv('cross_site_fetch_metadata', 'hotlink', $mediaBaseArr, $noCookies($req(['headers' => ['Sec-Fetch-Site' => 'cross-site']])), 403, 'media_cross_origin');
$mv('direct_navigation', 'save-as / address bar', $mediaBaseArr, $req(['headers' => ['Sec-Fetch-Site' => 'none']]), 403, 'media_cross_origin');
$mv('hotlink_without_cookies', 'hotlink (SameSite=Strict withholds cookies)', $mediaBaseArr, $noHeader($noCookies($req()), 'Sec-Fetch-Site'), 404, 'grant_missing');
$mv('copied_url_other_browser', 'URL reuse without the grant cookie', $mediaBaseArr, $noCookies($req()), 404, 'grant_missing');
foreach ([
    'raw_source_path' => '/wp-json/lof-core/v1/audio/media/Masters/Midnight%20Overture%20(MASTER).wav',
    'raw_source_name' => '/wp-json/lof-core/v1/audio/media/Northern_Lights_Theme.mp3',
    'legacy_asset_route' => '/wp-json/lof-core/v1/audio/media/thriller.m4a',
    'rid_with_extension' => Model::MEDIA_PREFIX . $RA . '.m4a',
    'rid_uppercase' => Model::MEDIA_PREFIX . strtoupper($RA),
    'rid_trailing_slash' => Model::MEDIA_PREFIX . $RA . '/',
    'traversal' => Model::MEDIA_PREFIX . '../../../../wp-config.php',
    'encoded_traversal' => Model::MEDIA_PREFIX . '%2e%2e%2fhealth.json',
    'directory_listing' => Model::MEDIA_PREFIX,
    'generation_path' => Model::MEDIA_PREFIX . "generations/$GEN/$RA.m4a",
] as $vid => $path) {
    $mv("raw_$vid", 'raw-path guess -> indistinguishable 404', $mediaBaseArr, $req(['path' => $path]), 404, 'media_id_shape');
}
$guess = h32('attacker guess');
$adjacent = substr($RA, 0, 31) . dechex((hexdec($RA[31]) + 1) % 16);
$mv('guessed_id_no_grant', 'guessed id -> indistinguishable 404', $mediaBaseArr, $noCookies($req(['path' => Model::MEDIA_PREFIX . $guess])), 404, 'grant_missing');
$mv('guessed_id_with_grant', 'enumeration with a valid grant', $mediaBaseArr, $req(['path' => Model::MEDIA_PREFIX . $guess]), 404, 'grant_rid_mismatch');
$mv('adjacent_id_with_grant', 'adjacent-id guessing', $mediaBaseArr, $req(['path' => Model::MEDIA_PREFIX . $adjacent]), 404, 'grant_rid_mismatch');
$mv('other_track_with_grant', 'wrong track: grant for A used on B', $mediaBaseArr, $req(['path' => Model::MEDIA_PREFIX . $RB]), 404, 'grant_rid_mismatch');
$mv('disabled', 'emergency disable', $ms([$set(['disabled'], true)]), $req(), 503, 'media_disabled');
$mv('publication_unavailable', 'fail closed', $ms([$set(['publication_ok'], false)]), $req(), 503, 'media_publication_unavailable');
$mv('grant_truncated', 'malformed', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => 'lofg1.abc']]), 404, 'grant_malformed');
$mv('grant_legacy_a1', 'version (989ea9d5 a1 token shape)', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => 'a1.' . ($T0 + 300) . '.0123456789abcdef.0123456789abcdef.0123456789abcdef0123456789abcdef']]), 404, 'grant_malformed');
$mv('grant_prefix_v2', 'version', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => 'lofg2' . substr($TOK, 5)]]), 404, 'grant_version');
$mv('grant_claims_ver_2', 'version', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => $signed([$set(['ver'], 2)])]]), 404, 'grant_version');
$mv('grant_unknown_kid', 'version/key', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => $signed([$set(['kid'], 'retired-k0')])]]), 404, 'grant_version');
$mv('grant_signature_flipped', 'forgery', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => substr($TOK, 0, -5) . ($TOK[-5] === 'A' ? 'B' : 'A') . substr($TOK, -4)]]), 404, 'grant_signature');
$mv('grant_other_key', 'forgery', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => $signed([], $OTHER_KEY)]]), 404, 'grant_signature');
$mv('grant_extends_own_budget', 'forgery (edited claims, old MAC)', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => 'lofg1.' . Model::b64u(Model::canonical(Model::mutate($CA, [$set(['bud', 'bytes'], 999999999)]))) . '.' . explode('.', $TOK)[2]]]), 404, 'grant_signature');
$mv('grant_carries_path', 'claims schema (no path/source in a grant)', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => $signed([$set(['path'], $assets['A']['source_rel'])])]]), 404, 'grant_malformed');
$mv('grant_ttl_over_ceiling', 'relational claims', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => $signed([$set(['exp'], $CA['iat'] + 1801)])]]), 404, 'grant_malformed');
$mv('wrong_listener', 'grant shared with another listener', $mediaBaseArr, $req(['cookies' => [Model::SESSION_COOKIE => $SESS['L2']]]), 403, 'grant_wrong_listener');
$mv('listener_cookie_missing', 'wrong listener', $mediaBaseArr, $req(['cookies' => [Model::SESSION_COOKIE => '']]), 403, 'grant_wrong_listener');
$mv('revoked_jti', 'explicit revocation', $ms([$set(['revoked_jti'], [$CA['jti']])]), $req(), 401, 'grant_revoked');
$mv('revoked_epoch', 'revoke-all / disable cleared', $ms([$set(['epoch'], 8)]), $req(), 401, 'grant_revoked');
$mv('generation_transition', 'generation change kills old grants', $ms([$set(['generation'], $GEN_NEXT)]), $req(), 401, 'grant_generation');
$ZR = h32('unpublished rendition');
$mv('unpublished_rendition', 'unpublished item', $mediaBaseArr, $req(['path' => Model::MEDIA_PREFIX . $ZR, 'cookies' => [Model::GRANT_COOKIE => $signed([$set(['rid'], $ZR)])]]), 404, 'media_unpublished');
$mv('not_yet_valid', 'clock', $ms([$set(['now'], $CA['nbf'] - 1)]), $req(), 401, 'grant_not_yet_valid');
$mv('expired_at_boundary', 'expired grant (exp is exclusive)', $ms([$set(['now'], $CA['exp'])]), $req(), 401, 'grant_expired');
$mv('previous_item', 'item transition kills old grant', $ms([$set(['current_item'], $ITEM['B1']), $set(['next_item'], null)]), $req(), 401, 'grant_wrong_item');
$mv('replay_earlier_play_same_rendition', 'previous item (same rid, earlier instance)', $mediaBaseArr, $req(['cookies' => [Model::GRANT_COOKIE => $grants['G_PREV_A0']['token']]]), 401, 'grant_expired');
$mv('replay_earlier_play_unexpired', 'previous item (same rid, earlier instance, still inside exp)', $ms([$set(['now'], $grants['G_PREV_A0']['claims']['exp'] - 1)]), $req(['cookies' => [Model::GRANT_COOKIE => $grants['G_PREV_A0']['token']]]), 401, 'grant_wrong_item');
$mv('next_grant_before_authoritative', 'future item', $ms([$set(['now'], $T0 + 81), $set(['next_item'], null)]), $req(['path' => Model::MEDIA_PREFIX . $RB, 'cookies' => [Model::GRANT_COOKIE => $grants['G_NEXT_B']['token']]]), 401, 'grant_wrong_item');
$mv('request_budget', 'request budget', $ms([$set(['usage', $CA['jti']], ['requests' => 200, 'bytes' => 0, 'in_flight' => 0, 'recent' => []])]), $req(), 429, 'budget_requests');
$mv('byte_budget', 'byte budget', $ms([$set(['usage', $CA['jti']], ['requests' => 12, 'bytes' => $CA['bud']['bytes'], 'in_flight' => 0, 'recent' => []])]), $req(), 429, 'budget_bytes');
$mv('concurrency', 'concurrency', $ms([$set(['usage', $CA['jti']], ['requests' => 2, 'bytes' => 2, 'in_flight' => 2, 'recent' => []])]), $req(), 429, 'budget_concurrency');
$mv('rate', 'rate', $ms([$set(['usage', $CA['jti']], ['requests' => 20, 'bytes' => 40, 'in_flight' => 0, 'recent' => array_fill(0, 20, $T0 - 8)])]), $req(), 429, 'budget_rate');
$mv('range_absent', 'no whole-entity 200 ever', $mediaBaseArr, $noHeader($req(), 'Range'), 416, 'range_required');
$mv('range_unit', 'no whole-entity fallback for unknown unit', $mediaBaseArr, $req(['headers' => ['Range' => 'items=0-10']]), 416, 'range_unit');
$mv('range_multipart', 'multipart refused', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=0-99,200-299']]), 416, 'range_multipart');
$mv('range_malformed', 'malformed', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=abc-']]), 416, 'range_malformed');
$mv('range_zero_suffix', 'malformed', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=-0']]), 416, 'range_malformed');
$mv('range_past_end', 'unsatisfiable', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=24576-']]), 416, 'range_unsatisfiable');
$mv('range_reversed', 'unsatisfiable', $mediaBaseArr, $req(['headers' => ['Range' => 'bytes=5-2']]), 416, 'range_unsatisfiable');
put("$DIR/vectors/media.json", pretty(['label' => 'GET|HEAD /wp-json/lof-core/v1/audio/media/<rid>', 'media_base' => $mediaBaseArr, 'vectors' => $mediaVectors]));

/* ------------------------------------------------------ Range-probe sequences */

$sequences = [];
$seq = static function (string $id, string $pattern, array $state0, array $steps) use (&$sequences, $ctx, $schemas): void {
    $state = $state0;
    $t0 = $state0['now'];
    $out = [];
    foreach ($steps as $i => $s) {
        if (isset($s['state_ops'])) {
            $state = Model::mutate($state, $s['state_ops']);
        }
        $state['now'] = $t0 + $s['at'];
        $jti = json_decode((string) Model::unb64u(explode('.', $s['request']['cookies'][Model::GRANT_COOKIE])[1]), true)['jti'];
        $probe = $state;
        if (isset($s['in_flight'])) {
            $probe['usage'][$jti] = ($probe['usage'][$jti] ?? []) + ['requests' => 0, 'bytes' => 0, 'in_flight' => 0, 'recent' => []];
            $probe['usage'][$jti]['in_flight'] = $s['in_flight'];
        }
        $r = Model::media($ctx, $probe, $s['request'], $schemas['grant-claims']);
        must($r['reason'] === $s['want'], "sequence $id step $i: want {$s['want']}, model {$r['reason']}");
        $state = Model::applySpend($state, $s['request'], $r, $jti);
        $row = ['at' => $s['at']];
        foreach (['state_ops', 'in_flight'] as $k) {
            if (isset($s[$k])) {
                $row[$k] = $s[$k];
            }
        }
        $row['request'] = $s['request'];
        $row['expect'] = $r;
        $out[] = $row;
    }
    $sequences[] = ['id' => $id, 'label' => 'synthetic compatibility vector', 'pattern' => $pattern, 'state' => $state0, 'steps' => $out];
};
$get = static fn (string $range, ?string $tok = null, ?string $rid = null) => [
    'method' => 'GET', 'path' => Model::MEDIA_PREFIX . ($rid ?? $RA),
    'headers' => ['Range' => $range, 'Sec-Fetch-Site' => 'same-origin'],
    'cookies' => [Model::GRANT_COOKIE => $tok ?? $TOK, Model::SESSION_COOKIE => $SESS['L1']],
];
$ok = static fn (int $at, string $range, array $extra = []) => ['at' => $at, 'request' => $get($range), 'want' => 'ok'] + $extra;

$seq('safari_ios_cold_start', 'tap-to-listen: bytes=0-1 probe, bytes=0-, then windowed continuation to the end', $mediaBaseArr, [
    $ok(0, 'bytes=0-1'), $ok(1, 'bytes=0-'), $ok(2, 'bytes=4096-'), $ok(3, 'bytes=8192-'), $ok(4, 'bytes=12288-'), $ok(5, 'bytes=16384-'), $ok(6, 'bytes=20480-'),
]);
$seq('ios_background_resume_network_change', 'lock/background pause, resume at offset, overlapping retry after network change', $mediaBaseArr, [
    $ok(0, 'bytes=0-1'), $ok(1, 'bytes=0-'), $ok(2, 'bytes=4096-'), $ok(45, 'bytes=8192-'), $ok(46, 'bytes=12288-'), $ok(47, 'bytes=12000-'), $ok(48, 'bytes=16384-'), $ok(49, 'bytes=20480-'),
]);
$seq('mid_song_join_seek_suffix', 'mid-song join at an offset, iOS suffix trailer read, seek back, continue', $mediaBaseArr, [
    $ok(0, 'bytes=0-1'), $ok(1, 'bytes=14336-'), $ok(2, 'bytes=-5000'), $ok(3, 'bytes=18432-'), $ok(4, 'bytes=2048-6143'), $ok(5, 'bytes=22528-'),
]);
$seq('overlapping_fetches', 'probe overlapping an open body fetch is allowed; a third concurrent fetch is not', $mediaBaseArr, [
    $ok(0, 'bytes=0-'), $ok(1, 'bytes=0-1', ['in_flight' => 1]), ['at' => 2, 'request' => $get('bytes=4096-'), 'in_flight' => 2, 'want' => 'budget_concurrency'], $ok(3, 'bytes=4096-'),
]);
$harvest = [];
for ($i = 0; $i < 11; $i++) {
    $harvest[] = $ok($i, 'bytes=' . (($i % 6) * 4096) . '-');
}
$harvest[] = ['at' => 11, 'request' => $get('bytes=0-'), 'want' => 'budget_bytes'];
$seq('bulk_harvest_refused', 'repeated full sweeps stop at the byte budget (1.5 x size + 2 windows)', $mediaBaseArr, $harvest);
$burst = [];
for ($i = 0; $i < 20; $i++) {
    $burst[] = $ok(0, 'bytes=0-1');
}
$burst[] = ['at' => 0, 'request' => $get('bytes=0-1'), 'want' => 'budget_rate'];
$burst[] = $ok(10, 'bytes=0-1');
$seq('rate_burst', 'more than rate_n requests inside rate_s is refused; the window then recovers', $mediaBaseArr, $burst);
$TN = $grants['G_NEXT_B']['token'];
$TB = $grants['G_CUR_B']['token'];
$nearEndMedia = Model::mutate($mediaBaseArr, [$set(['now'], $T0 + 80)]);
$seq('next_item_preload_and_transition', 'bounded next-item head preload, preload budget stop, transition kills next+previous grants, current grant for B continues', $nearEndMedia, [
    ['at' => 0, 'request' => $get('bytes=0-1', $TN, $RB), 'want' => 'ok'],
    ['at' => 1, 'request' => $get('bytes=0-', $TN, $RB), 'want' => 'ok'],
    ['at' => 2, 'request' => $get('bytes=4096-', $TN, $RB), 'want' => 'ok'],
    ['at' => 3, 'request' => $get('bytes=8190-', $TN, $RB), 'want' => 'budget_bytes'],
    ['at' => 20, 'state_ops' => [$set(['current_item'], $ITEM['B1']), $set(['next_item'], null)], 'request' => $get('bytes=8190-', $TN, $RB), 'want' => 'grant_wrong_item'],
    ['at' => 20, 'request' => $get('bytes=8192-', $TOK, $RA), 'want' => 'grant_wrong_item'],
    ['at' => 21, 'request' => $get('bytes=8190-', $TB, $RB), 'want' => 'ok'],
    ['at' => 22, 'request' => $get('bytes=12286-', $TB, $RB), 'want' => 'ok'],
]);
put("$DIR/vectors/range-probes.json", pretty([
    'label' => 'synthetic compatibility vector - no real Safari/iOS capture exists in either baseline repository; these encode the request patterns the 989ea9d5 tests name (bytes=0-, bounded seek, iOS suffix, reopen-at-offset) plus the Safari bytes=0-1 probe its session class documents. No device claim.',
    'rules' => 'now = state.now + step.at; state_ops apply before the step and persist; in_flight overrides usage.in_flight for that step only; after each step usage[jti].requests += spend.requests, usage[jti].bytes += spend.bytes, usage[jti].recent += [now] when spend.requests = 1.',
    'sequences' => $sequences,
]));

/* ------------------------------------------------------------- digests */

$files = [];
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($DIR, \FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($DIR) + 1);
    if (preg_match('#^(fixtures|vectors|schemas|tools)/#', $rel) === 1 && $rel !== 'fixtures/expected-digests.json' && basename($rel) !== '.DS_Store' && !str_starts_with(basename($rel), '._')) {
        $files[$rel] = hash_file('sha256', $f->getPathname());
    }
}
ksort($files, SORT_STRING);
put("$DIR/fixtures/expected-digests.json", pretty([
    'renditions' => [$RA => $assets['A']['rendition_sha256'], $RB => $assets['B']['rendition_sha256']],
    'sources' => [$assets['A']['source_rel'] => $assets['A']['source_sha256'], $assets['B']['source_rel'] => $assets['B']['source_sha256']],
    'viewer_manifest_sha256' => $M['manifest_sha256'],
    'source_map_sha256' => $S['map_sha256'],
    'health_canonical_sha256' => Model::sha(Model::canonical($H)),
    'grant_tokens_sha256' => array_map(static fn ($g) => Model::sha($g['token']), $grants),
    'files' => $files,
]));
echo "built: " . count($docVectors) . " document, " . count($issueVectors) . " issue, " . count($mediaVectors) . " media vectors, " . count($sequences) . " sequences\n";
