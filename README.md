# FPP LOF Audio Supply

An FPP plugin that publishes audio from the Falcon Player media estate as **verified, versioned
generations**: SHA-256 manifests, atomic activation, quarantine instead of deletion, and bounded
rollback.

> **Scope.** Media supply only. This component does not own browser authorization, playback
> synchronization, listener statistics, show decisions, or physical speaker authority. It writes a
> machine-readable health record for other components to read. It never calls them.

> **Status.** This is a hardening rewrite verified by an automated suite against temporary local
> fixtures. It has **not** been installed on a live FPP, connected to a real destination host,
> rehearsed against a show, or run in production. See [Verification status](#verification-status).

## What it does

```
/home/fpp/media/music                    source (read only)
        |
        |  hash every asset -> versioned manifest (sha256 + size)
        v
/home/fpp/media/lof-audio-supply/
    staging/<generation>/                marked .incomplete until it verifies
    generations/<generation>/            immutable once promoted
    manifests/<generation>.json          the proof
    current    -> generations/<gen>      what a consumer reads
    previous   -> generations/<gen>      last known good
    quarantine/<stamp>/                  assets that vanished upstream
    history.json                         bounded activation log
    health.json                          machine-readable status
```

A publish:

1. hashes the approved source assets into a versioned manifest;
2. copies them into a unique staging directory marked `.incomplete`;
3. verifies the staged copy against the manifest — every asset, right size, right digest, **and no
   extra files**;
4. promotes the directory, re-verifies it, and flips `current` with a single atomic `rename()`;
5. copies anything that vanished upstream into a timestamped quarantine generation;
6. keeps the outgoing generation as `previous` and prunes beyond the retained window.

**A failure at any step leaves `current` exactly where it was.** That invariant is asserted
individually for interrupted transfer, digest mismatch, truncation, an unexpected extra file,
missing source, unreadable source, unwritable publication root, insufficient disk space, a lost
connection, a killed process, and a concurrent run.

## Security properties

| Property | How it is enforced | Where it is proved |
|---|---|---|
| No shell, ever | `proc_open()` is called with an argv **array**, which PHP hands to `execvp`. There is no command string to quote for. | `InjectionTest`, `Fpp10Test::testNoShippedPhpFileCallsAShell` |
| No caller-supplied command fragments | One runner, an absolute-path binary allowlist, and a closed list of remote commands (`probe` → `true`). | `InjectionTest`, `TransportArgvTest` |
| Explicit allowlists | Source, publication, key, and destination roots; service users; hosts by exact match, CIDR, or dotted suffix. Declared in `policy.json`, which the web form **cannot** edit. | `ValidatorTest` |
| No traversal or symlink escape | Character screening, lexical `..` resolution, then `realpath()` containment against the approved root. | `SafePathTest` |
| No options-as-values | Any value starting with `-` is refused, and positional operands sit behind an `--` separator. | `SafePathTest`, `TransportArgvTest` |
| No silent integer coercion | `"22; rm -rf /"` is a validation error, not `22`. | `ValidatorTest`, `InjectionTest` |
| Never deletes | `--delete` appears in no argv, and there is no code path that can add it. Deletion happens only via `prune-quarantine --confirm`. | `TransportArgvTest`, `PublishTest` |
| Authenticated mutations | Every mutating action requires an authenticated FPP administrator, a same-origin request, and a CSRF token bound to that single action. | `AuthCsrfTest`, `ConfigPageTest` |
| No secret leakage | Key contents are never read. The key path is absent from the health record and the public settings view. Child output is redacted on capture. | `RedactionTest`, `ConfigPageTest` |
| Safe concurrency | `flock(LOCK_EX\|LOCK_NB)`; the kernel releases it if the holder is killed. | `ConcurrencyTest` (real forked processes, including SIGKILL) |

### Fail-closed authentication

If FPP's login is disabled there is no identity for a plugin page to check, so **mutations are
refused** and the page stays read-only. This is a deliberate behaviour change: the previous version
executed shell commands for any unauthenticated caller who could reach the page. Enable
*Status/Control → FPP Settings → Require Login* before configuring this plugin.

## Installation

### Via the FPP plugin manager

1. FPP web UI → **Content Setup → Plugins**
2. Paste into the search box:
   `https://raw.githubusercontent.com/ljhaydn/fpp-lof-audio-sync/main/pluginInfo.json`
3. Install.

The install hook creates `/home/fpp/media/lof-audio-supply`, writes validated default settings,
installs a static systemd service and timer, creates an empty pinned `known_hosts`, and **stops and
disables any lsyncd left over from an earlier version**. It does not delete the old
`/etc/lsyncd/lsyncd-lof.conf.lua`; that is left for you to review.

### Manual

```bash
cd /home/fpp/media/plugins
git clone https://github.com/ljhaydn/fpp-lof-audio-sync.git
cd fpp-lof-audio-sync
sudo ./scripts/fpp_install.sh
```

## Configuration

Settings live in `settings.json` (written by the plugin, never committed). The **policy** — approved
roots, service users, host allowlist, permitted binaries — lives in `policy.json`, which is read by
the plugin and deliberately not writable from the web form. Copy `policy.example.json` and keep it
root-owned.

| Setting | Meaning | Constraint |
|---|---|---|
| `enabled` | Publish on the timer | boolean |
| `source_path` | FPP media directory to publish | inside an approved source root |
| `publication_root` | Where generations live | inside an approved publication root, must not overlap the source |
| `sync_interval_seconds` | Minimum gap between publishes | 5 – 86400 |
| `retain_generations` | Rollback depth kept on disk | 1 – 20 |
| `quarantine_retention_days` | Retention window for quarantine | 1 – 365 |
| `distribution_enabled` | Arm the remote push | boolean; requires host and key |
| `destination_host` | Remote host | IP or hostname, must match the allowlist |
| `destination_port` | Remote SSH port | 1 – 65535 |
| `destination_path` | Remote directory | inside an approved destination root |
| `service_user` | Remote account | from the fixed service-user list |
| `ssh_key_path` | Private key | inside an approved key root, mode `0600` |

## Command line

```bash
cd /home/fpp/media/plugins/fpp-lof-audio-sync
./scripts/lof_audio_supply.sh status --json       # machine-readable health record
./scripts/lof_audio_supply.sh publish [--force]   # one publish cycle
./scripts/lof_audio_supply.sh verify              # re-prove the active generation
./scripts/lof_audio_supply.sh rollback            # return to the last known good
./scripts/lof_audio_supply.sh reclaim-staging     # clear staging from a killed run
./scripts/lof_audio_supply.sh prune-quarantine --retention-days=30 --confirm
./scripts/lof_audio_supply.sh show-argv --json    # print the exact rsync/ssh vectors
./scripts/lof_audio_supply.sh self-check          # policy and binary sanity check
```

Exit codes: `0` ok, `1` failed, `2` validation or policy refusal, `3` another run holds the lock,
`4` integrity failure, `64` bad usage.

The web page never calls this script. It calls the same PHP library in-process, so there is one set
of rules to audit and no `sudo` surface reachable from a browser.

## Health record

`<publication root>/health.json`, mode `0644`, rewritten after every run. Intended for later
consumption by `lof-core`; this plugin does not call or modify `lof-core`.

```json
{
  "health_version": 1,
  "component": "fpp-lof-audio-sync",
  "role": "media-supply",
  "state": "ok",
  "current": { "generation": "...", "asset_count": 12, "manifest_sha256": "..." },
  "previous_generation": "...",
  "quarantine": { "generation_count": 1, "deletion_policy": "retention-only; synchronisation never deletes" },
  "owns": {
    "media_supply": true,
    "browser_authorization": false,
    "playback_synchronization": false,
    "listener_statistics": false,
    "show_decisions": false,
    "physical_speaker_authority": false
  }
}
```

## Viewer rendition lane

The public listening player must never receive a show master. This lane turns the verified current
media-supply generation into one purpose-made, metadata-free rendition per master, under a separate
root that holds nothing else. It implements the publisher side of the frozen phone-audio
cross-repository contract V1 (`contract_sha256 a21c74cc…3b13`), vendored byte-for-byte under
`tests/contract-v1/`; its three publication schemas are pinned by digest in `contract/v1/`.

```
<viewer_root>/                              read-only for lof-core; outside every web root
    health.json                             commit point, written last; only state "ok" is servable
    manifests/<generation>.json             rendition ids, sizes, sha256 - nothing about a source
    generations/<generation>/<rid>.m4a      renditions only
    staging/  quarantine/                   in-progress and interrupted work; never referenced
<private_root>/                             outside every web root and outside <viewer_root>
    source-maps/<generation>.json           rid -> source_rel, source and rendition digests
    viewer-ledger.json  quarantine/
```

- **One profile.** `lof-viewer-aac-lc-m4a-v1`: AAC-LC in M4A, 2 ch, 44.1 kHz, 128 kbit/s. The one
  encoder invocation (`ViewerProfile::encoderArgv`) runs through the existing allowlisted argv
  runner: `file:`-only input with a demuxer whitelist (a master that is really a playlist is
  refused, not followed), first audio stream only, all global/stream metadata and chapters
  dropped, bit-exact flags, one thread. Two runs over the same master give the same bytes.
- **Metadata stripped and proved.** ffmpeg's MP4 muxer always writes an empty iTunes skeleton; it is
  retyped in place to zeroed padding, and anything more than that skeleton fails the run. A
  pure-PHP box walker then refuses any box outside the minimal AAC track tree, any non-zero padding,
  any external reference, and anything that is not AAC-LC 2 ch 44.1 kHz. Rendition bytes must not
  contain the source path or name, and must differ from every master.
- **Opaque ids.** `rid = HMAC-SHA256(rid_key, canonical(["lof-rid",1,generation,profile,source_rel,source_sha256]))[0:32]`:
  128 bits, keyed, new in every generation. The rid key never leaves the root-owned key file; the
  contract's fixture key is refused.
- **Commit order.** Staging (marked `.incomplete`) is verified against the manifest, promoted by one
  rename, then the manifest, then the private source map are written; the whole publication is
  re-read from disk and run through the contract's consumer pipeline; only then is `health.json`
  written. A failure leaves `health.json` alone - unless what it names no longer verifies, in which
  case it is marked `failed` so it cannot be served. Failures are reported in the media-supply
  `health.json` under `viewer_rendition`, by code only.
- **Recovery, quarantine, rollback.** Under the media-supply lock, every run first moves anything
  `health.json` has never named (staging leftovers, uncommitted generations, stray temp files) into
  quarantine; source maps go to the private quarantine. Nothing is deleted except generations older
  than the retained window, and never the current or previous one. `viewer-rollback` re-points
  `health.json` at a previous generation only after it passes the full pipeline.

Disabled by default. Enabling it is a root-side policy edit: add a `viewer_rendition` block (see
`policy.example.json`), add the encoder to `allowed_binaries`, and create the rid key
(`install -m 0600 /dev/null <key>; head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > <key>`).
The timer's `publish` then runs the lane after each good supply run.

```bash
./scripts/lof_audio_supply.sh viewer-publish --json    # one lane cycle on its own
./scripts/lof_audio_supply.sh viewer-verify --json     # prove the published generation (exit 4 if not)
./scripts/lof_audio_supply.sh viewer-rollback --json   # back to the verified previous generation
./scripts/lof_audio_supply.sh viewer-recover --json    # quarantine interrupted state now
```

The lane never serves bytes, issues grants, or knows about listeners; that is `lof-core`'s side of
the contract.

### Delivering verified masters locally (`supply-deliver`)

`supply-deliver` is an operator-only command. The timer never runs it. It is configured only in the root-owned `supply_delivery` policy block, which is disarmed by default and has no destination. The legacy masters-only `distribute` and its `destination_path` are unchanged.

It delivers the verified current supply generation as a complete supply root that a server-side viewer lane can read. The steps run in this order:

1. Lock the source, then the destination.
2. Re-verify the source generation and its manifest.
3. Copy the manifest-listed masters into `staging/<gen>/`, one per transfer, re-proving real containment before each copy.
4. Copy the byte-identical manifest into `staging/<gen>.manifest/`.
5. Read back the exact file set, sizes and digests, and compare the manifest bytes.
6. Promote the staging directory with one rename, then commit the manifest with one rename.
7. Re-verify the committed generation.
8. Run the existing `Generations::activate()`.

Destination generations are immutable:
- An existing generation id, whether named by `current` or `previous` or historical, is never written into.
- If it verifies exactly, it is reused with zero transfers.
- Otherwise the run refuses with `supply_deliver.generation_conflict`, after zero transfers.
- Stale staging is moved to quarantine, never deleted.
- A damaged live generation is **not** repaired automatically; that needs an operator decision.

`activate()` writes `previous` before it atomically swaps `current`, so the pair is not atomic. On any failure both links are read back and reported as observed. Possible codes include `supply_deliver.previous_moved_current_unchanged` and `supply_deliver.pointer_moved_unverified`.

Nothing at the destination is deleted, and no pointer is restored automatically. Only `mode: "local"` runs; `remote` is refused before any lock or transfer.

### Delivering the viewer publication (`viewer-distribute`)

A separate, operator-only command. It is never run by the timer, and it does not reuse or repurpose the media-supply `distribute`, which ships masters. It is configured only in the root-owned `viewer_distribution` policy block. That block is disarmed by default and has no destination.

It ships the verified current viewer generation to two separately configured roots:
- `viewer_destination`
- `private_destination`

It never infers either root from `destination_path`. It follows the contract commit order:

1. renditions
2. manifest
3. private source map
4. read the destination back and run the consumer pipeline against it
5. `health.json` last

The file lists come from the verified manifest. There is no glob, directory listing or delete. A failure before `health.json` is sent leaves the destination's old pointer authoritative. The outcome never assumes this: the destination pointer is read before the run and read back after any failure. If it changed, the error is `viewer_distribute.pointer_moved_unverified`, and the destination's verdict at that moment is recorded. This covers a transport that copies `health.json` but reports failure, and a destination that drifts after the commit. The old pointer is not restored automatically. Delivered generations are never removed, so current and previous stay recoverable. A destination rollback is a local `viewer-rollback` followed by `viewer-distribute`.

**Only `mode: "local"` works today.** Its destinations are paths on this host, delivered with the existing `LocalTransport` and read back directly. **`mode: "remote"` is refused before any transfer.** The existing SSH/rsync transport can write, with checksummed per-file temp-then-rename, but it has no allowlisted remote read. So it cannot prove the destination before moving the health pointer, and a delivery that skipped that proof would not be health-last in any meaningful sense.

```bash
./scripts/lof_audio_supply.sh viewer-distribute --json
```


## Tests

No composer, no PHPUnit — FPP images ship neither. The suite is pure PHP and runs anywhere PHP 8.1+
does, including on an FPP box.

```bash
php tests/run.php              # everything
php tests/run.php Injection    # one suite
```

Coverage: path safety and symlink escape, configuration validation, a 32-payload injection corpus
against every field, the exact rsync/ssh argument vectors, manifest creation/verification/tamper
detection, the publication lifecycle, every named failure mode, concurrency (real forked processes,
including `SIGKILL` recovery), secret redaction, the settings store and its v1 migration, the
`config.php` request path, FPP 10 paths and runtime constraints, and the viewer rendition lane:
the vendored contract's digest, all 58 publication document vectors (with parity against the
contract's own model), byte-for-byte reproduction of the contract's fixture publication through a
stub encoder, and - where ffmpeg is installed - real deterministic, stripped encodes of tagged,
cover-art and chaptered synthetic masters, end to end through the CLI.

Everything runs against temporary directories shaped like the FPP 10 estate. Nothing touches a real
`/home/fpp`, a real key, or a network.

## Upgrading from the lsyncd version

An existing `settings.json` is migrated in place. Remote-facing values are carried over as
*candidates* and still have to pass validation, and both `enabled` and `distribution_enabled` are
forced **off**, so an upgrade never silently resumes pushing under rules the old file was never
checked against. Re-enable them deliberately.

What is gone: lsyncd, the generated `/etc/lsyncd/lsyncd-lof.conf.lua`, `rsync --delete`, the two
divergent copies of `start_sync.sh`, and every interpolated shell command.

## Verification status

**Verified** by the automated suite against local temporary fixtures: validation and refusal
behaviour, the injection corpus, argv construction, manifest integrity, staged publication, atomic
activation, quarantine, rollback, the failure modes listed above, concurrency, redaction, and the
`config.php` request path.

**Not verified.** None of the following has been exercised, and no claim is made about them:

- installation on a live FPP 8/9/10 box, or the FPP plugin manager flow;
- FPP's actual authentication integration — `Auth` reads the HTTP-auth and session markers FPP is
  expected to set, and fails closed when it finds none, but that has not been confirmed against a
  running FPP;
- any SSH or rsync execution against a real destination — the distribution leg's argv is asserted,
  never run;
- systemd unit installation, timer behaviour, or the `systemd-analyze verify` path;
- performance or disk behaviour with a real show's media at real sizes;
- any WordPress, Remote Falcon, hardware, speaker, or network integration;
- the viewer lane on FPP 10's own ffmpeg build: determinism is proved across runs of one local
  ffmpeg, and bit-exact output across ffmpeg versions is not claimed; playback of the rendition on
  real Safari/iOS devices, and `lof-core` consuming a publication this lane wrote.

## Requirements

- FPP 8.0 or later (paths and defaults target FPP 10)
- PHP 8.1+ with `json`, `hash`, `pcre` (stock on FPP)
- `rsync` — only for the optional distribution leg
- No composer, no PHPUnit, no lsyncd

## Support

- Issues: <https://github.com/ljhaydn/fpp-lof-audio-sync/issues>
- Website: <https://lightsonfalcon.com>

## License

MIT
