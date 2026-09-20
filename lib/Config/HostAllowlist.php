<?php

declare(strict_types=1);

namespace LofAudioSupply\Config;

/**
 * Exact-match host approval.
 *
 * Three entry forms are supported, and nothing else is: a literal host, a CIDR
 * block, or a dotted DNS suffix. A bare substring is never treated as a match,
 * so "evil-lightsonfalcon.com.attacker.net" cannot ride in on the
 * ".lightsonfalcon.com" entry.
 */
final class HostAllowlist
{
    /** @param list<string> $allowlist */
    public static function matches(string $host, array $allowlist): bool
    {
        $host = strtolower($host);
        foreach ($allowlist as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                if (self::inCidr($host, $entry)) {
                    return true;
                }
                continue;
            }
            if ($entry[0] === '.') {
                $suffixLength = strlen($entry);
                if (strlen($host) > $suffixLength && substr($host, -$suffixLength) === $entry) {
                    return true;
                }
                continue;
            }
            if ($host === $entry) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $host, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$network, $prefixRaw] = $parts;
        if ($prefixRaw === '' || preg_match('/^\d{1,3}$/', $prefixRaw) !== 1) {
            return false;
        }
        $prefix = (int) $prefixRaw;

        $hostBin = @inet_pton($host);
        $netBin = @inet_pton($network);
        if ($hostBin === false || $netBin === false || strlen($hostBin) !== strlen($netBin)) {
            return false;
        }
        $bits = strlen($hostBin) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }
        if ($prefix === 0) {
            // A /0 entry would approve the whole internet; refuse it outright.
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if ($fullBytes > 0 && strncmp($hostBin, $netBin, $fullBytes) !== 0) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($hostBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    /**
     * RFC 1123 hostname grammar. Rejects underscores, leading/trailing dashes,
     * empty labels, over-long labels, and anything non-ASCII.
     */
    public static function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (substr($host, -1) === '.') {
            $host = substr($host, 0, -1);
        }
        $labels = explode('.', $host);
        if ($labels === ['']) {
            return false;
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
