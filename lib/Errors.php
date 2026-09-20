<?php

declare(strict_types=1);

namespace LofAudioSupply;

/**
 * Base for every error this component raises.
 *
 * Every exception carries a stable machine code so the health record and the
 * web UI can report a failure without echoing operator-supplied text back into
 * a page or a log line.
 */
class LofAudioException extends \RuntimeException
{
    private string $errorCode;

    /** @var array<string,scalar|null> */
    private array $context;

    /**
     * @param array<string,scalar|null> $context
     */
    public function __construct(string $code, string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
        $this->context = $context;
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,scalar|null> */
    public function context(): array
    {
        return $this->context;
    }
}

/** A configuration value failed an explicit type, bound, or allowlist check. */
class ValidationException extends LofAudioException
{
    private string $field;

    /**
     * @param array<string,scalar|null> $context
     */
    public function __construct(string $field, string $code, string $message, array $context = [])
    {
        parent::__construct($code, $message, $context + ['field' => $field]);
        $this->field = $field;
    }

    public function field(): string
    {
        return $this->field;
    }
}

/** A path or argument tried to leave the approved estate. */
class PolicyViolationException extends LofAudioException
{
}

/** Another publish run holds the exclusive lock. */
class LockException extends LofAudioException
{
}

/** A child process failed, timed out, or could not be started. */
class TransportException extends LofAudioException
{
}

/** Manifest, size, or digest verification failed. */
class IntegrityException extends LofAudioException
{
}

/** Staging, activation, quarantine, or rollback could not complete safely. */
class PublishException extends LofAudioException
{
}

/** Administrator authority or CSRF proof was absent or invalid. */
class AuthException extends LofAudioException
{
}
