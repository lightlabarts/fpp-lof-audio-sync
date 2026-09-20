<?php

declare(strict_types=1);

namespace LofAudioSupply\Security;

use LofAudioSupply\AuthException;

/**
 * Per-session, per-action CSRF tokens.
 *
 * The session holds one random secret; a token is the HMAC of the action name
 * under that secret. Binding the action in means a token minted for the
 * read-only "test connection" button cannot be replayed to drive "save
 * settings", and no server-side token table has to be tracked or expired.
 *
 * There is no non-session fallback. If a session cannot be established the
 * mutation is refused, because a double-submit cookie on a host that is not
 * serving HTTPS is not a defence worth pretending to have.
 */
final class Csrf
{
    private const SESSION_KEY = 'lof_audio_supply_csrf_secret';
    private const SECRET_BYTES = 32;

    /** Overridable so tests and CLI callers do not touch $_SESSION. */
    private ?string $secretOverride;

    public function __construct(?string $secretOverride = null)
    {
        $this->secretOverride = $secretOverride;
    }

    public function available(): bool
    {
        if ($this->secretOverride !== null) {
            return true;
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }

    private function secret(): string
    {
        if ($this->secretOverride !== null) {
            return $this->secretOverride;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new AuthException('csrf.no_session', 'No session is available to anchor a CSRF token.');
        }
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])
            || strlen($_SESSION[self::SESSION_KEY]) !== self::SECRET_BYTES * 2) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::SECRET_BYTES));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function token(string $action): string
    {
        if ($action === '' || preg_match('/^[a-z0-9_]{1,40}$/', $action) !== 1) {
            throw new AuthException('csrf.bad_action', 'Action name is not a valid CSRF binding.');
        }

        return hash_hmac('sha256', $action, $this->secret());
    }

    public function verify(string $action, ?string $presented): bool
    {
        if (!is_string($presented) || $presented === '') {
            return false;
        }
        try {
            $expected = $this->token($action);
        } catch (AuthException $e) {
            return false;
        }

        // Constant-time: a timing oracle on a 64-char hex token is worth closing.
        return hash_equals($expected, $presented);
    }

    public function assert(string $action, ?string $presented): void
    {
        if (!$this->verify($action, $presented)) {
            throw new AuthException('csrf.invalid', 'CSRF token is missing or invalid.', ['action' => $action]);
        }
    }

    /** Invalidate every outstanding token for this session. */
    public function rotate(): void
    {
        if ($this->secretOverride !== null) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::SECRET_BYTES));
        }
    }
}
