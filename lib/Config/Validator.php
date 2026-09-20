<?php

declare(strict_types=1);

namespace LofAudioSupply\Config;

use LofAudioSupply\PolicyViolationException;
use LofAudioSupply\Support\SafePath;
use LofAudioSupply\ValidationException;

/**
 * Turns an untrusted array into Settings, or refuses.
 *
 * Every field is checked by explicit type, explicit bound, and - for anything
 * that reaches a process argument or the filesystem - an explicit allowlist.
 * There is no pass-through: a key that is not recognised is ignored, and a
 * recognised key that fails a check aborts the whole save rather than being
 * silently coerced.
 */
final class Validator
{
    private Policy $policy;

    public function __construct(?Policy $policy = null)
    {
        $this->policy = $policy ?? Policy::default();
    }

    public function policy(): Policy
    {
        return $this->policy;
    }

    /**
     * @param array<string,mixed> $raw
     * @throws ValidationException on the first field that fails
     */
    public function validate(array $raw): Settings
    {
        $enabled = $this->boolField($raw, 'enabled', false);
        $distributionEnabled = $this->boolField($raw, 'distribution_enabled', false);

        $sourcePath = $this->approvedLocalPath(
            $this->stringField($raw, 'source_path', $this->policy->defaultSourcePath()),
            $this->policy->sourceRoots,
            'source_path',
            true
        );

        $publicationRoot = $this->approvedLocalPath(
            $this->stringField($raw, 'publication_root', $this->policy->defaultPublicationRoot()),
            $this->policy->publicationRoots,
            'publication_root',
            false
        );

        if ($sourcePath === $publicationRoot
            || SafePath::isContainedIn($sourcePath, $publicationRoot)
            || SafePath::isContainedIn($publicationRoot, $sourcePath)) {
            throw new ValidationException(
                'publication_root',
                'path.overlaps_source',
                'Publication root and source path must not contain one another.'
            );
        }

        $host = $this->stringField($raw, 'destination_host', '');
        if ($host !== '') {
            $host = $this->approvedHost($host);
        }

        $port = $this->intField($raw, 'destination_port', 22, 1, 65535);

        $destinationPath = $this->stringField($raw, 'destination_path', $this->policy->defaultDestinationPath());
        $destinationPath = $this->approvedRemotePath($destinationPath);

        $serviceUser = $this->approvedServiceUser($this->stringField($raw, 'service_user', $this->policy->defaultServiceUser()));

        $keyPath = $this->stringField($raw, 'ssh_key_path', '');
        if ($keyPath !== '') {
            $keyPath = $this->approvedKeyPath($keyPath);
        }

        $interval = $this->intField(
            $raw,
            'sync_interval_seconds',
            60,
            $this->policy->minSyncInterval,
            $this->policy->maxSyncInterval
        );
        $retain = $this->intField(
            $raw,
            'retain_generations',
            5,
            $this->policy->minRetainGenerations,
            $this->policy->maxRetainGenerations
        );
        $quarantineDays = $this->intField(
            $raw,
            'quarantine_retention_days',
            30,
            $this->policy->minQuarantineDays,
            $this->policy->maxQuarantineDays
        );

        // Arming the remote leg requires the remote leg to be fully specified.
        if ($distributionEnabled) {
            if ($host === '') {
                throw new ValidationException('destination_host', 'distribution.host_required', 'A destination host is required when distribution is enabled.');
            }
            if ($keyPath === '') {
                throw new ValidationException('ssh_key_path', 'distribution.key_required', 'An approved SSH key path is required when distribution is enabled.');
            }
        }

        return new Settings(
            $enabled,
            $sourcePath,
            $publicationRoot,
            $distributionEnabled,
            $host,
            $port,
            $destinationPath,
            $serviceUser,
            $keyPath,
            $interval,
            $retain,
            $quarantineDays
        );
    }

    /**
     * Validate without throwing, for form redisplay.
     *
     * @param array<string,mixed> $raw
     * @return array{0: Settings|null, 1: list<array{field:string,code:string,message:string}>}
     */
    public function check(array $raw): array
    {
        try {
            return [$this->validate($raw), []];
        } catch (ValidationException $e) {
            return [null, [['field' => $e->field(), 'code' => $e->code(), 'message' => $e->getMessage()]]];
        } catch (PolicyViolationException $e) {
            $context = $e->context();
            $field = isset($context['field']) ? (string) $context['field'] : 'policy';

            return [null, [['field' => $field, 'code' => $e->code(), 'message' => $e->getMessage()]]];
        }
    }

    /** @param array<string,mixed> $raw */
    public function boolField(array $raw, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $raw)) {
            return $default;
        }
        $value = $raw[$field];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        throw new ValidationException($field, 'type.not_bool', 'Value must be a boolean.');
    }

    /** @param array<string,mixed> $raw */
    public function stringField(array $raw, string $field, string $default): string
    {
        if (!array_key_exists($field, $raw) || $raw[$field] === null) {
            return $default;
        }
        $value = $raw[$field];
        if (!is_string($value)) {
            throw new ValidationException($field, 'type.not_string', 'Value must be a string.');
        }
        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

    /** @param array<string,mixed> $raw */
    public function intField(array $raw, string $field, int $default, int $min, int $max): int
    {
        if (!array_key_exists($field, $raw) || $raw[$field] === null || $raw[$field] === '') {
            return $default;
        }
        $value = $raw[$field];
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d{1,19}$/', trim($value)) === 1) {
            $int = (int) trim($value);
        } else {
            // No silent (int) cast: "22; rm -rf /" must fail, not become 22.
            throw new ValidationException($field, 'type.not_integer', 'Value must be an integer.');
        }
        if ($int < $min || $int > $max) {
            throw new ValidationException($field, 'bound.out_of_range', 'Value is outside the permitted range.', ['min' => $min, 'max' => $max]);
        }

        return $int;
    }

    /**
     * @param list<string> $roots
     */
    public function approvedLocalPath(string $path, array $roots, string $field, bool $mustExist): string
    {
        SafePath::assertSafeValue($path, $field);
        $normalized = SafePath::normalizeAbsolute($path, $field);
        $normalized = rtrim($normalized, '/');
        if ($normalized === '') {
            throw new ValidationException($field, 'path.is_root', 'Path must not be the filesystem root.');
        }

        $matchedRoot = null;
        foreach ($roots as $root) {
            if (SafePath::isContainedIn($root, $normalized)) {
                $matchedRoot = $root;
                break;
            }
        }
        if ($matchedRoot === null) {
            throw new ValidationException($field, 'path.not_approved', 'Path is not inside an approved root.');
        }

        if (is_dir($matchedRoot)) {
            // Only resolvable when the approved root exists on this host; that
            // is what catches "<root>/link -> /etc".
            SafePath::assertRealWithin($matchedRoot, $normalized, $field);
        }
        if ($mustExist && !is_dir($normalized)) {
            throw new ValidationException($field, 'path.missing', 'Directory does not exist.');
        }

        return $normalized;
    }

    /**
     * Remote paths cannot be resolved locally, so they get the strict lexical
     * treatment plus the destination-root allowlist and nothing more.
     */
    public function approvedRemotePath(string $path): string
    {
        $field = 'destination_path';
        SafePath::assertSafeValue($path, $field);
        $normalized = rtrim(SafePath::normalizeAbsolute($path, $field), '/');
        if ($normalized === '') {
            throw new ValidationException($field, 'path.is_root', 'Destination path must not be the filesystem root.');
        }
        foreach ($this->policy->destinationRoots as $root) {
            if (SafePath::isContainedIn($root, $normalized)) {
                return $normalized;
            }
        }

        throw new ValidationException($field, 'path.not_approved', 'Destination path is not inside an approved remote root.');
    }

    public function approvedKeyPath(string $path): string
    {
        $field = 'ssh_key_path';
        $normalized = $this->approvedLocalPath($path, $this->policy->keyRoots, $field, false);
        if (is_file($normalized)) {
            $perms = @fileperms($normalized);
            if ($perms !== false && ($perms & 0o077) !== 0) {
                throw new ValidationException(
                    $field,
                    'key.permissive_mode',
                    'SSH key file is readable by group or other; tighten it to 0600.'
                );
            }
        }

        return $normalized;
    }

    public function approvedServiceUser(string $user): string
    {
        $field = 'service_user';
        SafePath::assertSafeValue($user, $field);
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1) {
            throw new ValidationException($field, 'user.bad_grammar', 'Service user is not a valid POSIX user name.');
        }
        if (!in_array($user, $this->policy->serviceUsers, true)) {
            throw new ValidationException($field, 'user.not_approved', 'Service user is not on the approved list.');
        }

        return $user;
    }

    public function approvedHost(string $host): string
    {
        $field = 'destination_host';
        SafePath::assertSafeValue($host, $field);
        if (strlen($host) > 253) {
            throw new ValidationException($field, 'host.too_long', 'Host is too long.');
        }
        // An embedded user, port, path, or option is a separate field's job.
        if (preg_match('/[@:\/\\\\\s]/', $host) === 1 && !HostAllowlist::isIpLiteral($host)) {
            throw new ValidationException($field, 'host.bad_grammar', 'Host must not contain a user, port, path, or whitespace.');
        }
        if (!HostAllowlist::isIpLiteral($host) && !HostAllowlist::isValidHostname($host)) {
            throw new ValidationException($field, 'host.bad_grammar', 'Host is neither a valid IP literal nor a valid hostname.');
        }
        if (!HostAllowlist::matches($host, $this->policy->hostAllowlist)) {
            throw new ValidationException($field, 'host.not_approved', 'Host is not on the approved list.');
        }

        return strtolower($host);
    }
}
