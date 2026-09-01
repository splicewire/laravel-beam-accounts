<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Data\BeamData;
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
    backing: Invitation::class,
    label: 'Invitations',
    group: 'Settings',
    icon: 'mail',
    form: 'bare',
    input: CreateInvitationData::class,
    editData: CreateInvitationData::class,
    filterable: false,
    editable: false,
    // No per-record detail — an invitation is listed, re-sent or revoked, never opened. The two
    // `#[NotInList]` props below are a detail SHAPE nothing serves: `invitedBy` is an opaque actor id and
    // `updatedAt` a mtime, and with `editable: false` there is no edit surface to feed either.
    // ⚠️ `showable` defaults TRUE (readable ⇒ showable), so leaving it off is a promise made by not
    // opting out — the same class of accident as `filterable`. `~/Herd/splicewire-app` closed it in the
    // inline manifest that particle-manifest-repatriation 06 retired; this is that host fact descending,
    // and it keeps `records/{id}` a 405 rather than turning it into a 200 on the way through.
    showable: false,
)]
#[TypeScript]
class InvitationData extends BeamData
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
        // because `beam_invitations.invited_by` is — an invite can predate the stamp. It was
        // ALSO the wider of two disagreeing tables, back when `splicewire/laravel-beam-tenancy`
        // forked a `tenant_invitations` that made the column NOT NULL; that fork is retired
        // (`team_id` holds a `TeamContract` key now), so there is one table and the nullable
        // reading is simply the true one rather than the accommodating one.
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

        $teamKey = (string) $team->getKey();

        // Owner/admin only — asked through the TEAM CONTRACT, which every team notion in the estate
        // satisfies: beam's own `Team` implements `memberRole()` directly, and a host whose team lives on
        // a foreign pivot gets it from {@see \Splicewire\Beam\Accounts\Concerns\HasMembers}.
        //
        // ⚠️ This used to be `method_exists($actor, 'teamRole') ? $actor->teamRole($team) : …` and it
        // could not have worked off beam's own schema. `BelongsToTeams::teamRole()` is TYPED
        // `teamRole(Team $team)`, so at a host whose team is not beam's `Team` the `method_exists` probe
        // passes and the call is a TypeError; the fallback arm then reached `$team->memberships()`, a
        // relation only beam's `Team` has. Both arms named beam's own schema while the guard pretended
        // to be neutral — measured 2026-09-01 against `~/Herd/splicewire-app`, whose team is a
        // string-keyed `Tenant` over `tenant_users`. The contract method is the neutral question.
        $role = $actor instanceof Authenticatable ? $team->memberRole($actor) : null;

        abort_unless(
            in_array($role, [Role::Owner, Role::Admin], true),
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
        // A 64-character `Str::random`, not a uuid. The estate's redemption route takes the token as a
        // PATH SEGMENT (`POST invitations/{token}/accept`, {@see \Splicewire\Tower\Tenancy\Invitations\AcceptTenantInvitation}),
        // so it is a bearer secret standing alone in a URL — a v4 uuid carries 122 bits and advertises
        // its own shape. Every host minting one already used this; the DTO was the outlier.
        $invitation->token = Str::random(64);
        $invitation->invited_by = $actor?->getKey();
        $invitation->accepted_at = null;
    }

    /**
     * The pending-invitations scope for the current team — applied to BOTH the list and the Frame
     * revoke-by-id subject resolution, so an accepted / foreign-team invitation never resolves.
     */
    public static function scope(Builder $query): Builder
    {
        $team = BeamAccounts::currentTeam();

        return $query
            // `(string)` deliberately, and only when there IS a team: `beam_invitations.team_id` holds a
            // `TeamContract` key (declared `int|string`) and the model casts the column to string, so
            // both sides are normalised at the comparison. A null team must stay null — casting it would
            // ask for `team_id = ''` and match nothing by accident rather than by construction.
            ->where('team_id', $team === null ? null : (string) $team->getKey())
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
