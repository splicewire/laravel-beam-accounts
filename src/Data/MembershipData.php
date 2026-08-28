<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Data\BeamData;

/**
 * The team-members LIST row (Frame OS ticket 20 — promoted from tower's
 * `Splicewire\Tower\Data\Frame\MembershipResourceData`, now domain-neutral in beam-accounts).
 *
 * Members are LIST-ONLY through Frame — you can't create a member directly (they arrive by
 * accepting an invite), and the inline Role cell + owner-only remove are host-supplied escape
 * hatches against a REST survivor. The `members` resource is SOURCE-backed
 * ({@see MembershipSource}) — registered imperatively as a raw ResourceDefinition rather than by
 * `#[ParticleResource]`, because that attribute names ONE `model:` and this resource has to serve
 * hosts whose membership lives on a foreign pivot as well as beam's own `beam_memberships` rows.
 * (The package does ship a `Membership` model; it is the beam-native half, not the whole
 * population — see {@see MembershipSource}'s docblock for the full argument.) A source resource is
 * `creatable: false` + `deletable: false`, so create/edit/delete all 405.
 */
#[TypeScript]
class MembershipData extends BeamData
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
