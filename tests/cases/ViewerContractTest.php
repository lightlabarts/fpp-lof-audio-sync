<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Support\Json;
use LofAudioSupply\Viewer\Contract;
use LofAudioSupply\Viewer\ContractSchema;
use LofTest\ContractFixture;
use LofTest\TestCase;

/**
 * The frozen phone-audio contract V1, publisher side.
 *
 * The contract directory is vendored byte-for-byte under tests/contract-v1 so
 * the gate needs nothing outside the repository, and the first test re-derives
 * the recorded whole-contract digest from it. Every publisher-side fixture and
 * vector is then replayed against this component's implementation, and cross-
 * checked against the contract's own normative model.
 */
final class ViewerContractTest extends TestCase
{
    public function testVendoredContractReproducesTheFrozenWholeContractDigest(): void
    {
        $this->assertSame(Contract::CONTRACT_SHA256, ContractFixture::contractDigest());
        $this->assertSame('a21c74cc0741b2a783a959aed9f77ba7de3eb278820f322758d76c87bd1e3b13', Contract::CONTRACT_SHA256);
        $this->assertFalse(is_file(ContractFixture::dir() . '/RETURN.md'), 'RETURN.md is outside the digest and is not vendored.');
    }

    public function testEveryVendoredFileMatchesTheFrozenFileDigests(): void
    {
        $files = ContractFixture::digests()['files'];
        $this->assertCount(19, $files, 'expected-digests.json lists every file except itself, the verifier, and README.md');
        foreach ($files as $rel => $digest) {
            $this->assertSame($digest, hash_file('sha256', ContractFixture::dir() . '/' . $rel), $rel);
        }
    }

    public function testRuntimeSchemasAreThePinnedFrozenSchemas(): void
    {
        $files = ContractFixture::digests()['files'];
        foreach (Contract::SCHEMA_SHA256 as $name => $digest) {
            $this->assertSame($files['schemas/' . $name . '.v1.schema.json'], $digest, $name);
            $this->assertSame($digest, hash_file('sha256', Contract::schemaDir() . '/' . $name . '.v1.schema.json'), $name);
            $this->assertSame(
                (string) file_get_contents(ContractFixture::dir() . '/schemas/' . $name . '.v1.schema.json'),
                (string) file_get_contents(Contract::schemaDir() . '/' . $name . '.v1.schema.json'),
                $name . ' must be a byte copy'
            );
        }
        foreach (Contract::schemas() as $schema) {
            $this->assertSame([], ContractSchema::unsupported($schema));
        }
        $this->assertSame('lof-viewer-aac-lc-m4a-v1', Contract::profile()['profile_id']);
        $this->assertSame('stripped', Contract::profile()['metadata']);
    }

    public function testAlteredOrMissingSchemaFailsClosed(): void
    {
        $dir = $this->makeTempRoot('schemas');
        foreach (array_keys(Contract::SCHEMA_SHA256) as $name) {
            copy(Contract::schemaDir() . '/' . $name . '.v1.schema.json', $dir . '/' . $name . '.v1.schema.json');
        }
        $this->assertCount(3, Contract::loadSchemas($dir));

        // One widened pattern is a different contract.
        $path = $dir . '/viewer-rendition-manifest.v1.schema.json';
        file_put_contents($path, str_replace('^[0-9a-f]{32}$', '^.+$', (string) file_get_contents($path)));
        $this->assertRefused('viewer.schema_digest', static fn () => Contract::loadSchemas($dir));

        unlink($path);
        $this->assertRefused('viewer.schema_digest', static fn () => Contract::loadSchemas($dir));

        symlink(Contract::schemaDir() . '/viewer-rendition-manifest.v1.schema.json', $path);
        $this->assertRefused('viewer.schema_digest', static fn () => Contract::loadSchemas($dir), 'A symlinked schema is refused.');
    }

    public function testRidDerivationReproducesTheFixtureIds(): void
    {
        $c = ContractFixture::constants();
        $key = (string) hex2bin($c['rid_key_hex']);
        foreach ($c['assets'] as $asset) {
            $rid = Contract::deriveRid($key, $c['generation'], $asset['source_rel'], $asset['source_sha256']);
            $this->assertSame($asset['rid'], $rid);
            $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $rid), '128 bits, lowercase hex');
            $this->assertFalse(Contract::ridDerivable($rid, $asset));
            // Generation-scoped: the same master in another generation is another id.
            $this->assertNotSame($rid, Contract::deriveRid($key, $c['previous_generation'], $asset['source_rel'], $asset['source_sha256']));
            // Keyed: without rid_key the id cannot be recomputed.
            $this->assertNotSame($rid, Contract::deriveRid(str_repeat("\0", 32), $c['generation'], $asset['source_rel'], $asset['source_sha256']));
        }
        $a = $c['assets']['A'];
        $this->assertTrue(Contract::ridDerivable(substr($a['source_sha256'], 0, 32), $a), 'A source hash is not an opaque id.');
        $this->assertTrue(Contract::ridDerivable(substr(bin2hex(basename($a['source_rel'])), 0, 32), $a), 'A hex filename is not an opaque id.');
        $this->assertTrue(Contract::ridDerivable(substr(md5($a['source_rel']), 0, 32), $a));
    }

    public function testFixturePublicationPassesTheFullPipelineFromDisk(): void
    {
        $c = ContractFixture::constants();
        $pub = ContractFixture::dir() . '/fixtures/publication';
        $h = Json::readFile($pub . '/viewer-root/health.json');
        $m = Json::readFile($pub . '/viewer-root/manifests/' . $c['generation'] . '.json');
        $s = Json::readFile($pub . '/private-root/source-maps/' . $c['generation'] . '.json');
        $o = Contract::observe($pub . '/viewer-root/generations/' . $c['generation'], false);
        $this->assertSame(['ok', 'ok'], Contract::pipeline($h, $m, $s, $o));
        $d = ContractFixture::digests();
        $this->assertSame($d['viewer_manifest_sha256'], Contract::manifestDigest($m));
        $this->assertSame($d['source_map_sha256'], Contract::sourceMapDigest($s));
        $this->assertSame($d['health_canonical_sha256'], hash('sha256', Json::canonical($h)));
    }

    public function testEveryDocumentVectorIsRefusedForTheFrozenReason(): void
    {
        require_once ContractFixture::dir() . '/tools/model.php';
        $schemas = [];
        foreach (['viewer-rendition-manifest', 'publication-health-pointer', 'private-source-map'] as $n) {
            $schemas[$n] = json_decode((string) file_get_contents(ContractFixture::dir() . "/schemas/$n.v1.schema.json"), false, 64, JSON_THROW_ON_ERROR);
        }
        $c = ContractFixture::constants();
        $pub = ContractFixture::dir() . '/fixtures/publication';
        $base = [
            'health' => Json::readFile($pub . '/viewer-root/health.json'),
            'manifest' => Json::readFile($pub . '/viewer-root/manifests/' . $c['generation'] . '.json'),
            'source_map' => Json::readFile($pub . '/private-root/source-maps/' . $c['generation'] . '.json'),
            'observation' => Contract::observe($pub . '/viewer-root/generations/' . $c['generation'], false),
        ];
        $vectors = Json::readFile(ContractFixture::dir() . '/vectors/documents.json')['vectors'];
        $this->assertCount(58, $vectors);
        $reasons = [];
        foreach ($vectors as $v) {
            $docs = [];
            foreach (['health', 'manifest', 'source_map', 'observation'] as $k) {
                $docs[$k] = $v[$k] === 'base' ? $base[$k] : $v[$k];
            }
            $ours = Contract::pipeline($docs['health'], $docs['manifest'], $docs['source_map'], $docs['observation']);
            $want = [$v['expect']['stage'], $v['expect']['reason']];
            $this->assertSame($want, $ours, 'vector ' . $v['id']);
            $model = \LofPhoneAudioContractV1\Model::pipeline($docs['health'], $docs['manifest'], $docs['source_map'], $docs['observation'], $schemas);
            $this->assertSame($model, $ours, 'model parity ' . $v['id']);
            $reasons[$want[1]] = true;
        }
        $this->assertTrue(count($reasons) >= 40, 'The vectors cover the publication reason vocabulary.');
    }
}
