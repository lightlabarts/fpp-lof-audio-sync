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
`config.php` request path, and FPP 10 paths and runtime constraints.

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
- any WordPress, Remote Falcon, hardware, speaker, or network integration.

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
