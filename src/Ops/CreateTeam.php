<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Data\CreateTeamInputData;
use Splicewire\Beam\Accounts\Data\TeamData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * `POST teams/create` (`teams.store`) — self-service team creation: the signed-in principal starts a
 * team, becomes its Owner, and it becomes their current team. Minted with
 * `splicewire:beam:make:particle-op CreateTeam --resource=teams --op=create`, then filled in.
 *
 * This is how a user with NO team gets onto one without an invitation: a fresh registration at a beam
 * starter provisions no personal team, so its dashboard is `DashboardWelcome`'s first-run row, whose
 * "Create a team" link is the `teams.create` page that posts here.
 *
 * ## `subject: NoSubject` — there is no team yet
 *
 * The op creates its subject; there is nothing to resolve. So it mounts at the flat `teams/create`
 * (no `{id}` coordinate) — the same URI as the `teams.create` page, on POST. The `teams` resource is
 * `readOnly: true` and stays so: this is an operation beside it, not a generic create opened on it,
 * because the Owner membership, the role sync and the current-team switch are not a row write.
 *
 * ## `ability: false` — any signed-in principal may start a team
 *
 * Declared ungated on purpose, not left undeclared. The mount's `auth` middleware is the control (you
 * must be someone), and a first-run user holds no team role or entitlement anything could be checked
 * against — every ability this estate has would deny exactly the caller the operation exists for. A host
 * that wants to meter team creation (plans, quotas) puts its own middleware on the mount.
 *
 * ## Output and transport
 *
 * The payload is the new team as {@see TeamData}, the `teams` resource's own read projection. A JSON
 * caller gets it enveloped; the Inertia form is redirected to the team page instead
 * (`beam.accounts.teams.created_redirect`, default `account.team`, falling back to `dashboard`) — the
 * pass-through `ParticleOperationController::finish()` gives an already-built response.
 */
#[ParticleOp(
    resource: 'teams',
    name: 'create',
    kind: OperationKind::Write,
    ability: false,
    input: CreateTeamInputData::class,
    output: TeamData::class,
    subject: NoSubject::class,
)]
class CreateTeam
{
    public static function handle(mixed $model, Request $request, mixed $actor): mixed
    {
        // The declared `input:` has already been validated by the controller; hydrate it and do the work.
        $input = CreateTeamInputData::from($request->all());

        abort_if($actor === null, 401);

        // No beam team model here (the host's team is its tenant): the op is not mounted by
        // `routes/teams.php`, and a host that mounts it anyway gets a 404, not a provisioner failure.
        abort_if(BeamAccounts::teamModel() === null, 404);

        $team = app(TeamProvisioner::class)->createTeamFor($actor, trim($input->name));

        return $request->expectsJson()
            ? TeamData::project($team)
            : redirect()->to(self::landing())->with('status', 'team-created');
    }

    /** Where the new owner lands: the team page, where they can invite their first teammate. */
    public static function landing(): string
    {
        foreach ([config('beam.accounts.teams.created_redirect', 'account.team'), 'dashboard'] as $target) {
            if (is_string($target) && $target !== '' && Route::has($target)) {
                return route($target);
            }
        }

        return '/';
    }
}
