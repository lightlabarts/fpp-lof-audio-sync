<?php

declare(strict_types=1);

namespace LofAudioSupply\Security;

/**
 * Who the current request is acting as, and how that was established.
 */
final class Authority
{
    public function __construct(
        public readonly bool $administrator,
        public readonly string $identity,
        public readonly string $source,
        public readonly string $reasonCode
    ) {
    }

    public static function denied(string $reasonCode): self
    {
        return new self(false, '', 'none', $reasonCode);
    }

    public static function granted(string $identity, string $source): self
    {
        return new self(true, $identity, $source, 'ok');
    }
}
