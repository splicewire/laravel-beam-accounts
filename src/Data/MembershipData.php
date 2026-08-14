<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;

/**
 * The team-members LIST row (Frame OS ticket 20 — promoted from tower's
 * `Splicewire\Tower\Data\Frame\MembershipResourceData`, now domain-neutral in beam-accounts).
 *
 * Members are LIST-ONLY through Frame — you can't create a member directly (they arrive by
 * accepting an invite), and the inline Role cell + owner-only remove are host-supplied escape
 * hatches against a REST survivor. Members are MODEL-LESS: the list is the team-memberships
 * PIVOT (role + joinedAt live there, not on a plain user), so the `members` resource is
 * SOURCE-backed ({@see MembershipSource}) — registered
 * imperatively as a raw ResourceDefinition (the model-required `#[ParticleResource]` attribute
 * can't express this shape). A source resource is `creatable: false` + `deletable: false`, so
 * create/edit/delete all 405.
 */
#[TypeScript]
class MembershipData extends Data
{
    public function __construct(
        #[NotInList]
        public string $id,
        #[Column(label: 'Member', sort: 0)]
        public ?string $name,
        #[Column(label: 'Email', sort: 1)]
        public string $email,
        #[Column(label: 'Role', sort: 2)]
        public string $role,
        #[Column(label: 'Joined', sort: 3)]
        public ?string $joinedAt,
    ) {}
}
