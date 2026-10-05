<?php

namespace Splicewire\Beam\Accounts\Doors;

/** Whether a door admits an address, and why not when it does not. */
final class Admission
{
    private function __construct(
        public readonly bool $admitted,
        public readonly ?string $reason = null,
    ) {}

    public static function admit(): self
    {
        return new self(true);
    }

    public static function refuse(string $reason): self
    {
        return new self(false, $reason);
    }
}
