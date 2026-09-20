<?php

declare(strict_types=1);

namespace LofAudioSupply\Security;

use LofAudioSupply\AuthException;

/**
 * Establishes FPP administrator authority for a web request, and gates every
 * mutation behind it.
 *
 * FPP can be run with its login disabled, in which case the web tier offers no
 * identity at all. This class fails closed in that situation: status stays
 * readable, but nothing that changes configuration, starts a transfer, or
 * activates a generation will run. That is a deliberate behaviour change from
 * the pre-hardening plugin, which executed shell commands for any unauthenticated
 * caller who could reach the page.
 */
final class Auth
{
    /**
     * Session keys FPP is known to populate once a user has logged in. Any one
     * of them proves the web tier authenticated somebody.
     */
    private const FPP_SESSION_USER_KEYS = ['fppUser', 'fpp_user', 'username', 'user', 'loggedInUser'];

    /** @var array<string,mixed> */
    private array $server;

    /** @var array<string,mixed> */
    private array $session;

    /**
     * @param array<string,mixed>|null $server
     * @param array<string,mixed>|null $session
     */
    public function __construct(?array $server = null, ?array $session = null)
    {
        $this->server = $server ?? $_SERVER;
        $this->session = $session ?? ($_SESSION ?? []);
    }

    public function authority(): Authority
    {
        // HTTP auth in front of FPP (its own "require login", or a reverse
        // proxy) is the strongest signal available to a plugin page.
        foreach (['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $key) {
            $value = $this->server[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return Authority::granted($this->sanitizeIdentity($value), $key);
            }
        }

        foreach (self::FPP_SESSION_USER_KEYS as $key) {
            $value = $this->session[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return Authority::granted($this->sanitizeIdentity($value), 'session:' . $key);
            }
        }

        return Authority::denied('auth.no_identity');
    }

    private function sanitizeIdentity(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._@\- ]/', '', $value) ?? '';

        return substr(trim($clean), 0, 64);
    }

    /**
     * Reject a cross-site POST whose Origin or Referer disagrees with Host.
     *
     * Defence in depth behind the CSRF token, not a replacement for it: a
     * browser that omits both headers still has to present a valid token.
     */
    public function sameOriginOk(): bool
    {
        $host = $this->server['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '') {
            return true;
        }
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
            $value = $this->server[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if ($value === 'null') {
                return false;
            }
            $parsedHost = parse_url($value, PHP_URL_HOST);
            if (!is_string($parsedHost) || $parsedHost === '') {
                return false;
            }
            $parsedPort = parse_url($value, PHP_URL_PORT);
            $candidate = is_int($parsedPort) ? $parsedHost . ':' . $parsedPort : $parsedHost;

            if (strcasecmp($candidate, $host) !== 0 && strcasecmp($parsedHost, $this->hostWithoutPort($host)) !== 0) {
                return false;
            }

            // The first header present decides; checking Origin is enough.
            return true;
        }

        return true;
    }

    private function hostWithoutPort(string $host): string
    {
        $colon = strrpos($host, ':');
        if ($colon === false) {
            return $host;
        }
        // Leave bracketed IPv6 literals alone.
        if (strpos($host, ']') !== false && strpos($host, ']') > $colon) {
            return $host;
        }

        return substr($host, 0, $colon);
    }

    /**
     * The single gate every mutating action must pass.
     *
     * @throws AuthException when authority, origin, or CSRF proof is missing
     */
    public function assertMutationAllowed(string $action, ?string $presentedToken, Csrf $csrf): Authority
    {
        $method = $this->server['REQUEST_METHOD'] ?? '';
        if (!is_string($method) || strtoupper($method) !== 'POST') {
            throw new AuthException('auth.method_not_allowed', 'Mutations require POST.', ['action' => $action]);
        }

        $authority = $this->authority();
        if (!$authority->administrator) {
            throw new AuthException(
                'auth.not_authenticated',
                'FPP administrator authentication is required. Enable FPP login before changing this plugin.',
                ['action' => $action]
            );
        }

        if (!$this->sameOriginOk()) {
            throw new AuthException('auth.cross_origin', 'Request origin does not match this host.', ['action' => $action]);
        }

        if (!$csrf->available()) {
            throw new AuthException('auth.no_session', 'No session is available; cannot verify a CSRF token.', ['action' => $action]);
        }

        $csrf->assert($action, $presentedToken);

        return $authority;
    }

    /**
     * Refuse to render when the file was fetched directly rather than included
     * by FPP's plugin dispatcher.
     */
    public static function isDirectlyRequested(string $file): bool
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (!is_string($script) || $script === '') {
            return false;
        }
        $realScript = realpath($script);
        $realFile = realpath($file);

        return $realScript !== false && $realFile !== false && $realScript === $realFile;
    }
}
