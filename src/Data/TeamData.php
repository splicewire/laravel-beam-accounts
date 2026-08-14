<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The teams-admin LIST + DETAIL resource (Frame OS ticket 20).
 *
 * The domain-neutral analog of a "Tenants" admin surface: beam-accounts' tenancy primitive is the
 * TEAM (ADR-0015 §Proving row 7, "team-based tenancy"), so a fresh host's OOTB tenant-admin list is
 * the teams over `beam_teams` — name, owner, member count, personal/shared, created. READ-ONLY through
 * Frame (`readOnly: true` ⇒ show serves the detail, store/update/destroy 405): teams are provisioned by
 * the account runtime (registration mints a personal team; invites grow a shared one), not created from
 * an admin form.
 *
 * A host with a RICHER tenant admin — cross-model plan/bill enrichment, provisioning status, suspend /
 * scaffold-pack mutations (e.g. splicewire-app's tower `TenantData` + `TenantAdminSource`) — layers that
 * as its OWN source-backed resource under a different key (`tenants`), a host EXTENSION on top of this
 * neutral base rather than a fold into the package. This resource stays commerce-free and tenancy-engine-free.
 */
#[ParticleResource(
    key: 'teams',
    model: Team::class,
    label: 'Teams',
    group: 'Platform',
    icon: 'building',
    form: 'bare',
    readOnly: true,
)]
#[TypeScript]
class TeamData extends Data
{
    public function __construct(
        #[NotInList]
        public string $id,
        #[Column(label: 'Team', sort: 0)]
        public string $name,
        #[Column(label: 'Owner', sort: 1)]
        public ?string $ownerEmail,
        #[Column(label: 'Members', sort: 2)]
        public int $memberCount,
        #[Column(label: 'Personal', sort: 3)]
        public bool $personal,
        #[Column(label: 'Created', sort: 4)]
        public ?string $createdAt,
    ) {}

    public static function project(Model $team): self
    {
        return new self(
            id: (string) $team->getKey(),
            name: $team->name,
            ownerEmail: $team->owner?->email,
            memberCount: $team->memberships()->count(),
            personal: (bool) ($team->personal_team ?? false),
            createdAt: $team->created_at?->toIso8601String(),
        );
    }
}
