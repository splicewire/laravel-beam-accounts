<?php

namespace Splicewire\Beam\Accounts\Notify;

use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Notifications\Contracts\AccountsDirectory;

/**
 * This package's implementation of beam-notifications' `AccountsDirectory` port — the answer to "who
 * holds this role" / "who is in this team", in notifiable terms and nothing else (beam-facade 100/159).
 *
 * ## Memberships, not spatie roles — and that is a correction, not a preference
 *
 * `to_roles:` names the MEMBERSHIP vocabulary ({@see Role}: `owner`, `admin`, `member`), read off
 * `beam_memberships.role`, which is the source of truth. It deliberately does NOT query spatie's
 * `roles`, even though a team-scoped spatie role of the identical name exists beside every membership.
 *
 * beam-facade 100 measured why: {@see \Splicewire\Beam\Accounts\Teams\TeamProvisioner::syncSpatieRole()}
 * ends in `syncRoles([$roleModel])`, which is REPLACE-ALL. A hand-assigned extra spatie role — the
 * obvious way to spell `to_roles: ['support']` today — is silently wiped by the next membership write
 * in that team: a role change, a re-invite, even an idempotent `addMember`. So resolving against spatie
 * would work, and then quietly stop the first time somebody edited a membership. Host-defined roles
 * arrive properly when the membership role enum becomes a registry (beam-facade 156), and this class
 * needs no change when they do — it already reads whatever `memberships.role` holds.
 *
 * ## Teams by SLUG
 *
 * A `to_teams:` selector is authored by hand into a JSON Schema that travels between hosts, so it names
 * the team's globally-unique slug rather than an auto-increment key (100 D5). An unknown slug resolves
 * to nobody; the caller turns that into the fault.
 *
 * ## Scope is the connection (100 D4)
 *
 * Both reads are unscoped within the current connection. A multi-tenant host is isolated by
 * construction — stancl swaps the default connection before any of this runs — and a single-DB host
 * resolves across its one database. No ambient "current team" is consulted: an `in_team:` scope
 * MODIFIER is the declared growth path, and simulating one from `current_team_id` here would make a
 * notification's audience depend on whoever happened to be logged in when the record was written.
 */
class MembershipDirectory implements AccountsDirectory
{
    public function membersOfRole(string $role): array
    {
        return $this->usersKeyed(
            Membership::query()->where('role', $role)->pluck('user_id')->all()
        );
    }

    public function membersOfTeam(string $team): array
    {
        $key = Team::query()->where('slug', $team)->value('id');

        if ($key === null) {
            return [];
        }

        return $this->usersKeyed(
            Membership::query()->where('team_id', $key)->pluck('user_id')->all()
        );
    }

    /**
     * The host's user models for a set of membership user ids, deduped.
     *
     * Through {@see BeamAccounts::userModel()} rather than this package's own `User`, because the model
     * a host authenticates with is the one that has to be notifiable — and every host in the estate
     * binds its own.
     *
     * @param  list<mixed>  $userIds
     * @return list<object>
     */
    protected function usersKeyed(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, fn (mixed $id): bool => $id !== null)));

        if ($userIds === []) {
            return [];
        }

        $model = BeamAccounts::userModel();

        return array_values($model::query()->whereKey($userIds)->get()->all());
    }
}
