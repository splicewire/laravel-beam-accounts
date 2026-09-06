<?php

use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Authorization\RolePermissions;
use Splicewire\Beam\Accounts\Authorization\TokenPolicy;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The two write-capable Frame resources this package owns — `invitations` and `tokens` — and the
 * policies that make them reachable again.
 *
 * ## What these lock
 *
 * `schemastud/laravel-frame`'s `ResourceAuthorizer` fails CLOSED on the write axis: a model with no
 * policy refuses `create`/`update`/`delete` to EVERY actor. That is the right posture — permit-by-
 * omission was the defect it replaced — but it made two core out-of-the-box acts impossible at every
 * beam host: "invite a teammate" and "revoke an API token". Measured at `~/Herd/beam` 2026-09-05,
 * with `grep -rln UseCascadePolicy src/Models/` returning nothing in this package.
 *
 * The two models take DIFFERENT answers, and the difference is the point:
 *
 *  - `Invitation` takes the plain tiered `#[UseCascadePolicy]`. Inviting a teammate changes who can
 *    reach the tenant, so it is owner/admin — which is what the seeded tiers already say.
 *  - `PersonalAccessToken` takes {@see TokenPolicy}, whose own-token arm is NOT tiered. A PAT is a
 *    credential, not an authority: its abilities are intersected DOWN by the cascade
 *    ({@see \Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver}), so self-service
 *    escalates nothing, and a member who can see a leaked token but not revoke it is the worst of
 *    the three postures.
 *
 * ## Gate posture
 *
 * ⚠️ **The gate is CLOSED throughout.** Nothing here installs `Gate::before(fn () => true)`, and the
 * denials below — a member refused `create` on an invitation, a peer refused `delete` on someone
 * else's token — only mean something under a closed gate. They are the witness that the checks ran.
 */
// The PAT fixture table is opt-in on the base TestCase (nothing calls it by default), and the
// token half of this file needs it.
beforeEach(function () {
    $this->createPersonalAccessTokensSchema();
});

/** A team owner and a member of the same team, with the registrar pointed at that team. */
function seatPair(): array
{
    $owner = User::create(['name' => 'Owner', 'email' => 'rmp-owner@example.test', 'password' => 'password-1234']);
    $member = User::create(['name' => 'Mem', 'email' => 'rmp-member@example.test', 'password' => 'password-1234']);

    $team = app(TeamProvisioner::class)->personalTeamFor($owner);
    app(TeamProvisioner::class)->addMember($member, $team, Role::Member);

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([$owner, $member] as $user) {
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    return [$owner, $member, $team];
}

/**
 * A PAT row owned by `$user`, minted directly rather than through `HasApiTokens::createToken()` —
 * the fixture User does not carry that trait, and the policy is about the ROW's `tokenable` columns,
 * which is what this writes. Same pair {@see \Splicewire\Beam\Accounts\QueryBuilders\TokensQuery}
 * queries on.
 */
function tokenOwnedBy(object $user, string $name): object
{
    $model = BeamAccounts::tokenModel();

    // `forceFill`, not `create`: the model's `$fillable` deliberately excludes the `tokenable`
    // morph pair (Sanctum stamps it from the tokenable side), and a mass-assign would silently
    // DROP the two columns this whole test is about — an unowned row that every own-arm refuses,
    // which would read as the policy working.
    $token = (new $model)->forceFill([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->getKey(),
        'name' => $name,
        'token' => hash('sha256', $name.'-'.$user->getKey()),
        'abilities' => ['*'],
    ]);

    $token->save();

    return $token;
}

it('gives Invitation a policy at all, which is what unblocks the Frame write axis', function () {
    // `ResourceAuthorizer::allows()` returns false the moment `getPolicyFor()` is null — before it
    // ever asks who is acting. So the non-null-ness is itself the assertion, not a formality.
    expect(Gate::getPolicyFor(Invitation::class))->not->toBeNull();
});

it('lets an owner create and revoke an invitation, and refuses a plain member', function () {
    [$owner, $member, $team] = seatPair();

    $invitation = Invitation::create([
        'team_id' => (string) $team->getKey(),
        'email' => 'invitee@example.test',
        'role' => 'member',
        'token' => 'tok-rmp',
    ]);

    expect(Gate::forUser($owner)->allows('create', Invitation::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $invitation))->toBeTrue()
        // The member tier is `view` only — they may see the pending list, not grow the team.
        ->and(Gate::forUser($member)->allows('viewAny', Invitation::class))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', Invitation::class))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $invitation))->toBeFalse();
});

/**
 * The retired warning, asserted rather than believed. `WiresAuthorization` used to carry a note that
 * `Gate::policy(Invitation::class, …)` must never be added, because a policy would swallow every
 * ability asked about the model — including this `Gate::define`d one — and resolve it to a denial.
 * `ConfiguredModelPolicy`'s `__call()` is gone, so a policy no longer eats an ability it cannot
 * answer, and adding the policy was the correct fix rather than a hazard to route around.
 */
it('does not shadow the manageInvitation ability the policy has no method for', function () {
    [$owner, $member, $team] = seatPair();

    config(['beam.accounts.teams.resolver' => fn (): ?object => $team]);

    $invitation = Invitation::create([
        'team_id' => (string) $team->getKey(),
        'email' => 'shadow@example.test',
        'role' => 'member',
        'token' => 'tok-shadow',
    ]);

    expect(Gate::forUser($owner)->allows('manageInvitation', $invitation))->toBeTrue()
        ->and(Gate::forUser($member)->allows('manageInvitation', $invitation))->toBeFalse();

    config(['beam.accounts.teams.resolver' => null]);
});

it('binds the token policy against the CONFIGURED pat model, not ours by name', function () {
    expect(Gate::getPolicyFor(BeamAccounts::tokenModel()))->toBeInstanceOf(TokenPolicy::class);
});

it('lets any authenticated principal mint and revoke their OWN token, with no tier grant', function () {
    [, $member] = seatPair();

    // Deliberately NOT given any permission token for the PAT model beyond the member tier's `view`.
    // Self-service must not depend on the tiering, or a host that retiers loses it silently.
    $token = tokenOwnedBy($member, 'mine');

    expect(Gate::forUser($member)->allows('viewAny', BeamAccounts::tokenModel()))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', BeamAccounts::tokenModel()))->toBeTrue()
        ->and(Gate::forUser($member)->allows('delete', $token))->toBeTrue();
});

it("refuses a member reaching for ANOTHER user's token", function () {
    [$owner, $member] = seatPair();

    $ownersToken = tokenOwnedBy($owner, 'not-yours');

    // The own-arm answers false, and the member tier holds no `…personalaccesstoken.delete`, so the
    // cascade fallback answers false too. Both arms must fail for this to be a denial.
    expect(Gate::forUser($member)->allows('delete', $ownersToken))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $ownersToken))->toBeFalse();
});

it('derives both new models into the seeded token set with no edit to RolePermissions', function () {
    // The claim `RolePermissions` is built on — the model set comes off `Gate::policies()`, so
    // declaring a policy is the whole change. If this ever fails, the defect is in RolePermissions.
    $models = app(RolePermissions::class)->policedModels();

    expect($models)->toContain(Invitation::class)
        // …and the PAT is deliberately NOT in it. `RolePermissions` crosses its model set with
        // UNIFORM per-role abilities, so membership would hand every member a class-level `view` on
        // a table holding every user's tokens. {@see TokenPolicy} is a plain policy for exactly that
        // reason; this asserts the absence so it reads as a decision, not an oversight.
        ->and($models)->not->toContain(BeamAccounts::tokenModel());

    $ownerTokens = app(RolePermissions::class)->tokensFor(Role::Owner);
    $memberTokens = app(RolePermissions::class)->tokensFor(Role::Member);

    expect($ownerTokens)->toContain('splicewirebeamaccountsmodelsinvitation.create')
        ->and($ownerTokens)->toContain('splicewirebeamaccountsmodelsinvitation.delete')
        ->and($memberTokens)->toContain('splicewirebeamaccountsmodelsinvitation.view')
        ->and($memberTokens)->not->toContain('splicewirebeamaccountsmodelsinvitation.create');
});

/**
 * The advisory half. `ResourceAuthorizer` asks the instance abilities a SECOND time with no id, to
 * decide whether the console should render a Revoke button, and probes a fresh unsaved model to do
 * it. An own-only rule answers false to that by construction, and the console then shows nobody a
 * revoke button — measured at `~/Herd/beam` as the team OWNER, whose `can.delete` was false on the
 * `tokens` block while the DELETE itself returned 204. The two must not disagree.
 */
it('answers the id-less class-level probe yes, so the revoke affordance renders', function () {
    [, $member] = seatPair();

    $model = BeamAccounts::tokenModel();

    expect(Gate::forUser($member)->allows('delete', new $model))->toBeTrue()
        // …while the authoritative, persisted-row answer stays own-only. Both halves, or this
        // assertion is just the widening restated.
        ->and(Gate::forUser($member)->allows('delete', tokenOwnedBy($member, 'own-probe')))->toBeTrue()
        ->and(Gate::forUser($member)->allows('delete', tokenOwnedBy(
            User::create(['name' => 'Other', 'email' => 'rmp-other@example.test', 'password' => 'password-1234']),
            'peer-probe'
        )))->toBeFalse();
});
