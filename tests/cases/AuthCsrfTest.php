<?php

declare(strict_types=1);

namespace LofTest\Cases;

use LofAudioSupply\Security\Auth;
use LofAudioSupply\Security\Csrf;
use LofTest\TestCase;

/**
 * Authority and CSRF, exercised without a browser.
 *
 * Auth and Csrf both accept injected state precisely so this can be asserted
 * deterministically: no session cookie, no web server, no live FPP.
 */
final class AuthCsrfTest extends TestCase
{
    private const SECRET = 'a0b1c2d3e4f5a0b1c2d3e4f5a0b1c2d3e4f5a0b1c2d3e4f5a0b1c2d3e4f5a0b1';

    private function csrf(): Csrf
    {
        return new Csrf(self::SECRET);
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $session */
    private function auth(array $server = [], array $session = []): Auth
    {
        return new Auth($server + ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'fpp.local'], $session);
    }

    public function testNoIdentityMeansNoAuthority(): void
    {
        $authority = $this->auth()->authority();
        $this->assertFalse($authority->administrator);
        $this->assertSame('auth.no_identity', $authority->reasonCode);
        $this->assertSame('', $authority->identity);
    }

    public function testHttpAuthAndSessionIdentitiesAreBothAccepted(): void
    {
        $viaHttp = $this->auth(['PHP_AUTH_USER' => 'joe'])->authority();
        $this->assertTrue($viaHttp->administrator);
        $this->assertSame('joe', $viaHttp->identity);
        $this->assertSame('PHP_AUTH_USER', $viaHttp->source);

        $viaSession = $this->auth([], ['fppUser' => 'joe'])->authority();
        $this->assertTrue($viaSession->administrator);
        $this->assertSame('session:fppUser', $viaSession->source);

        // The identity is sanitised before it can reach a page or a log.
        $messy = $this->auth(['REMOTE_USER' => "joe<script>alert(1)</script>\n"])->authority();
        $this->assertStringNotContains('<', $messy->identity);
        $this->assertStringNotContains("\n", $messy->identity);
    }

    public function testTokensAreBoundToASingleAction(): void
    {
        $csrf = $this->csrf();
        $save = $csrf->token('save_settings');
        $rollback = $csrf->token('rollback');

        $this->assertNotSame($save, $rollback);
        $this->assertTrue($csrf->verify('save_settings', $save));
        $this->assertTrue($csrf->verify('rollback', $rollback));
        // A token minted for the read-only-looking button cannot drive another.
        $this->assertFalse($csrf->verify('rollback', $save), 'Cross-action replay must fail.');
        $this->assertFalse($csrf->verify('save_settings', $rollback));
    }

    public function testTokensAreRejectedWhenMissingMalformedOrForeign(): void
    {
        $csrf = $this->csrf();
        $this->assertFalse($csrf->verify('save_settings', null));
        $this->assertFalse($csrf->verify('save_settings', ''));
        $this->assertFalse($csrf->verify('save_settings', 'not-a-token'));
        $this->assertFalse($csrf->verify('save_settings', str_repeat('0', 64)));

        // A token from a different session secret is worthless here.
        $other = new Csrf(str_repeat('f', 64));
        $this->assertFalse($csrf->verify('save_settings', $other->token('save_settings')));
    }

    public function testActionNamesAreConstrained(): void
    {
        $csrf = $this->csrf();
        $this->assertRefused('csrf.bad_action', static fn () => $csrf->token(''));
        $this->assertRefused('csrf.bad_action', static fn () => $csrf->token('save settings'));
        $this->assertRefused('csrf.bad_action', static fn () => $csrf->token('../../etc'));
    }

    public function testMutationRequiresPostAuthorityOriginAndToken(): void
    {
        $csrf = $this->csrf();
        $token = $csrf->token('save_settings');

        // GET is never a mutation.
        $this->assertRefused(
            'auth.method_not_allowed',
            fn () => $this->auth(['REQUEST_METHOD' => 'GET', 'PHP_AUTH_USER' => 'joe'])->assertMutationAllowed('save_settings', $token, $csrf)
        );
        // No identity.
        $this->assertRefused(
            'auth.not_authenticated',
            fn () => $this->auth()->assertMutationAllowed('save_settings', $token, $csrf)
        );
        // Cross-origin POST.
        $this->assertRefused(
            'auth.cross_origin',
            fn () => $this->auth(['PHP_AUTH_USER' => 'joe', 'HTTP_ORIGIN' => 'https://attacker.invalid'])
                ->assertMutationAllowed('save_settings', $token, $csrf)
        );
        // Missing or wrong token.
        $this->assertRefused(
            'csrf.invalid',
            fn () => $this->auth(['PHP_AUTH_USER' => 'joe'])->assertMutationAllowed('save_settings', null, $csrf)
        );
        $this->assertRefused(
            'csrf.invalid',
            fn () => $this->auth(['PHP_AUTH_USER' => 'joe'])->assertMutationAllowed('save_settings', $csrf->token('rollback'), $csrf)
        );

        // All four conditions met.
        $authority = $this->auth(['PHP_AUTH_USER' => 'joe', 'HTTP_ORIGIN' => 'http://fpp.local'])
            ->assertMutationAllowed('save_settings', $token, $csrf);
        $this->assertTrue($authority->administrator);
    }

    public function testOriginCheckHandlesPortsAndOpaqueOrigins(): void
    {
        $this->assertTrue($this->auth(['HTTP_ORIGIN' => 'http://fpp.local'])->sameOriginOk());
        $this->assertTrue($this->auth(['HTTP_REFERER' => 'http://fpp.local/plugin.php?plugin=x'])->sameOriginOk());
        $this->assertTrue(
            (new Auth(['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'fpp.local:8080', 'HTTP_ORIGIN' => 'http://fpp.local:8080']))->sameOriginOk()
        );
        // A sandboxed iframe sends Origin: null.
        $this->assertFalse($this->auth(['HTTP_ORIGIN' => 'null'])->sameOriginOk());
        $this->assertFalse($this->auth(['HTTP_ORIGIN' => 'http://fpp.local.attacker.invalid'])->sameOriginOk());
        // Neither header present: the CSRF token is still required separately.
        $this->assertTrue($this->auth()->sameOriginOk());
    }

    public function testNoSessionMeansNoMutationRatherThanAWeakerCheck(): void
    {
        // With no injected secret and no active session, Csrf cannot mint or
        // verify anything, and the gate refuses rather than falling back to a
        // weaker check such as a double-submit cookie.
        $reopen = session_status() === PHP_SESSION_ACTIVE;
        if ($reopen) {
            session_write_close();
        }
        try {
            $csrf = new Csrf();
            $this->assertFalse($csrf->available());
            $this->assertFalse($csrf->verify('save_settings', 'anything'));
            $this->assertRefused(
                'csrf.no_session',
                static fn () => $csrf->token('save_settings')
            );
            $this->assertRefused(
                'auth.no_session',
                fn () => $this->auth(['PHP_AUTH_USER' => 'joe'])->assertMutationAllowed('save_settings', 'anything', $csrf)
            );
        } finally {
            if ($reopen) {
                @session_start();
            }
        }
    }

    public function testDirectFileAccessIsDetected(): void
    {
        $original = $_SERVER['SCRIPT_FILENAME'] ?? null;
        try {
            $_SERVER['SCRIPT_FILENAME'] = LOF_AUDIO_SUPPLY_ROOT . '/config.php';
            $this->assertTrue(Auth::isDirectlyRequested(LOF_AUDIO_SUPPLY_ROOT . '/config.php'));

            $_SERVER['SCRIPT_FILENAME'] = '/opt/fpp/www/plugin.php';
            $this->assertFalse(Auth::isDirectlyRequested(LOF_AUDIO_SUPPLY_ROOT . '/config.php'));
        } finally {
            if ($original === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $original;
            }
        }
    }

    public function testConfigPageRefusesEveryMutationWithoutAuthority(): void
    {
        // A direct exercise of the gate for each action the page exposes.
        $csrf = $this->csrf();
        foreach (['save_settings', 'publish_now', 'verify_now', 'rollback', 'probe_remote'] as $action) {
            $this->assertRefused(
                'auth.not_authenticated',
                fn () => $this->auth()->assertMutationAllowed($action, $csrf->token($action), $csrf),
                'Action must be gated: ' . $action
            );
        }
    }
}
