<?php

namespace Splicewire\Beam\Accounts\Doors;

/** What a door creates a user from. A null password leaves the account to be claimed; `verified` marks the email proven. */
final class NewUserData
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $password = null,
        public readonly bool $verified = false,
    ) {}
}
