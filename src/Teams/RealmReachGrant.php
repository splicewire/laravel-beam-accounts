<?php

namespace Splicewire\Beam\Accounts\Teams;

use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Sharing\AccessGrants;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Grants a Team `manage` on every registered realm's root grantable — the DATA that makes an
 * Owner/Admin member of that team `login-as`-verifiable into every staff-gated surface
 * (`/operator`/authoring), reproducing the old blanket `is_staff` capability exactly, but as a
 * Team's grants rather than a boolean column (ACC-01).
 *
 * Extracted from `DemoTeamSeeder`'s own original `grantRealmReach()` (which still delegates here) so
 * ANY host can grant an arbitrary team full realm reach — not just the shared "Demo Team" — e.g. a
 * host's own seeded staff/operator accounts, or a `UserFactory` state, without re-deriving this walk.
 *
 * Requires a host-bound `Splicewire\Beam\Accounts\Entitlements\Contracts\RealmGrantable`
 * (`splicewire/laravel-beam-ux`'s is the OOTB implementation) — inert (no-op) when unbound, so a
 * beam-accounts host without beam-ux pays nothing.
 *
 * Realm SET: beam-core's {@see RealmRegistry} (operator/tenant/site/user by default, plus any
 * host-declared `#[Realm]` preset) UNIONED with `$grantable->provisionedRealms()` — not
 * `provisionedRealms()` alone. `BeamUxEntry::rootFor()` auto-provisions a realm root LAZILY, on first
 * ask (typically an author actually visiting that realm's authoring surface) — so on a fresh install,
 * before any author has ever opened one, `provisionedRealms()` returns nothing to grant at all, and a
 * seeded demo/staff team ends up with zero realm reach despite `RealmReachGrant` having run. Since
 * `rootFor()` is find-or-create, unioning in the registered set EAGERLY provisions each realm's root
 * the first time any team is granted reach — closing that gap instead of requiring a host to visit
 * every authoring surface once, or re-run this after the fact, before a seeded operator can log in.
 */
class RealmReachGrant
{
    public function __construct(private AccessGrants $grants, private RealmRegistry $realms) {}

    public function toTeam(Team $team): void
    {
        $class = config('beam.accounts.entitlements.realm_grantable');

        if ($class === null) {
            return;
        }

        $grantable = app($class);

        $realms = array_unique([...array_keys($this->realms->all()), ...$grantable->provisionedRealms()]);

        foreach ($realms as $realm) {
            $this->grants->share($grantable->rootFor($realm), $team, AccessGrant::ABILITY_MANAGE);
        }
    }
}
