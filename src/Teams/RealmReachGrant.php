<?php

namespace Splicewire\Beam\Accounts\Teams;

use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Sharing\AccessGrants;

/**
 * Grants a Team `manage` on every currently-provisioned realm's root grantable — the DATA that makes
 * an Owner/Admin member of that team `login-as`-verifiable into every staff-gated surface
 * (`/os`/`/operator`/authoring), reproducing the old blanket `is_staff` capability exactly, but as a
 * Team's grants rather than a boolean column (ACC-01).
 *
 * Extracted from `DemoTeamSeeder`'s own original `grantRealmReach()` (which still delegates here) so
 * ANY host can grant an arbitrary team full realm reach — not just the shared "Demo Team" — e.g. a
 * host's own seeded staff/operator accounts, or a `UserFactory` state, without re-deriving this walk.
 *
 * Requires a host-bound `Splicewire\Beam\Accounts\Entitlements\Contracts\RealmGrantable`
 * (`splicewire/laravel-beam-ux`'s is the OOTB implementation) — inert (no-op) when unbound, so a
 * beam-accounts host without beam-ux pays nothing. Also requires that host's realm roots already be
 * provisioned (e.g. `splicewire:beam:ux:seed-nav` run first) — a realm provisioned afterward needs a
 * re-run to pick up its grant (`AccessGrants::share()` is idempotent — `firstOrCreate`).
 */
class RealmReachGrant
{
    public function __construct(private AccessGrants $grants) {}

    public function toTeam(Team $team): void
    {
        $class = config('beam.accounts.entitlements.realm_grantable');

        if ($class === null) {
            return;
        }

        $grantable = app($class);

        foreach ($grantable->provisionedRealms() as $realm) {
            $this->grants->share($grantable->rootFor($realm), $team, AccessGrant::ABILITY_MANAGE);
        }
    }
}
