<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Model;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Data\BeamData;
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
    // No data-filters query, so stop promising one (`particle.filterable-promise`;
    // `particle-write-surface` 09). `filterable` defaults to `true` and this never opted out, so
    // `ParticleController::index()` would raise on a key with no registration. Nothing reaches it: a
    // route sweep at `~/Herd/splicewire-app` found `teams` on **zero** of 926 routes (54
    // `ParticleController` routes matched in the same pass, so the zero is a real absence, not a
    // failed sweep), and no `->beam()->inResource('teams')` stamp exists in the estate. The resource
    // is reachable only through Frame's `{resource}` wildcard, which catches and degrades.
    filterable: false,
    backing: Team::class,
    label: 'Teams',
    group: 'Platform',
    icon: 'building',
    form: 'bare',
    readOnly: true,
)]
#[TypeScript]
class TeamData extends BeamData
{
    public function __construct(
        #[NotInList]
        #[Description('The team id.')]
        public string $id,
        #[Column(label: 'Team', sort: 0)]
        #[Description('Display name of the team.')]
        public string $name,
        #[Column(label: 'Owner', sort: 1)]
        #[Description('Email of the team owner; null if the owner record is missing.')]
        public ?string $ownerEmail,
        #[Column(label: 'Members', sort: 2)]
        #[Description('How many seats the team currently holds, the owner included.')]
        public int $memberCount,
        #[Column(label: 'Personal', sort: 3)]
        #[Description('True for the team-of-one minted at registration, false for a shared team.')]
        public bool $personal,
        #[Column(label: 'Created', sort: 4)]
        #[Sortable(default: true, direction: 'desc')]
        #[Description('When the team was provisioned, ISO-8601. Newest first is the default order.')]
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
