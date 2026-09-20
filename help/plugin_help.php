<h3>LOF Audio Supply &mdash; Help</h3>

<p>
    This plugin publishes audio from the FPP media estate as <strong>verified, versioned
    generations</strong>. It hashes every asset, proves the published copy matches, and only then
    switches the active generation in a single atomic rename.
</p>

<p><strong>Scope.</strong> Media supply only. This plugin does not own browser authorization,
playback synchronization, listener statistics, show decisions, or physical speaker authority.
It writes a machine-readable health record for other components to read; it never calls them.</p>

<h4>How a publish works</h4>
<ol>
    <li>Assets under the approved source root are hashed into a versioned manifest &mdash; SHA-256 and byte size for each one.</li>
    <li>They are copied into a unique staging directory that carries an <code>.incomplete</code> marker until it verifies.</li>
    <li>The staged copy is checked against the manifest: every asset present, right size, right digest, and no extra files.</li>
    <li>The verified directory is promoted to an immutable generation, re-verified, and only then does <code>current</code> flip.</li>
    <li>Anything that disappeared upstream is copied into a timestamped quarantine generation.</li>
    <li>The outgoing generation is kept as <code>previous</code>, so a rollback is one rename away.</li>
</ol>

<p>
    A failure at any step &mdash; interrupted copy, digest mismatch, missing source, full disk,
    permission error, lost connection, a killed process, or a second run starting concurrently &mdash;
    leaves <code>current</code> exactly where it was.
</p>

<h4>Deletion</h4>
<p>
    <strong>A publish never deletes anything.</strong> An asset that vanishes upstream is copied into
    quarantine and also stays in the generation it came from. Actual deletion only happens when you
    run the retention command with an explicit confirmation:
</p>
<pre>sudo -u fpp /home/fpp/media/plugins/fpp-lof-audio-sync/scripts/lof_audio_supply.sh \
    prune-quarantine --retention-days=30 --confirm</pre>

<h4>Setup</h4>
<ol>
    <li><strong>Enable FPP's login.</strong> Without an authenticated administrator this page is
        read-only &mdash; saving, publishing, and rollback are all disabled. This is deliberate.</li>
    <li>Open <strong>Content Setup &rarr; LOF Audio Supply</strong>.</li>
    <li>Check the source path and publication root. Both must sit inside the roots the policy approves;
        the page lists them under each field.</li>
    <li>Tick <em>Publish new generations on the timer</em> and save.</li>
    <li>Press <em>Publish now</em> for the first generation, then <em>Verify active generation</em>.</li>
</ol>

<h4>Optional: distributing to a web host</h4>
<p>
    Distribution is disarmed by default and has to be armed explicitly. It requires a destination host
    that is on the policy allowlist and an SSH key stored under the approved config estate with mode
    <code>0600</code>. The key's <em>contents</em> are never read, displayed, or logged by this plugin.
</p>
<p>
    Host keys are pinned: <code>StrictHostKeyChecking</code> is on and
    <code>/home/fpp/media/config/lof-audio-known_hosts</code> is the only file consulted, so you must add
    the destination's host key there yourself before the first connection will succeed. Distribution
    never passes <code>--delete</code>, so it cannot remove anything on the far side.
</p>

<h4>Policy</h4>
<p>
    The approved roots, service users, host allowlist, and permitted binaries live in
    <code>policy.json</code> next to the plugin (see <code>policy.example.json</code>). That file is
    <strong>not editable from this page</strong> &mdash; keep it root-owned. The web form can only choose
    among values the policy already approves.
</p>

<h4>Command line</h4>
<pre>cd /home/fpp/media/plugins/fpp-lof-audio-sync
./scripts/lof_audio_supply.sh status --json      # machine-readable health
./scripts/lof_audio_supply.sh publish --force    # publish now, ignoring the interval
./scripts/lof_audio_supply.sh verify             # re-prove the active generation
./scripts/lof_audio_supply.sh rollback           # return to the last known good
./scripts/lof_audio_supply.sh self-check         # policy and binary sanity check</pre>

<h4>Troubleshooting</h4>
<p><strong>"Read-only" banner:</strong> FPP's login is off, or you are not signed in. Enable it and sign in.</p>
<p><strong>A value is refused on save:</strong> the message includes a code such as
    <code>path.not_approved</code> or <code>host.not_approved</code>. The value is outside what
    <code>policy.json</code> approves &mdash; fix the value, or widen the policy at the console.</p>
<p><strong>Publish reports <code>lock.busy</code>:</strong> the timer is mid-publish. Wait and retry.</p>
<p><strong>Publish reports <code>publish.staging_unverified</code>:</strong> the staged copy did not match
    its manifest. The active generation was left untouched. Check the disk and the source tree.</p>
<p><strong>Nothing gets published:</strong> only approved audio extensions are collected. Run
    <code>publish --json</code> and read the <code>skipped</code> list.</p>

<h4>What is no longer here</h4>
<p>
    Earlier versions of this plugin ran <code>lsyncd</code> with <code>rsync --delete</code> and built
    shell commands by string interpolation from web form values. Both are gone: there is no lsyncd, no
    generated Lua, no <code>--delete</code>, and no command string anywhere in the plugin.
</p>

<h4>Support</h4>
<p>Issues and questions:
    <a href="https://github.com/ljhaydn/fpp-lof-audio-sync/issues" target="_blank" rel="noopener">GitHub Issues</a>
</p>
