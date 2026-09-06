<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Facades\Beam;

/**
 * A pending or accepted seat offer.
 *
 * ## Why it carries a policy
 *
 * `schemastud/laravel-frame`'s generic resource socket fails CLOSED on the write axis: a model with
 * no policy refuses `create`/`update`/`delete` for every actor, owner included
 * ({@see \Schemastud\Frame\Authorization\ResourceAuthorizer::allows()}). `invitations` is a
 * write-capable Frame resource ({@see \Splicewire\Beam\Accounts\Data\InvitationData} — `create` +
 * `delete`, `editable: false`), so without this attribute "invite a teammate" is a 403 out of the
 * box. Measured at `~/Herd/beam` 2026-09-05, before this line existed.
 *
 * Plain `#[UseCascadePolicy]` with NO overrides is the whole declaration: the tiers
 * {@see \Splicewire\Beam\Accounts\Authorization\RolePermissions::DEFAULT_ABILITIES} seeds already
 * say what this resource needs — owner and admin hold `create`/`delete`, member holds only `view`.
 * Inviting a teammate changes who can reach the tenant, so it is deliberately NOT a member-tier act;
 * that is the same line {@see \Splicewire\Beam\Accounts\Authorization\MembershipPolicy::manageInvitations()}
 * already drew, and the two now agree by construction rather than by coincidence.
 *
 * ⚠️ The permission tokens are DERIVED from `Gate::policies()`, so this attribute is the only edit
 * a new policed model needs — but existing teams' roles are synced at role-CREATION time, so a host
 * that already has teams must run `splicewire:beam:seed` (→ {@see \Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder})
 * once for the new tokens to land. Until it does, the resource stays refused rather than open.
 *
 * The DTO's own `prepare()` owner/admin `abort_unless` stays where it is: it is a second, narrower
 * statement that also covers a host which retiers `beam.accounts.roles.abilities` to hand `create`
 * to members, and it names the team explicitly where the token rung is team-agnostic.
 */
#[UseCascadePolicy]
class Invitation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'accepted_at' => 'datetime',
        // `team_id` holds a {@see TeamContract} key, and that contract declares it `int|string`. The
        // column is therefore a plain string (see `create_invitations_table.php.stub`), and the cast
        // is what stops the widening from buying a silent-coercion bug in exchange: without it a
        // bigint-keyed host reads back `5` and a string-keyed host reads back `"5"`, so `===` against
        // `teamKey()` would be true at one host and false at another for the same team.
        'team_id' => 'string',
    ];

    /**
     * The single-use accept `token` is a bearer secret — anyone holding it can join the team. Hidden
     * from every array/JSON serialization so it cannot ride the wire off an unguarded projection.
     * The accept flow queries by token in a WHERE clause and never serializes it.
     */
    protected $hidden = [
        'token',
    ];

    /**
     * `invitations` → `beam_invitations`, via the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename ticket 04).
     */
    public function getTable(): string
    {
        return Beam::table('invitations');
    }

    /**
     * The connection `beam_invitations` lives on — the seam that lets this model serve a TENANTED
     * host, and the last thing `TenantInvitation` had that this model did not.
     *
     * An invitation is prospective membership: it is issued to an email before the invitee has any
     * tenant context, and the accept flow resolves its token on a route with no tenancy middleware
     * at all. So at a tenanted host it MUST live on the shared central database — the table exists
     * in every tenant schema too (the stub is `shared/`), and a send that wrote the tenant copy
     * while accept read the central one would mint invitations nobody could redeem, silently and
     * behind a 201.
     *
     * ⚠️ A config key rather than `protected $connection = 'central'`, for exactly the reason
     * `beam.accounts.tokens.connection` states in-file: a hardcoded pin is unoverridable off the
     * host it was written for, and the majority of this package's hosts are single-database and
     * have no `central` connection to name. null = the app default, which is the only answer a
     * non-tenanted host can use.
     */
    public function getConnectionName(): ?string
    {
        return config('beam.accounts.invitations.connection') ?: parent::getConnectionName();
    }

    /**
     * The owning team AS BEAM'S OWN `Team`.
     *
     * ⚠️ Correct only where the host's team notion IS {@see Team}. `team_id` is a `TeamContract` key
     * and the contract has more than one implementation, so a host that binds
     * `beam.accounts.teams.resolver` to something else (beam-tenancy's `Tenant`) must read its team
     * through that resolver, not through this relation — which would look for a `beam_teams` row
     * that does not exist and answer null. Kept because it is the reference implementation's
     * relation and because nothing else in the package reads it; deliberately NOT widened into a
     * morph, since a host has exactly one team notion and a discriminator that never discriminates
     * buys only a forgery axis (see the stub's note).
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The user who sent the invitation, resolved through the configured Authenticatable rather than
     * an imported host class.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(BeamAccounts::userModel(), 'invited_by');
    }
}
