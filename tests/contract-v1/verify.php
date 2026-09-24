#!/usr/bin/env php
<?php
/**
 * Phone-audio cross-repository contract V1 — focused verifier.
 *
 *   php verify.php
 *
 * PHP >= 8.1 (the runtime both fpp-lof-audio-sync and lof-core already use),
 * core + hash + json + pcre only. Read-only: writes nothing, contacts nothing.
 * Verifies every fixture and vector against the schemas and the reference
 * model, then prints one digest for the whole directory (RETURN.md excluded,
 * because it records that digest).
 */

declare(strict_types=1);

namespace LofPhoneAudioContractV1;

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "PHP >= 8.1 required\n");
    exit(2);
}
require __DIR__ . '/tools/model.php';

$DIR = __DIR__;
$checks = 0;
$fail = 0;
function check(bool $ok, string $what): void
{
    global $checks, $fail;
    $checks++;
    if (!$ok) {
        $fail++;
        fwrite(STDERR, "FAIL $what\n");
    }
}
function jload(string $path, bool $assoc = true)
{
    return json_decode((string) file_get_contents($path), $assoc, 64, JSON_THROW_ON_ERROR);
}
function synth(string $label, int $size): string
{
    $out = '';
    for ($i = 0; strlen($out) < $size; $i++) {
        $out .= hash('sha256', 'lof-phone-audio-contract-v1|' . $label . '|' . $i, true);
    }
    return substr($out, 0, $size);
}
$groups = [];
function group(string $name): void
{
    global $groups, $checks;
    $groups[] = [$name, $checks];
}

/* ------------------------------------------------------------ inventory */

group('inventory');
$all = [];
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($DIR, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($DIR) + 1);
    check(!is_link($f->getPathname()), "no symlink in contract: $rel");
    $b = basename($rel);
    if ($f->isFile() && $b !== '.DS_Store' && !str_starts_with($b, '._')) {
        $all[$rel] = hash_file('sha256', $f->getPathname());
    }
}
ksort($all, SORT_STRING);

/* -------------------------------------------------------------- schemas */

group('schemas');
$schemas = [];
foreach (['viewer-rendition-manifest', 'publication-health-pointer', 'private-source-map', 'grant-claims', 'media-decision', 'grant-response'] as $n) {
    $schemas[$n] = jload("$DIR/schemas/$n.v1.schema.json", false);
    check(Schema::unsupported($schemas[$n]) === [], "schema $n uses only the supported subset");
}

/* ---------------------------------------------------------- publication */

group('publication');
$C = jload("$DIR/fixtures/constants.json");
$X = jload("$DIR/fixtures/expected-digests.json");
$GEN = $C['generation'];
$PUB = "$DIR/fixtures/publication";
$H = jload("$PUB/viewer-root/health.json");
$M = jload("$PUB/viewer-root/manifests/$GEN.json");
$S = jload("$PUB/private-root/source-maps/$GEN.json");
$genDir = "$PUB/viewer-root/generations/$GEN";
$realGen = (string) realpath($genDir);
$bytes = [];
$sourceShas = [];
foreach ($C['assets'] as $k => $a) {
    $src = synth("source-$k", $a['source_size']);
    $ren = synth("rendition-$k", $a['rendition_size']);
    check(hash('sha256', $src) === $a['source_sha256'] && $X['sources'][$a['source_rel']] === $a['source_sha256'], "source $k digest");
    check(hash('sha256', $ren) === $a['rendition_sha256'] && $X['renditions'][$a['rid']] === $a['rendition_sha256'], "rendition $k digest");
    check($src !== $ren && strlen($ren) < strlen($src), "rendition $k is not the master");
    check(Model::deriveRid($C['rid_key_hex'], $GEN, $a['source_rel'], $a['source_sha256']) === $a['rid'], "rid $k keyed derivation");
    check(preg_match(Model::RID_RE, $a['rid']) === 1, "rid $k is 128-bit lowercase hex");
    $file = "$genDir/{$a['rid']}.m4a";
    check(!is_link($file) && is_file($file) && file_get_contents($file) === $ren, "rendition $k file bytes");
    check(str_starts_with((string) realpath($file), $realGen . '/'), "rendition $k real path contained");
    $bytes[$a['rid']] = $ren;
    $sourceShas[] = $a['source_sha256'];
}
$obs = ['root_public' => false, 'entries' => []];
foreach (scandir($genDir) as $n) {
    if ($n === '.' || $n === '..' || $n === '.DS_Store' || str_starts_with($n, '._')) {
        continue;
    }
    $p = "$genDir/$n";
    $type = is_link($p) ? 'symlink' : (is_file($p) ? 'file' : (is_dir($p) ? 'dir' : 'other'));
    $obs['entries'][$n] = ['type' => $type, 'within_root' => str_starts_with((string) realpath($p), $realGen . '/'),
                           'size' => (int) filesize($p), 'sha256' => (string) hash_file('sha256', $p)];
}
check(Model::pipeline($H, $M, $S, $obs, $schemas) === ['ok', 'ok'], 'valid publication passes the consumer pipeline from disk');
check(Schema::errors(Model::toObj($H), $schemas['publication-health-pointer']) === [], 'health schema');
check(Schema::errors(Model::toObj($M), $schemas['viewer-rendition-manifest']) === [], 'manifest schema');
check(Schema::errors(Model::toObj($S), $schemas['private-source-map']) === [], 'source map schema');
check($M['manifest_sha256'] === $X['viewer_manifest_sha256'] && $S['map_sha256'] === $X['source_map_sha256'], 'document digests');
check(Model::sha(Model::canonical($H)) === $X['health_canonical_sha256'], 'health canonical digest');
$viewerFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$PUB/viewer-root", \FilesystemIterator::SKIP_DOTS));
foreach ($viewerFiles as $f) {
    check(!in_array(hash_file('sha256', $f->getPathname()), $sourceShas, true), 'no master beneath viewer root: ' . $f->getFilename());
    check(preg_match('/\.(wav|mp3|flac|aac|fseq)$/i', $f->getFilename()) !== 1, 'no source extension beneath viewer root');
}
check(!str_starts_with((string) realpath("$PUB/private-root"), (string) realpath("$PUB/viewer-root")), 'private root is outside viewer root');

/* ------------------------------------------------------------------ ctx */

$policy = $C['policy_fixture'];
$map = $C['lof_core_item_map'];
$index = [];
$sizes = [];
foreach ($S['entries'] as $rid => $e) {
    $index[$e['source_rel']] = $rid;
}
foreach ($M['renditions'] as $rid => $e) {
    $sizes[$rid] = $e['size'];
}
$ctx = ['grant_key_hex' => $C['grant_key_hex'], 'kid' => $C['grant_kid'], 'policy' => $policy, 'map' => $map,
        'index' => $index, 'sizes' => $sizes, 'own_origin' => $C['own_origin'], 'bytes' => $bytes];
$visible = [];

/* --------------------------------------------------------------- grants */

group('grants');
$G = jload("$DIR/fixtures/grants.json");
$clamp = static function (array $state, array $claims) use ($policy): bool {
    $age = $state['now'] - $state['current']['observed_at'];
    $rem = max(0, $state['current']['remaining_s'] - $age);
    return $claims['exp'] - $claims['iat'] === min($policy['max_ttl_s'], $rem + $policy['recovery_margin_s']);
};
foreach ($G as $id => $g) {
    $r = Model::issue($ctx, $g['state'], $g['request']);
    check($r['ok'] && $r['claims'] === $g['claims'] && $r['token'] === $g['token'] && $r['response'] === $g['response'], "grant $id reproduces");
    check(Model::sha($g['token']) === $X['grant_tokens_sha256'][$id], "grant $id token digest");
    [$pr, $pc] = Model::parseToken($g['token'], $C['grant_key_hex'], $C['grant_kid'], $schemas['grant-claims']);
    check($pr === 'ok' && Model::canonical($pc) === Model::canonical($g['claims']), "grant $id verifies");
    check(Schema::errors(Model::toObj($g['claims']), $schemas['grant-claims']) === [], "grant $id claims schema");
    check(Schema::errors(Model::toObj($g['response']['body']), $schemas['grant-response']) === [], "grant $id response schema");
    check($clamp($g['state'], $g['claims']), "grant $id expiry clamp");
    check(!str_contains($g['token'], $g['request']['session']) && !str_contains(json_encode($g['response']['body']), $g['token']), "grant $id: session not in token, token not in body");
    $visible[] = json_encode($g['response']) . Model::canonical($g['claims']);
}

/* ---------------------------------------------------- document vectors */

group('document vectors');
$D = jload("$DIR/vectors/documents.json");
$base = ['health' => $H, 'manifest' => $M, 'source_map' => $S, 'observation' => $obs];
$seen = [];
foreach ($D['vectors'] as $v) {
    $d = [];
    foreach ($base as $k => $b) {
        $d[$k] = $v[$k] === 'base' ? $b : $v[$k];
    }
    [$stage, $reason] = Model::pipeline($d['health'], $d['manifest'], $d['source_map'], $d['observation'], $schemas);
    check(['stage' => $stage, 'reason' => $reason] === $v['expect'] && $reason !== 'ok', "document {$v['id']} -> {$v['expect']['reason']}");
    $seen[$reason] = true;
}

/* ------------------------------------------------------- issue vectors */

group('issue vectors');
$I = jload("$DIR/vectors/issue.json");
foreach ($I['vectors'] as $v) {
    $state = $v['state'] === 'issue_base' ? $G['G_CUR_A']['state'] : $v['state'];
    $r = Model::issue($ctx, $state, $v['request']);
    check($r === $v['expect'], "issue {$v['id']} -> {$v['expect']['reason']}");
    check(Schema::errors(Model::toObj($r['response']['body']), $schemas['grant-response']) === [], "issue {$v['id']} response schema");
    if ($r['ok']) {
        check($clamp($state, $r['claims']), "issue {$v['id']} expiry clamp");
        check($r['claims']['rid'] === $index[$map[$state['current']['name']]], "issue {$v['id']} rid chosen by server");
    }
    $visible[] = json_encode($r['response']);
    $seen[$r['reason']] = true;
}

/* ------------------------------------------------------- media vectors */

group('media vectors');
$MV = jload("$DIR/vectors/media.json");
$results = [];
foreach ($MV['vectors'] as $v) {
    $state = $v['state'] === 'media_base' ? $MV['media_base'] : $v['state'];
    $r = Model::media($ctx, $state, $v['request'], $schemas['grant-claims']);
    check($r === $v['expect'], "media {$v['id']} -> {$v['expect']['status']} {$v['expect']['reason']}");
    $results[] = $r;
}

/* ---------------------------------------------------- Range sequences */

group('range-probe sequences');
$RP = jload("$DIR/vectors/range-probes.json");
foreach ($RP['sequences'] as $sq) {
    check($sq['label'] === 'synthetic compatibility vector', "sequence {$sq['id']} labelled synthetic");
    $state = $sq['state'];
    $t0 = $state['now'];
    foreach ($sq['steps'] as $i => $s) {
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
        check($r === $s['expect'], "sequence {$sq['id']} step $i -> {$s['expect']['reason']}");
        $state = Model::applySpend($state, $s['request'], $r, $jti);
        $results[] = $r;
    }
}

/* ---------------------------------------- cross-cutting response rules */

group('response invariants');
$first404 = null;
foreach ($results as $r) {
    check(Schema::errors(Model::toObj($r), $schemas['media-decision']) === [], 'media result schema');
    $seen[$r['reason']] = true;
    $hd = $r['headers'];
    check(($hd['Cache-Control'] ?? '') === 'private, no-store' && ($hd['X-Content-Type-Options'] ?? '') === 'nosniff'
        && ($hd['Cross-Origin-Resource-Policy'] ?? '') === 'same-origin' && ($hd['Access-Control-Allow-Origin'] ?? '') !== '*', 'security headers exact');
    if ($r['status'] === 206) {
        check($hd['Content-Disposition'] === Model::DISPOSITION && $r['range']['length'] <= $policy['window_bytes'], '206 is windowed, inline, generic name');
    } else {
        check($hd['Content-Length'] === '0' && $r['body_sha256'] === null && $r['spend']['bytes'] === 0, 'refusal has empty body');
    }
    if ($r['status'] === 404) {
        $shape = json_encode($hd);
        $first404 = $first404 ?? $shape;
        check($shape === $first404, 'every 404 is indistinguishable');
    }
    $visible[] = json_encode($r['headers']) . json_encode($r['telemetry']);
}

/* ------------------------------------------------------------ leak scan */

group('leak scan');
$visible[] = (string) file_get_contents("$PUB/viewer-root/health.json");
$visible[] = (string) file_get_contents("$PUB/viewer-root/manifests/$GEN.json");
$visible[] = implode("\n", array_keys($obs['entries']));
$blob = strtolower(implode("\n", $visible));
foreach ($C['leak_denylist'] as $needle) {
    check(!str_contains($blob, strtolower($needle)), 'no visitor-visible leak of ' . $needle);
}

/* -------------------------------------------------- vocabulary coverage */

group('vocabulary coverage');
$vocab = [];
foreach ($schemas['media-decision']->properties->reason->enum as $r) {
    $vocab[] = $r;
}
foreach ($schemas['grant-response']->oneOf[1]->properties->reason->enum as $r) {
    $vocab[] = $r;
}
foreach ($vocab as $r) {
    check(isset($seen[$r]), "reason $r exercised by a vector");
}

/* ----------------------------------------------------- expected digests */

group('file digests');
$listed = $X['files'];
$actual = array_filter($all, static fn ($rel) => preg_match('#^(fixtures|vectors|schemas|tools)/#', $rel) === 1 && $rel !== 'fixtures/expected-digests.json', ARRAY_FILTER_USE_KEY);
check(array_keys($listed) === array_keys($actual), 'file set equals expected-digests.json');
foreach ($listed as $rel => $sha) {
    check(($actual[$rel] ?? '') === $sha, "digest $rel");
}

/* --------------------------------------------------------------- report */

$counts = [];
for ($i = 0; $i < count($groups); $i++) {
    $end = $groups[$i + 1][1] ?? $checks;
    $counts[] = sprintf('%-24s %4d', $groups[$i][0], $end - $groups[$i][1]);
}
echo implode("\n", $counts), "\n";
if ($fail > 0) {
    echo "FAIL $fail of $checks checks\n";
    exit(1);
}
$lines = '';
foreach ($all as $rel => $sha) {
    if ($rel !== 'RETURN.md') {
        $lines .= $sha . '  ' . $rel . "\n";
    }
}
echo "PASS $checks checks\n";
echo 'contract_files ' . substr_count($lines, "\n") . "\n";
echo 'contract_sha256 ' . hash('sha256', $lines) . "\n";
