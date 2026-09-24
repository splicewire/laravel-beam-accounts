<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;

/**
 * `Invitation`'s write policy: the cascade tokens, OR the team role that already governs invitations.
 *
 * ## Why the cascade alone refused the owner
 *
 * The model's `#[UseCascadePolicy]` answers `create`/`delete` from the `invitation.*` permission tokens,
 * which {@see RolePermissions} derives and syncs onto a team's spatie roles — where the host provisions
 * teams through beam-accounts. A host that seeds its own roles never received those tokens. Measured
 * 2026-09-24 at `~/Herd/splicewire-app` (tenant roles seeded by the host's `PermissionsSeeder`): the tenant
 * OWNER and an Admin both held zero `invitation.*` tokens, so frame's fail-closed write gate refused
 * "invite a teammate" to every principal but Root, while the team role said the owner may.
 *
 * The line an invitation follows was already drawn, once, in {@see MembershipPolicy}: owners and admins
 * manage the current team's invitations (`manageInvitations`), and an existing invitation must belong to
 * that team (`manageInvitation`). The Invitation model's own docblock says the cascade tiers and that
 * line "agree by construction". This class makes that literally so: a principal the cascade grants
 * keeps its grant, and a principal the membership line admits is admitted even where no host synced
 * tokens. Nothing is admitted that `InvitationData::prepare()` / `assertManages()` would then refuse.
 *
 * Every other ability (`viewAny`, `view`, `update`, `restore`, `forceDelete`) is the cascade's, unchanged.
 * It stays under the cascade's container key, so {@see RolePermissions::policedModels()} still counts
 * `Invitation` and a beam-provisioned team keeps receiving its tokens.
 */
class InvitationPolicy extends ConfiguredModelPolicy
{
    public function __construct()
    {
        parent::__construct(Invitation::class);
    }

    public function create(Authenticatable $user)
    {
        return parent::create($user) || $this->managesCurrentTeam($user);
    }

    public function delete(Authenticatable $user, Model $instance)
    {
        if (parent::delete($user, $instance)) {
            return true;
        }

        // An unsaved instance is the class-level probe ("may you revoke invitations at all?"); a persisted
        // one must belong to the team the caller manages.
        return $instance->exists && $instance instanceof Invitation
            ? app(MembershipPolicy::class)->manageInvitation($user, $instance)
            : $this->managesCurrentTeam($user);
    }

    private function managesCurrentTeam(Authenticatable $user): bool
    {
        $team = BeamAccounts::currentTeam();

        return $team instanceof TeamContract && app(MembershipPolicy::class)->manageInvitations($user, $team);
    }
}
