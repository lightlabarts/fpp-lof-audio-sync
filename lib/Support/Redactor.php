<?php

declare(strict_types=1);

namespace LofAudioSupply\Support;

/**
 * Strips credential material out of anything the plugin is about to show or log.
 *
 * Child-process output is the main risk: rsync and ssh happily print key paths,
 * agent contents, and occasionally a URL with an embedded password. The status
 * page and the health record are both read-only surfaces, and neither is
 * allowed to leak a secret, so everything they emit goes through here.
 */
final class Redactor
{
    public const PLACEHOLDER = '[redacted]';

    /** Setting/field names whose value is never rendered. */
    private const SECRET_FIELDS = [
        'password',
        'passphrase',
        'secret',
        'token',
        'credential',
        'credentials',
        'private_key',
        'privatekey',
        'key_material',
        'authorization',
        'api_key',
        'apikey',
    ];

    public static function text(string $value): string
    {
        // PEM blocks, including the OpenSSH container format.
        $value = preg_replace(
            '/-----BEGIN[^-]{0,80}(PRIVATE KEY|OPENSSH PRIVATE KEY)-----.*?-----END[^-]{0,80}-----/s',
            self::PLACEHOLDER,
            $value
        ) ?? $value;
        // A bare BEGIN line with no matching END (truncated output).
        $value = preg_replace(
            '/-----BEGIN[^-\n]{0,80}PRIVATE KEY-----.*/s',
            self::PLACEHOLDER,
            $value
        ) ?? $value;
        // Public/authorized-keys blobs. Not strictly secret, but they identify
        // the trust relationship and have no place on a status page.
        $value = preg_replace(
            '/\b(ssh-(?:rsa|dss|ed25519)|ecdsa-sha2-nistp\d+)\s+[A-Za-z0-9+\/=]{20,}/',
            '$1 ' . self::PLACEHOLDER,
            $value
        ) ?? $value;
        // key=value credential pairs in command output.
        $value = preg_replace(
            '/\b(password|passphrase|secret|token|api[_-]?key)\b(\s*[:=]\s*)\S+/i',
            '$1$2' . self::PLACEHOLDER,
            $value
        ) ?? $value;
        // Credentials embedded in a URL.
        $value = preg_replace(
            '#\b([a-z][a-z0-9+.\-]*://)[^/@\s:]+:[^/@\s]+@#i',
            '$1' . self::PLACEHOLDER . '@',
            $value
        ) ?? $value;
        // HTTP auth headers.
        $value = preg_replace(
            '/\b(Authorization\s*:\s*)(\S+\s+)?\S+/i',
            '$1' . self::PLACEHOLDER,
            $value
        ) ?? $value;

        return $value;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public static function structure(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            // Match on the name only where the value could actually carry
            // credential material. A boolean or a number cannot, and blanking
            // one on a name collision loses real information - "owns":
            // {"browser_authorization": false} is a scope declaration, not a
            // secret.
            if (is_string($key) && self::isSecretField($key) && (is_string($value) || is_array($value))) {
                $out[$key] = self::PLACEHOLDER;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::structure($value);
                continue;
            }
            $out[$key] = is_string($value) ? self::text($value) : $value;
        }

        return $out;
    }

    private static function isSecretField(string $key): bool
    {
        $needle = strtolower($key);
        foreach (self::SECRET_FIELDS as $field) {
            if (strpos($needle, $field) !== false) {
                // "key_path" names a location, not key material; the operator
                // has to be able to see which key is configured.
                if ($field === 'private_key' && substr($needle, -5) === '_path') {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Make child-process output safe to persist: redact, bound, and strip
     * control bytes that would otherwise let output rewrite a terminal or a log.
     */
    public static function processOutput(string $value, int $maxBytes = 8192): string
    {
        $value = self::text($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        if (strlen($value) > $maxBytes) {
            $value = substr($value, 0, $maxBytes) . "\n[truncated]";
        }

        return $value;
    }
}
