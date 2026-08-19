<?php

namespace Splicewire\Beam\Accounts\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\RealmReachGrant;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;

/**
 * Provisions the demo subjects with deterministic credentials, so every satellite has a
 * reproducible way to log in as a known access level and verify its surfaces. Idempotent;
 * skips itself outside development/preview.
 *
 * The roster is **role-derived** ({@see BeamDemo::subjects()} off the {@see Role} enum): the
 * shared "Demo Team" is owned by the Owner-role subject, and every other (invitable) role
 * joins as a member — so adding a `Role` case seeds a new member with zero seeder edits.
 * The `solo` subject models the default team-of-one shape.
 *
 * **No separate "Staff" demo subject exists (ACC-01).** The Demo Team already carries an
 * Owner and an Admin member — exactly the roles {@see Role::grantEligible()} admits — so
 * granting realm-root `manage` onto THIS team (see {@see self::grantRealmReach()}) makes
 * Owner/Admin `login-as`-verifiable into `/os`/`/operator` and Member correctly denied, with
 * zero new roster surface. A Team's reach is data; any team holding the grants behaves the
 * same as the old blanket `is_staff` principal.
 */
class DemoTeamSeeder extends Seeder
{
    public function __construct(protected TeamProvisioner $provisioner, protected RealmReachGrant $reach) {}

    public function run(): void
    {
        if (! BeamDemo::enabled()) {
            $this->command?->warn('beam-accounts: demo subjects skipped (demo affordances disabled in this environment).');

            return;
        }

        $model = BeamAccounts::userModel();
        $password = Hash::make((string) config('beam.accounts.demo.password', 'password'));

        // Every subject in the role-derived roster (+ solo) gets a deterministic account.
        $users = [];
        foreach (BeamDemo::keys() as $key) {
            $users[$key] = $model::query()->firstOrCreate(
                ['email' => BeamDemo::email($key)],
                ['name' => BeamDemo::name($key), 'password' => $password, 'email_verified_at' => now()],
            );
        }

        // One shared team carrying every role, so the role gates can be assumed and
        // compared side by side. Owned by the Owner-role subject; the other shared roles
        // are added as members — the whole membership list is derived from the roster.
        $shared = array_filter(BeamDemo::subjects(), fn (array $subject) => $subject['shared']);
        $ownerKey = Role::Owner->value;

        $team = Team::updateOrCreate(
            ['user_id' => $users[$ownerKey]->getKey(), 'personal_team' => false],
            ['name' => 'Demo Team'],
        );

        foreach ($shared as $key => $subject) {
            $this->provisioner->addMember($users[$key], $team, $subject['role']->value);
            $users[$key]->forceFill(['current_team_id' => $team->getKey()])->save();
        }

        // The solo subject models the default satellite shape: its own team-of-one.
        $this->provisioner->personalTeamFor($users['solo']);

        // ACC-01: grant the Demo Team `manage` on every currently-provisioned realm's root grantable —
        // the DATA that makes Owner/Admin `login-as`-verifiable into `/os`/`/operator` and every
        // authored realm (Member stays denied via {@see Role::grantEligible()}). Extracted into
        // {@see RealmReachGrant} so any host can grant an arbitrary team full realm reach, not just
        // this shared "Demo Team".
        $this->reach->toTeam($team);

        $this->command?->info('beam-accounts: demo subjects ready — '.implode('/', Role::values()).' on "Demo Team", plus solo.');
    }
}
