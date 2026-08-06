<?php

namespace Splicewire\Beam\Accounts\Data\Frame;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * The `editData` escape hatch for the Invitations resource (Frame OS ticket 20 — promoted from
 * tower's `Splicewire\Tower\Data\Frame\CreateInvitationData`) — the invite create input
 * (`email` + `role`). `role` is validated against the invitable subset of the beam-accounts
 * `Role` enum (owner excluded — invite-excludes-owner as a constraint over the one enum, not a
 * second list). The resource's `prepare` enforces the owner/admin authorization on top.
 */
#[TypeScript]
class CreateInvitationData extends Data
{
    public function __construct(
        #[Required, Email]
        public string $email,
        #[In(['admin', 'member'])]
        public string $role = 'member',
    ) {}

    /**
     * @return array<int, string>
     */
    public static function invitableRoles(): array
    {
        return Role::invitableValues();
    }
}
