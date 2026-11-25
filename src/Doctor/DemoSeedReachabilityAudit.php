<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Facades\BeamDemo;

/**
 * **A demo login door that is OPEN onto an EMPTY roster is the failure this ends.**
 *
 * The one-click demo sign-in ({@see \Splicewire\Beam\Accounts\Actions\DemoLoginLinks}) renders its
 * links from demo SUBJECTS seeded by {@see \Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder}.
 * Those two facts are gated independently and on purpose — the door is `beam.accounts.demo.enabled`
 * on the login path, the subjects are `beam.accounts.demo.seed_users` on the seed path — so a host can
 * reach a state where the door is on and nothing was ever seeded. The door then renders an empty set,
 * silently, and the only symptom is a login page with no demo buttons (or, in a browser test, a
 * `visit(null)` on a null link). Nothing failed; nothing said why.
 *
 * ## The teams-absent trap this exists for (ticket 29)
 *
 * The seed step's gate defers to the teams estate when the host states no opinion ({@see
 * \Splicewire\Beam\Accounts\Concerns\WiresSeed}): a host that declares that estate `'absent'` — it
 * runs its own team system over its own user table — is NOT seeded by default, so existing such hosts
 * are never surprised into fabricating `beam_teams`. But the demo door ships on everywhere but
 * production, so exactly that host can boot with the door open and zero subjects behind it.
 *
 * The remedy is now safe and cheap, and this audit names it: set `ACCOUNT_SEED_DEMO_USERS=true`. Since
 * ticket 29 the seeder provisions the subjects BEFORE its teams-table guard, so an explicit opt-in
 * seeds login-able demo subjects WITHOUT ever creating the teams tables the absent estate forbids.
 *
 * ## Severity: Warn, never Fail
 *
 * The answer depends on the HOST's own intent — a host may deliberately run a demo door with no
 * engine-seeded subjects (it seeds its own, or publishes none) — and the estate's rule is that a
 * check whose verdict turns on host intent reports rather than throws. Production ships the door off,
 * so it reads Pass there and this is never a production alarm.
 */
class DemoSeedReachabilityAudit implements DoctorAudit
{
    public const CHECK = 'beam-accounts.demo-seed-reachability';

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        // The door must be REACHABLE for an empty roster to matter. Production ships it off.
        if (! BeamDemo::enabled()) {
            return [Finding::pass(self::CHECK, 'Demo sign-in is off at this host, so demo-subject seeding is moot.')];
        }

        // The seed step's EFFECTIVE gate, as WiresSeed resolved it in boot and wrote back onto the key.
        if ((bool) config('beam.accounts.demo.seed_users')) {
            return [Finding::pass(self::CHECK, 'Demo sign-in is on and the demo subjects seed at this host, so the links have a roster.')];
        }

        // Door on, subjects off. Name the teams-absent case specifically — it is the one with a safe
        // one-line remedy, and the one a host reaches without ever having chosen it.
        if (BeamAccountsServiceProvider::estateDeclaredAbsent('migrations')) {
            return [Finding::warn(self::CHECK,
                'Demo sign-in is ON, but no demo subject will be seeded here: this host declares the '.
                'teams estate absent and has not set `beam.accounts.demo.seed_users`, so the seed step '.
                'defers to that estate and stays off. The demo login links will be EMPTY. Set '.
                '`ACCOUNT_SEED_DEMO_USERS=true` to seed the team-agnostic demo SUBJECTS — safe on a '.
                'teams-absent host, which the seeder provisions before its teams-table guard and '.
                'without ever creating `beam_teams`. (Leave it off only if this host publishes its own '.
                'demo subjects.)')];
        }

        // Door on, subjects off, estate present: the host turned the key off itself. Still worth
        // saying, because the door is open onto an empty roster — but it is the host's own `false`.
        return [Finding::warn(self::CHECK,
            'Demo sign-in is ON, but `beam.accounts.demo.seed_users` is false here, so no demo subjects '.
            'are seeded and the demo login links will be empty. Set it to true (or unset it) to seed '.
            'the demo subjects, unless this host publishes its own.')];
    }
}
