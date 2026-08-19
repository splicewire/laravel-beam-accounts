<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The team-invitation LIST + CREATE (send) + REVOKE resource (Frame OS ticket 20 — promoted from
 * tower's `Splicewire\Tower\Data\Frame\InvitationResourceData`, now domain-neutral in beam-accounts).
 *
 * The invite form IS its schema-form (`email` + `role` via {@see CreateInvitationData}). The bespoke
 * semantics ride the convention hooks (no bespoke handler — beam-core's generic
 * `ParticleFrameResourceHandler` serves the Frame transport):
 *  - CREATE ({@see self::prepare()}): owner/admin AUTHORIZATION (403) then `updateOrCreate` by the
 *    `unique(team_id, email)` composite key — finds an existing invite for [team, email] and, when
 *    present, makes the fresh model REPRESENT that row so the generic writer's `save()` UPDATEs
 *    rather than INSERT-colliding on a re-invite; mints a fresh token and stamps `invited_by` +
 *    `accepted_at = null`. The writer then fills `role` from the DTO and persists.
 *  - REVOKE only-if-pending ({@see self::scope()}): the current team + `whereNull(accepted_at)`, so
 *    an accepted / foreign-team invitation never resolves (404), never deletes.
 *  - NO in-place edit → `editable: false` (show/update 405) while create + delete stay open.
 *
 * DOMAIN-NEUTRAL: the team is {@see BeamAccounts::currentTeam()} (the current-or-personal team by default; a
 * host binds `beam.accounts.teams.resolver` to scope to its own notion — e.g. tower's TENANT). Mail
 * send/resend + token-based accept stay a host REST survivor (over-ceiling for the generic pipeline).
 */
#[ParticleResource(
    key: 'invitations',
    model: Invitation::class,
    label: 'Invitations',
    group: 'Settings',
    icon: 'mail',
    form: 'bare',
    input: CreateInvitationData::class,
    editData: CreateInvitationData::class,
    filterable: false,
    editable: false,
)]
#[TypeScript]
class InvitationData extends Data
{
    public function __construct(
        #[NotInList]
        #[Description('The invitation id. Delete it to revoke a still-pending invite.')]
        public string $id,
        #[Column(label: 'Email', sort: 0)]
        #[Description('Address the invitation was sent to. Unique per team.')]
        public string $email,
        #[Column(label: 'Role', sort: 1)]
        #[Description('Role the invitee joins as: admin or member. Owner is not invitable.')]
        public string $role,
        #[Column(label: 'Status', sort: 2)]
        #[Description('When the invite was accepted, ISO-8601; null while it is still pending.')]
        public ?string $acceptedAt,
        #[Column(label: 'Sent', sort: 3)]
        #[Sortable(default: true, direction: 'desc')]
        #[Description('When the invitation was sent, ISO-8601. Newest first is the default order.')]
        public ?string $createdAt,
        // Detail-only: an opaque actor id and a mtime are not list columns. Both are nullable
        // because the two tables this shape serves disagree — beam-accounts' `invitations.invited_by`
        // is nullable (an invite can predate the stamp), while beam-tenancy's `tenant_invitations`
        // makes it NOT NULL. The wider type is the one that holds for both.
        #[NotInList]
        #[Description('Opaque id of the user who sent the invitation; null if it predates the stamp.')]
        public ?string $invitedBy = null,
        #[NotInList]
        #[Description('When the invitation was last re-sent or its role changed, ISO-8601.')]
        public ?string $updatedAt = null,
    ) {}

    /**
     * Owner/admin gate + `updateOrCreate([team_id, email])` + token mint, reproducing the retired
     * bespoke create. The generic `store` hands a fresh model; when a row already exists for
     * [team, email] we make THIS model represent it so `save()` UPDATEs (honouring the DB
     * `unique(team_id, email)` on re-invite).
     */
    public static function prepare(Model $invitation, CreateInvitationData $input, ?object $actor): void
    {
        $team = BeamAccounts::currentTeam();
        abort_if($team === null, 403, 'No active team to invite into.');

        $teamKey = $team->getKey();

        // Owner/admin only — reproduces the bespoke owner/admin check.
        $pivotRole = method_exists($actor, 'teamRole')
            ? $actor->teamRole($team)
            : optional($team->memberships()->where('user_id', $actor?->getKey())->first())->role;

        abort_unless(
            in_array($pivotRole, [Role::Owner->value, Role::Admin->value], true),
            403,
            'Only owners and admins can manage invitations.'
        );

        $existing = Invitation::query()
            ->where('team_id', $teamKey)
            ->where('email', $input->email)
            ->first();

        if ($existing !== null) {
            $invitation->setRawAttributes($existing->getAttributes(), sync: true);
            $invitation->exists = true;
        }

        $invitation->team_id = $teamKey;
        $invitation->email = $input->email;
        $invitation->token = (string) Str::uuid();
        $invitation->invited_by = $actor?->getKey();
        $invitation->accepted_at = null;
    }

    /**
     * The pending-invitations scope for the current team — applied to BOTH the list and the Frame
     * revoke-by-id subject resolution, so an accepted / foreign-team invitation never resolves.
     */
    public static function scope(Builder $query): Builder
    {
        return $query
            ->where('team_id', BeamAccounts::currentTeam()?->getKey())
            ->whereNull('accepted_at');
    }

    public static function project(Model $invitation): self
    {
        return new self(
            id: (string) $invitation->getKey(),
            email: $invitation->email,
            role: $invitation->role,
            acceptedAt: $invitation->accepted_at?->toIso8601String(),
            createdAt: $invitation->created_at?->toIso8601String(),
            invitedBy: $invitation->invited_by === null ? null : (string) $invitation->invited_by,
            updatedAt: $invitation->updated_at?->toIso8601String(),
        );
    }
}
