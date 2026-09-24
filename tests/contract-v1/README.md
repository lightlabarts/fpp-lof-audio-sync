# Phone-audio cross-repository contract V1 (frozen 2026-09-23)

This is the only surface the two phone-audio writers share. Phase 1A (`fpp-lof-audio-sync`, based on `588418f914b223d1068d2fca46002ad56285281f`) and Phase 1B (`lights-on-falcon-show-control`/`lof-core`, based on `989ea9d5d14ff0cc601cdf53d32e045ef9a8c65d`) must implement exactly what is in this directory. `tools/model.php` is the normative reference: for every vector, an implementation must return the same reason, status, headers, claims, token and digests. **Check order is normative.** When an input is wrong in several ways, the first failing check names the reason.

Governing requirement: `PHONE-AUDIO-PROTECTION-AND-PARITY-CONTRACT-2026-09-20.md`. This directory freezes the Phase 0 contract that document calls for and changes nothing else in it.

```
php verify.php        # PHP >= 8.1, no dependency, read-only; prints one contract digest
php tools/build.php   # regenerates fixtures/ and vectors/ byte-for-byte (deterministic)
```

## 1. Ownership boundary

| Concern | Owner | The other side must never |
|---|---|---|
| Acquisition, allowlisted deterministic transcode, metadata stripping, opaque-id and source-map generation, viewer manifest, validation, atomic publication, quarantine, rollback, interrupted-generation recovery | `fpp-lof-audio-sync` | `lof-core` never publishes, transcodes, quarantines, rolls back, renames or deletes any file beneath either root. It opens files read-only. |
| Read-only verification of the viewer publication, item→rendition mapping, grants, revocation, budgets, the media route and byte pump, player, Media Session, listening evidence, operator health | `lof-core` | `fpp-lof-audio-sync` never serves bytes to a browser, issues grants, or knows about sessions or items. |
| Rendition-id key (`rid_key`) | `fpp-lof-audio-sync` only | `lof-core` never holds it and needs no key to consume. |
| Grant key (`grant_key`), item-instance ids, listener sessions | `lof-core` only | Never written to any FPP document. |

## 2. Layout

```
<viewer_root>/                      outside every static/public web root; lof-core has read-only access
  health.json                       publication health pointer (commit point, written last)
  manifests/<generation>.json       viewer rendition manifest
  generations/<generation>/<rid>.m4a    renditions only; nothing else may exist here
<private_root>/                     outside every web root AND outside <viewer_root>
  source-maps/<generation>.json     private source map
```

`lof-core` reads the viewer publication only through `health.json` → `manifests/<generation>.json` → `generations/<generation>/<rid>.m4a`. It never follows `current`/`previous` symlinks, never lists a directory to find a generation, and never reads the existing `media-supply` publication to serve bytes; that publication and its manifest v1 (`source_root`, filename keys) stay private to FPP and may appear in `lof-core` only as operator evidence.

## 3. Documents and version negotiation

| Document | Schema | Version field | Digest |
|---|---|---|---|
| Viewer rendition manifest | `schemas/viewer-rendition-manifest.v1.schema.json` | `manifest_version: 1`, `role: "viewer-rendition"` | `manifest_sha256 = sha256(canonical(doc − manifest_sha256))` |
| Publication health pointer | `schemas/publication-health-pointer.v1.schema.json` | `health_version: 1`, `role: "viewer-rendition"` | binds `current.manifest_sha256` |
| Private source map | `schemas/private-source-map.v1.schema.json` | `source_map_version: 1`, `role: "private-source-map"` | `map_sha256 = sha256(canonical(doc − map_sha256))`; binds `viewer_manifest_sha256` |
| Grant claims | `schemas/grant-claims.v1.schema.json` | token prefix `lofg1`, `typ`, `ver: 1`, `kid` | HMAC-SHA256 |
| Media decision/result | `schemas/media-decision.v1.schema.json` | — | `body_sha256` of served bytes |
| Grant response (visitor) | `schemas/grant-response.v1.schema.json` | — | — |

Negotiation is **exact match, no fallback**. A consumer accepts exactly the versions listed here and refuses everything else with the stage's `*_version` reason. There are no optional or ignorable fields. `additionalProperties` is false everywhere. The one profile allowed is `lof-viewer-aac-lc-m4a-v1`: AAC-LC, M4A, 2 ch, 44.1 kHz, 128 kbit/s, metadata stripped. It is a schema `const`, so a second codec or profile is a schema failure. Any change is V2, which means a new contract directory, a new digest, and a consumer that lists V2 before any producer writes it.

`canonical(x)` is byte-identical to `LofAudioSupply\Support\Json::canonical()` and `LOF_Audio_Manifest::canonical()`. Associative arrays are recursively `ksort(SORT_STRING)`, lists are preserved, and output uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`. All numbers are integers. Rendition ids are 32 hex characters, so PHP never coerces them to integer keys.

## 4. Identifiers and cryptography

- **Rendition id (`rid`)**: `lower_hex(HMAC-SHA256(rid_key, canonical(["lof-rid",1,generation,profile_id,source_rel,source_sha256])))[0:32]`. This is exactly 128 bits and scoped to one generation, because a new generation gives new ids. It is not a source hash or a reversible filename encoding. `lof-core` checks, and refuses with `rid_derivable`, any rid that is contained in, or contains the first 64 bits of, a plain source/rendition digest, sha256/sha1/md5/hex/base64url of `source_rel` or its basename.
- **`source_rel`** follows `SafePath::assertRelative` (UTF-8, spaces allowed). `lof-core` compares it as an exact byte string against its operator item map (`sequence name → source_rel`, the existing `lof_audio_asset_map` role). It **never** uses it as a filesystem path, so the old `asset_for_path` grammar no longer governs it.
- **Listener subject**: `sub = lower_hex(HMAC-SHA256(grant_key, "lof-sub|v1|" + session))[0:32]`. `session` matches `^[A-Za-z0-9_-]{22,64}$`. It is validated, never sanitised.
- **Item instance id**: opaque 32-hex, computed by `lof-core` from its authoritative item instance with its own key. It must be stable for one play of an item and different for every play, including a repeat of the same song. The fixtures give these ids directly.
- **Token**: `"lofg1." + b64url(canonical(claims)) + "." + b64url(HMAC-SHA256(grant_key, "lofg1." + b64url(canonical(claims))))`. It uses unpadded base64url and a strict round-trip decode. `kid` must equal the active key id, so rotating the key invalidates every live grant. The 989ea9d5 `a1.*` token is retired and is refused as `grant_malformed`.

## 5. Production sequence (`fpp-lof-audio-sync`)

1. Take masters only from a verified `media-supply` generation (`supply_generation`, `supply_manifest_sha256`).
2. For each master, sorted by `source_rel`, transcode with the existing allowlisted argv runner. No shell. The invocation must be deterministic (bit-exact flags), must strip all metadata, chapters, artwork and tags, and must output the V1 profile into `staging/<generation>/<rid>.m4a`.
3. Probe each output: it must be the V1 profile, carry no tag or metadata atoms, and have `rendition_sha256 ≠ source_sha256`.
4. Write the manifest in staging. Verify staging against it: no missing, extra or symlinked file, and exact size and digest.
5. Commit in this order, each step atomic (temp file + fsync + rename): `generations/<generation>/` → `manifests/<generation>.json` → `<private_root>/source-maps/<generation>.json` → **`health.json` last**. Until `health.json` names a generation, that generation does not exist for `lof-core`.
6. `health.json` gets `state: "ok"` only when `current` is fully verified. Anything else is `degraded` or `failed`, and `lof-core` refuses both. A failed run that leaves a verified `current` in place keeps `state: "ok"`. The failure is reported in the media-supply health, not here.
7. **Rollback** re-points `health.json` to a retained generation. This changes the generation, so every grant ends (`grant_generation`). Never quarantine or remove the generation `health.json` names or its `previous_generation`.
8. **Interrupted** generations (`.incomplete`, staging leftovers) are never referenced by `health.json`. Recovery quarantines them and never deletes them.

## 6. Consumption sequence (`lof-core`)

**A. Publication load.** This is `Model::pipeline`. Cache it keyed on the health bytes for ≤ 5 s, which is also the revocation/disable bound.
1. `<viewer_root>` and `<private_root>` are configured absolute paths. Either one inside a web root → `publication_root_public`.
2. `health.json`: `health_version` → forbidden keys (`health_source_leak`) → component/role → `current.generation` shape → schema → `state == ok` → `current != null`.
3. `manifests/<current.generation>.json`: version → forbidden keys (`manifest_source_leak`) → role/component → algorithm → generation → rid key shape (`manifest_rendition_id`) → schema → derived counters → self-digest.
4. Health↔manifest: generation, then `manifest_sha256` (`publication_health_digest`), then counters and profile.
5. The source map for that generation: version → role → algorithm → schema → `source_rel` safety → generation → `viewer_manifest_sha256` → self-digest → rid coverage → rendition size/digest agreement → one source per rid → not byte-identical to its master → `rid_derivable`.
6. Every rendition file: lstat is a regular file (not a symlink or directory), real path contained in `generations/<generation>/`, exact size, exact sha256. The full hash is checked once per (generation, rid, inode, size, mtime). Every request re-checks lstat, containment and size. Any other entry in the directory → `file_unexpected`.

Any failure: no grant is issued (`issue_publication`), every media request gets `503 media_publication_unavailable`, and the visitor is offered FM.

**B. Grant (`POST /wp-json/lof-core/v1/audio/grant`, body `{session, ctx}`).** The client names only `ctx ∈ {current, next}`, and any other field is ignored. The server chooses item, rid and generation. See `Model::issue` for the check order. Expiry is:

```
remaining_now = max(0, remaining_s − (now − observed_at))      # observation age must be 0..5 s
exp           = now + min(max_ttl_s = 300, remaining_now + recovery_margin_s = 20)
```

`next` is issued only when the upcoming item is authoritative, `remaining_now ≤ 30 s`, and its rid differs from the current rid. It dies at the transition. Budgets (production defaults; `policy_fixture` differs only in `window_bytes = 4096`):

| ctx | req | bytes | conc | rate | win |
|---|---|---|---|---|---|
| current | 200 | ⌊1.5 × size⌋ + 2 × win | 2 | 20 / 10 s | 262144 |
| next | 8 | min(size, 2 × win) | 1 | 8 / 10 s | 262144 |

The response body (schema `grant-response`) never contains the token, a sequence name or a source fact. `item.title` is `lof-core` show copy. It sets two cookies, both `Secure; HttpOnly; SameSite=Strict`: `lof_ag=<token>` with `Path=/wp-json/lof-core/v1/audio/media/<rid>` and `Max-Age=exp−now`, and `lof_as=<session>` with `Path=/wp-json/lof-core/v1/audio/`. The media URL `/wp-json/lof-core/v1/audio/media/<rid>` therefore carries no credential. A copied URL, another browser, or a hotlinking site has no cookie and gets `404`.

**C. Media (`GET|HEAD /wp-json/lof-core/v1/audio/media/<rid>`).** See `Model::media`. The order is:
1. method
2. `Origin` ≠ own, or `Sec-Fetch-Site` present and ≠ `same-origin` → 403
3. id shape
4. disabled / publication (503)
5. grant cookie → token shape, version, signature, claims schema and relations → `claims.rid == rid`
6. listener (403)
7. epoch/jti revocation
8. generation
9. rid published
10. nbf/exp (`exp` exclusive)
11. item binding (`current` must equal the authoritative current item; `next` the authoritative next)
12. budgets: requests, bytes, concurrency, rate (429)
13. Range

Range handling:
- There is **no whole-entity response**. A missing Range or an unknown unit gets `416`. Multipart, malformed and unsatisfiable ranges get `416` with `Content-Range: bytes */size`.
- Every `206` is at most `min(win, bytes_budget − bytes_used)` long. It is clamped from the start, except suffix ranges, which are clamped from the end.
- A decided request spends 1 request and the served bytes. HEAD spends 0 bytes. A `416` after authorization spends 1 request and 0 bytes. Refusals before that point spend nothing.

Headers are exact:
- On `206`: `Accept-Ranges: bytes`, `Cache-Control: private, no-store`, `Content-Disposition: inline; filename="lof-listen.m4a"`, `Content-Length`, `Content-Range`, `Content-Type: audio/mp4`, `Cross-Origin-Resource-Policy: same-origin`, `X-Content-Type-Options: nosniff`, plus `Access-Control-Allow-Origin: <own>` and `Vary: Origin` only when `Origin` equals the own origin. Never `*`.
- On a refusal: an empty body with `Cache-Control`, `Content-Length: 0`, `CORP`, `nosniff`. **Every 404 is byte-identical**: raw filenames, traversal, guessed or adjacent ids, a missing, forged or wrong-rid grant, and unpublished ids.

Telemetry is `{event, status, reason, ctx, bytes}` and nothing else. It never contains a grant, session, path, source or coordinates.

## 7. Reason vocabulary

| Stage | Reasons (first failure wins) |
|---|---|
| health | `health_missing` `health_version` `health_source_leak` `health_component` `health_generation` `health_schema` `health_state` `health_no_current` |
| manifest | `manifest_missing` `manifest_version` `manifest_source_leak` `manifest_role` `manifest_algorithm` `manifest_generation` `manifest_schema` `manifest_rendition_id` `manifest_counter` `manifest_digest` |
| publication | `publication_generation_drift` `publication_health_digest` `publication_health_counter` |
| source map | `source_map_missing` `source_map_version` `source_map_role` `source_map_algorithm` `source_map_schema` `source_map_path` `source_map_generation` `source_map_manifest_digest` `source_map_digest` `source_map_coverage` `source_map_rendition_drift` `source_map_duplicate_source` `source_map_not_transcoded` `rid_derivable` |
| observation | `publication_root_public` `file_missing` `file_symlink` `file_type` `file_outside_root` `file_size_drift` `file_digest_drift` `file_unexpected` |
| grant issue (HTTP 200, `ok:false`, `fallback:"fm"`) | `issue_disabled` `issue_publication` `issue_session` `issue_ctx` `issue_nothing_playing` `issue_playhead_stale` `issue_remaining_unknown` `issue_next_not_authoritative` `issue_next_too_early` `issue_unmapped` `issue_unpublished` `issue_next_same_rendition` |
| media 404 | `media_method` `media_id_shape` `media_unpublished` `grant_missing` `grant_malformed` `grant_version` `grant_signature` `grant_rid_mismatch` |
| media 403 / 401 / 429 / 416 / 503 | `media_cross_origin` `grant_wrong_listener` / `grant_revoked` `grant_generation` `grant_not_yet_valid` `grant_expired` `grant_wrong_item` / `budget_requests` `budget_bytes` `budget_concurrency` `budget_rate` / `range_required` `range_unit` `range_multipart` `range_malformed` `range_unsatisfiable` / `media_disabled` `media_publication_unavailable` |

Reasons are server-side, for telemetry and operator use. Visitors see only the HTTP status and headers, and for grant refusals the issue reason plus FM.

## 8. Fixtures and vectors

- `fixtures/publication/` holds one generation (`20260923T020000Z-5e1f0a3c`) with two renditions. Its bytes are **synthetic and not decodable audio**. The generator is in `fixtures/constants.json`. The tree is laid out exactly as §2.
- `fixtures/grants.json` holds four issued grants: current A, bounded next B, current B after the transition, and an earlier play of A. Each has its state, request, claims, token, response and cookies.
- `fixtures/expected-digests.json` holds every file, rendition, source, document and token digest.
- `vectors/documents.json` has 58 publication tamper vectors. `vectors/issue.json` has 20 issuance vectors, including four expiry clamps. `vectors/media.json` has 64 single requests.
- `vectors/range-probes.json` has 7 sequences, all labelled **synthetic compatibility vector**. Neither baseline repository contains a real Safari/iOS capture. The sequences encode the patterns the 989ea9d5 tests and the session class document: the `bytes=0-1` probe, `bytes=0-`, reopen at an offset, seek, the iOS suffix, background resume, mid-song join and overlap. They also cover harvest, burst and the next→current transition.
- The keys in `constants.json` are fixture keys and must never be deployed.

## 9. Evidence vocabulary and gates

States are **Built**, **Tested**, **Deployed**, **Connected**, **Physically rehearsed** and **Founder accepted**. Claim only the highest state evidenced. This directory is a specification artifact. Passing `verify.php` proves internal consistency, not any implementation.

| Gate | Required evidence |
|---|---|
| 1A Tested | FPP focused tests reproduce this fixture's manifest, source map and health documents and digests from the synthetic masters through a stub transcoder, and reject every applicable document vector. The allowlisted real encoder is proven deterministic across two runs. No `lof-core` edit. |
| 1B Tested | `lof-core` focused PHP tests pass every document, issue, media and sequence vector with identical outputs. JS player tests consume the grant response shape. Source-scan tests prove no write/unlink/rename call reaches either root. |
| Phase 2 Tested | One real FPP-built publication fed unchanged to `lof-core`. The full vector corpus passes. The leak scan passes against real responses. Both worktrees are clean, pushed, remote-equal, and based on their baselines. |
| Connected / Physically rehearsed | Only in Phase 4. This needs real devices, Wi-Fi and cellular, the house/FM reference, load, and outage and disable drills. Nothing in this contract claims them. |

## 10. Named deferrals (not V1)

DRM/FairPlay/Widevine, encrypted HLS, per-listener watermarking, a native app, more than one codec or profile, bitrate ladders, a new media service or second publisher. Also deferred:
- Per-session caps that span several grants.
- `Retry-After` on 429.
- Multi-key grant rotation windows.
- Real-device Range captures, which replace the synthetic vectors in Phase 4.
- The frozen device matrix and synchronization thresholds.
- Production values for the window, the byte multiplier and the rate. The defaults above are provisional until device evidence exists.
- The Media Session, background, drift and recovery player behaviour. That is Phase 1B scope, but it is not a cross-repository contract.
- Listener accounting.
