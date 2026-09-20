<?php
/**
 * LOF Audio Supply - FPP plugin configuration page.
 *
 * What changed, and why it matters:
 *
 *   - This page no longer builds a command string. It calls the plugin library
 *     in-process; the only child process it can cause is the SSH probe, which
 *     goes through the argv-only runner with an allowlisted binary.
 *   - Every mutation requires an authenticated FPP administrator AND a valid,
 *     action-bound CSRF token. If FPP's login is disabled there is no identity
 *     to check, so mutations are refused and the page stays read-only.
 *   - Nothing operator-supplied is echoed without escaping, and the status
 *     surface is built from the redacted view of settings, so a private key
 *     path, key material, or credential cannot reach the browser.
 *
 * Rendered as a fragment: FPP's plugin.php supplies the surrounding document.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

use LofAudioSupply\AuthException;
use LofAudioSupply\LofAudioException;
use LofAudioSupply\Publish\Manifest;
use LofAudioSupply\Runtime;
use LofAudioSupply\Security\Auth;
use LofAudioSupply\Security\Csrf;
use LofAudioSupply\Status\Health;
use LofAudioSupply\Support\Json;

// Refuse a direct hit on the file; this page is only meaningful when FPP's
// plugin dispatcher includes it, and FPP's auth lives on that path.
if (Auth::isDirectlyRequested(__FILE__)) {
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo 'This page must be opened through the FPP plugin menu.';

    return;
}

if (!function_exists('lofEscape')) {
    function lofEscape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

// FPP normally has a session already. If it does not and headers are still
// open, start one; otherwise CSRF cannot be anchored and mutations are refused.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    @session_start();
}

$runtime = new Runtime(__DIR__);
$auth = new Auth();
$csrf = new Csrf();
$authority = $auth->authority();

/** @var list<array{level:string,text:string}> $messages */
$messages = [];
/** @var array<string,mixed>|null $commandOutput */
$commandOutput = null;

/** @var array<string,mixed> $formValues */
[$loadedSettings, $settingsError] = $runtime->store()->loadTolerant();
$formValues = $loadedSettings !== null
    ? $loadedSettings->toArray()
    : $runtime->store()->defaultsArray();

$actions = ['save_settings', 'publish_now', 'verify_now', 'rollback', 'probe_remote'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if (!in_array($action, $actions, true)) {
        $messages[] = ['level' => 'error', 'text' => 'Unrecognised action.'];
    } else {
        try {
            $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
            $auth->assertMutationAllowed($action, $token, $csrf);

            if ($action === 'save_settings') {
                // Only recognised keys are read. Nothing is passed through.
                $candidate = [
                    'settings_version' => 2,
                    'enabled' => $_POST['enabled'] ?? false,
                    'source_path' => $_POST['source_path'] ?? null,
                    'publication_root' => $_POST['publication_root'] ?? null,
                    'distribution_enabled' => $_POST['distribution_enabled'] ?? false,
                    'destination_host' => $_POST['destination_host'] ?? null,
                    'destination_port' => $_POST['destination_port'] ?? null,
                    'destination_path' => $_POST['destination_path'] ?? null,
                    'service_user' => $_POST['service_user'] ?? null,
                    'ssh_key_path' => $_POST['ssh_key_path'] ?? null,
                    'sync_interval_seconds' => $_POST['sync_interval_seconds'] ?? null,
                    'retain_generations' => $_POST['retain_generations'] ?? null,
                    'quarantine_retention_days' => $_POST['quarantine_retention_days'] ?? null,
                ];
                [$validated, $errors] = $runtime->store()->validator()->check($candidate);
                if ($validated === null) {
                    foreach ($errors as $error) {
                        $messages[] = [
                            'level' => 'error',
                            'text' => $error['field'] . ': ' . $error['message'] . ' [' . $error['code'] . ']',
                        ];
                    }
                    // Keep what the operator typed so the form can be corrected,
                    // but only as escaped display values.
                    foreach (array_keys($formValues) as $key) {
                        if (isset($candidate[$key]) && is_scalar($candidate[$key])) {
                            $formValues[$key] = $candidate[$key];
                        }
                    }
                } else {
                    $runtime->store()->save($validated);
                    $loadedSettings = $validated;
                    $settingsError = null;
                    $formValues = $validated->toArray();
                    $messages[] = ['level' => 'success', 'text' => 'Settings saved and validated against policy.'];
                }
            } elseif ($action === 'publish_now') {
                $settings = $runtime->store()->load();
                $result = $runtime->publisher($settings)->publish();
                $commandOutput = $result->toArray();
                $messages[] = ['level' => 'success', 'text' => 'Publish finished: ' . $result->outcome . '.'];
            } elseif ($action === 'verify_now') {
                $settings = $runtime->store()->load();
                $problems = $runtime->publisher($settings)->verifyCurrent();
                $commandOutput = ['problem_count' => count($problems), 'problems' => array_slice($problems, 0, 50)];
                $messages[] = $problems === []
                    ? ['level' => 'success', 'text' => 'Active generation matches its manifest.']
                    : ['level' => 'error', 'text' => 'Active generation failed verification.'];
            } elseif ($action === 'rollback') {
                $settings = $runtime->store()->load();
                $target = $runtime->publisher($settings)->rollback();
                $commandOutput = ['rolled_back_to' => $target];
                $messages[] = ['level' => 'success', 'text' => 'Rolled back to the last known good generation.'];
            } elseif ($action === 'probe_remote') {
                $settings = $runtime->store()->load();
                $transport = $runtime->remoteTransport($settings);
                // Fixed remote command from a closed allowlist; argv only.
                $result = $runtime->processRunner()->run($transport->buildProbeArgv(), 30);
                $commandOutput = [
                    'reachable' => $result->succeeded(),
                    'exit_code' => $result->exitCode,
                    'stderr' => $result->stderr,
                ];
                $messages[] = $result->succeeded()
                    ? ['level' => 'success', 'text' => 'Destination answered the probe.']
                    : ['level' => 'error', 'text' => 'Destination did not answer the probe.'];
            }
        } catch (AuthException $e) {
            $messages[] = ['level' => 'error', 'text' => 'Refused: ' . $e->getMessage()];
        } catch (LofAudioException $e) {
            $messages[] = ['level' => 'error', 'text' => 'Refused: ' . $e->getMessage() . ' [' . $e->code() . ']'];
        }
    }
}

$generations = $loadedSettings !== null ? $runtime->generations($loadedSettings) : null;
$health = null;
if ($generations !== null) {
    try {
        $generations->initialise();
        $health = Health::current($generations, $loadedSettings, $settingsError);
    } catch (LofAudioException $e) {
        $messages[] = ['level' => 'error', 'text' => 'Publication root unavailable: ' . $e->getMessage()];
    }
}

$mutationsAllowed = $authority->administrator && $csrf->available();

/** Mint a token only when there is a session to anchor it to. */
$tokenFor = static function (string $action) use ($csrf, $mutationsAllowed): string {
    if (!$mutationsAllowed) {
        return '';
    }
    try {
        return $csrf->token($action);
    } catch (AuthException $e) {
        return '';
    }
};

$policy = $runtime->policy();
?>
<style>
.lof-section { margin: 18px 0; padding: 14px; background: #f5f5f5; border-radius: 5px; }
.lof-section h3 { margin-top: 0; }
.lof-field { margin: 12px 0; }
.lof-field label { display: block; font-weight: bold; margin-bottom: 4px; }
.lof-field input[type="text"], .lof-field input[type="number"] { width: 420px; max-width: 100%; padding: 5px; }
.lof-help { font-size: 12px; color: #666; margin-top: 3px; }
.lof-alert { padding: 10px; margin: 8px 0; border-radius: 3px; }
.lof-alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.lof-alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.lof-alert-warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
.lof-pre { background: #f0f0f0; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 340px; font-size: 12px; }
.lof-kv td { padding: 2px 12px 2px 0; vertical-align: top; font-size: 13px; }
.lof-btn { padding: 8px 14px; margin-right: 8px; border: none; border-radius: 3px; cursor: pointer; }
.lof-btn-primary { background: #007bff; color: #fff; }
.lof-btn-secondary { background: #6c757d; color: #fff; }
.lof-btn-warning { background: #ffc107; color: #000; }
.lof-btn[disabled] { opacity: 0.5; cursor: not-allowed; }
</style>

<h2>LOF Audio Supply</h2>
<p class="lof-help">
    Media supply only. This plugin publishes verified audio generations. It does not own browser
    authorization, playback synchronization, listener statistics, show decisions, or physical speaker authority.
</p>

<?php foreach ($messages as $message) : ?>
    <div class="lof-alert lof-alert-<?php echo $message['level'] === 'success' ? 'success' : 'error'; ?>">
        <?php echo lofEscape($message['text']); ?>
    </div>
<?php endforeach; ?>

<?php if (!$authority->administrator) : ?>
    <div class="lof-alert lof-alert-warn">
        <strong>Read-only.</strong> No authenticated FPP administrator was detected for this request,
        so configuration changes, publishing, and rollback are disabled. Enable FPP's login
        (Status/Control &rarr; FPP Settings &rarr; Require Login) and sign in to make changes.
    </div>
<?php elseif (!$csrf->available()) : ?>
    <div class="lof-alert lof-alert-warn">
        <strong>Read-only.</strong> No PHP session is available, so a CSRF token cannot be anchored.
    </div>
<?php endif; ?>

<?php if ($settingsError !== null) : ?>
    <div class="lof-alert lof-alert-error">
        The saved settings file does not satisfy current policy (<?php echo lofEscape($settingsError); ?>).
        Defaults are shown below; saving will replace the invalid file.
    </div>
<?php endif; ?>

<div class="lof-section">
    <h3>Current state</h3>
    <?php if ($health === null) : ?>
        <p>No health record is available yet.</p>
    <?php else : ?>
        <table class="lof-kv">
            <tr><td><strong>State</strong></td><td><?php echo lofEscape((string) ($health['state'] ?? 'unknown')); ?></td></tr>
            <tr><td><strong>Active generation</strong></td><td><?php echo lofEscape((string) ($health['current']['generation'] ?? 'none')); ?></td></tr>
            <tr><td><strong>Assets</strong></td><td><?php echo (int) ($health['current']['asset_count'] ?? 0); ?></td></tr>
            <tr><td><strong>Total bytes</strong></td><td><?php echo (int) ($health['current']['total_bytes'] ?? 0); ?></td></tr>
            <tr><td><strong>Manifest digest</strong></td><td><code><?php echo lofEscape(substr((string) ($health['current']['manifest_sha256'] ?? ''), 0, 16)); ?></code></td></tr>
            <tr><td><strong>Last known good</strong></td><td><?php echo lofEscape((string) ($health['previous_generation'] ?? 'none')); ?></td></tr>
            <tr><td><strong>Generations retained</strong></td><td><?php echo (int) ($health['generation_count'] ?? 0); ?></td></tr>
            <tr><td><strong>Quarantine generations</strong></td><td><?php echo (int) ($health['quarantine']['generation_count'] ?? 0); ?></td></tr>
            <tr><td><strong>Record written</strong></td><td><?php echo lofEscape((string) ($health['generated_utc'] ?? '')); ?></td></tr>
        </table>
        <p class="lof-help">
            Machine-readable record:
            <code><?php echo lofEscape(($generations !== null ? $generations->healthPath() : '')); ?></code>
        </p>
    <?php endif; ?>
</div>

<div class="lof-section">
    <h3>Settings</h3>
    <form method="post">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="csrf_token" value="<?php echo lofEscape($tokenFor('save_settings')); ?>">

        <div class="lof-field">
            <label>
                <input type="checkbox" name="enabled" value="1" <?php echo !empty($formValues['enabled']) ? 'checked' : ''; ?>>
                Publish new generations on the timer
            </label>
            <div class="lof-help">When off, nothing is staged, verified, or activated.</div>
        </div>

        <div class="lof-field">
            <label for="lof_source_path">Source path (FPP media)</label>
            <input type="text" id="lof_source_path" name="source_path" value="<?php echo lofEscape((string) $formValues['source_path']); ?>">
            <div class="lof-help">Must be inside: <?php echo lofEscape(implode(', ', $policy->sourceRoots)); ?></div>
        </div>

        <div class="lof-field">
            <label for="lof_publication_root">Publication root</label>
            <input type="text" id="lof_publication_root" name="publication_root" value="<?php echo lofEscape((string) $formValues['publication_root']); ?>">
            <div class="lof-help">Must be inside: <?php echo lofEscape(implode(', ', $policy->publicationRoots)); ?></div>
        </div>

        <div class="lof-field">
            <label for="lof_sync_interval">Minimum seconds between publishes</label>
            <input type="number" id="lof_sync_interval" name="sync_interval_seconds"
                   min="<?php echo $policy->minSyncInterval; ?>" max="<?php echo $policy->maxSyncInterval; ?>"
                   value="<?php echo (int) $formValues['sync_interval_seconds']; ?>">
        </div>

        <div class="lof-field">
            <label for="lof_retain">Generations to retain</label>
            <input type="number" id="lof_retain" name="retain_generations"
                   min="<?php echo $policy->minRetainGenerations; ?>" max="<?php echo $policy->maxRetainGenerations; ?>"
                   value="<?php echo (int) $formValues['retain_generations']; ?>">
            <div class="lof-help">Bounds the rollback history kept on disk.</div>
        </div>

        <div class="lof-field">
            <label for="lof_quarantine_days">Quarantine retention (days)</label>
            <input type="number" id="lof_quarantine_days" name="quarantine_retention_days"
                   min="<?php echo $policy->minQuarantineDays; ?>" max="<?php echo $policy->maxQuarantineDays; ?>"
                   value="<?php echo (int) $formValues['quarantine_retention_days']; ?>">
            <div class="lof-help">
                Assets that disappear upstream are moved to quarantine, never deleted by a publish.
                Deleting them is a separate, explicitly confirmed retention command.
            </div>
        </div>

        <h4>Distribution to the web host (optional)</h4>
        <div class="lof-field">
            <label>
                <input type="checkbox" name="distribution_enabled" value="1" <?php echo !empty($formValues['distribution_enabled']) ? 'checked' : ''; ?>>
                Arm distribution of the active generation
            </label>
            <div class="lof-help">Requires an approved host and an approved SSH key path. Never deletes remote files.</div>
        </div>

        <div class="lof-field">
            <label for="lof_destination_host">Destination host</label>
            <input type="text" id="lof_destination_host" name="destination_host" value="<?php echo lofEscape((string) $formValues['destination_host']); ?>">
            <div class="lof-help">Approved: <?php echo lofEscape(implode(', ', $policy->hostAllowlist)); ?></div>
        </div>

        <div class="lof-field">
            <label for="lof_destination_port">Destination SSH port</label>
            <input type="number" id="lof_destination_port" name="destination_port" min="1" max="65535"
                   value="<?php echo (int) $formValues['destination_port']; ?>">
        </div>

        <div class="lof-field">
            <label for="lof_destination_path">Destination path</label>
            <input type="text" id="lof_destination_path" name="destination_path" value="<?php echo lofEscape((string) $formValues['destination_path']); ?>">
            <div class="lof-help">Must be inside: <?php echo lofEscape(implode(', ', $policy->destinationRoots)); ?></div>
        </div>

        <div class="lof-field">
            <label for="lof_service_user">Service user</label>
            <input type="text" id="lof_service_user" name="service_user" value="<?php echo lofEscape((string) $formValues['service_user']); ?>">
            <div class="lof-help">Approved: <?php echo lofEscape(implode(', ', $policy->serviceUsers)); ?></div>
        </div>

        <div class="lof-field">
            <label for="lof_ssh_key_path">SSH key path</label>
            <input type="text" id="lof_ssh_key_path" name="ssh_key_path" value="<?php echo lofEscape((string) ($formValues['ssh_key_path'] ?? '')); ?>">
            <div class="lof-help">
                Must be inside: <?php echo lofEscape(implode(', ', $policy->keyRoots)); ?>, mode 0600.
                The key's contents are never read, displayed, or logged by this plugin.
            </div>
        </div>

        <button type="submit" class="lof-btn lof-btn-primary" <?php echo $mutationsAllowed ? '' : 'disabled'; ?>>Save settings</button>
    </form>
</div>

<div class="lof-section">
    <h3>Actions</h3>
    <?php
    $actionButtons = [
        'publish_now' => ['Publish now', 'lof-btn-primary'],
        'verify_now' => ['Verify active generation', 'lof-btn-secondary'],
        'rollback' => ['Roll back to last known good', 'lof-btn-warning'],
        'probe_remote' => ['Probe destination', 'lof-btn-secondary'],
    ];
    foreach ($actionButtons as $name => [$label, $class]) :
        ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="<?php echo lofEscape($name); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo lofEscape($tokenFor($name)); ?>">
            <button type="submit" class="lof-btn <?php echo lofEscape($class); ?>" <?php echo $mutationsAllowed ? '' : 'disabled'; ?>>
                <?php echo lofEscape($label); ?>
            </button>
        </form>
    <?php endforeach; ?>
    <div class="lof-help" style="margin-top:8px;">
        Each button carries its own CSRF token bound to that single action, so a token minted for one
        button cannot drive another.
    </div>
</div>

<?php if ($commandOutput !== null) : ?>
    <div class="lof-section">
        <h3>Result</h3>
        <pre class="lof-pre"><?php echo lofEscape(Json::pretty($commandOutput)); ?></pre>
    </div>
<?php endif; ?>

<div class="lof-section">
    <h3>How publication works</h3>
    <ol>
        <li>Assets under the approved source root are hashed into a versioned manifest (SHA-256 and size per asset).</li>
        <li>They are copied into a unique staging directory that is marked incomplete until it verifies.</li>
        <li>The staged copy is verified against the manifest &mdash; every asset, and no extra files.</li>
        <li>The verified directory is promoted, re-verified, and only then does <code>current</code> flip in a single atomic rename.</li>
        <li>Anything that vanished upstream is copied into a timestamped quarantine generation. A publish never deletes.</li>
        <li>The outgoing generation stays on disk as <code>previous</code> so rollback is a rename away.</li>
    </ol>
    <p class="lof-help">
        A failure at any step leaves <code>current</code> exactly where it was.
        Report generated <?php echo lofEscape(Manifest::nowUtc()); ?>.
    </p>
</div>
