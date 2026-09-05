<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Support\CascadePolicyRegistrar;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Authorization\RolePermissions;
use Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\PolicedWidget;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The out-of-the-box denial these lock: `BaseModelPolicy::viewAny()` asks `$user->can('<alias>.view')`,
 * nothing in the family ever wrote a permission row, so at `~/Herd/beam` on 2026-09-05 the demo
 * team's OWNER was denied `viewAny` on every cascade-policed resource and the tenant nav rendered
 * exactly one row.
 */
beforeEach(function () {
    Relation::morphMap(['policed-widget' => PolicedWidget::class]);
    CascadePolicyRegistrar::register(PolicedWidget::class);
});

it('derives the policed model set from the gate rather than a hand-kept list', function () {
    expect(app(RolePermissions::class)->policedModels())->toContain(PolicedWidget::class);
});

it('gives owner, admin and member DIFFERENT abilities', function () {
    $perms = app(RolePermissions::class);

    expect($perms->abilitiesFor(Role::Owner))->toContain('force-delete')
        ->and($perms->abilitiesFor(Role::Admin))->toContain('update')
        ->and($perms->abilitiesFor(Role::Admin))->not->toContain('force-delete')
        ->and($perms->abilitiesFor(Role::Member))->toBe(['view'])
        ->and($perms->abilitiesFor('no-such-role'))->toBe([]);
});

it('crosses every policed model with the role abilities to build tokens', function () {
    $tokens = app(RolePermissions::class)->tokensFor(Role::Member);

    expect($tokens)->toContain('policed-widget.view')
        ->and($tokens)->not->toContain('policed-widget.delete');
});

it('lets a host retier a role through config without touching the class', function () {
    config()->set('beam.accounts.roles.abilities', ['member' => ['view', 'update']]);

    expect(app(RolePermissions::class)->tokensFor(Role::Member))
        ->toContain('policed-widget.update');
});

/**
 * The end-to-end assertion, and the one that would have caught the live defect: provisioning a team
 * must leave its OWNER able to `viewAny` a policed model. Asserted through the Gate (what the nav
 * actually asks), not through `$user->can` on a token string.
 */
it('leaves a freshly provisioned team owner able to viewAny a policed resource', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $team = app(TeamProvisioner::class)->personalTeamFor($owner);

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $owner->unsetRelation('roles')->unsetRelation('permissions');

    Auth::setUser($owner);

    expect(Gate::forUser($owner)->allows('viewAny', PolicedWidget::class))->toBeTrue();
});

it('denies write abilities to a member while still letting them read', function () {
    $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-1234']);
    $member = User::create(['name' => 'Mem', 'email' => 'mem@example.test', 'password' => 'password-1234']);

    $team = app(TeamProvisioner::class)->personalTeamFor($owner);
    app(TeamProvisioner::class)->addMember($member, $team, Role::Member);

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $member->unsetRelation('roles')->unsetRelation('permissions');

    expect(Gate::forUser($member)->allows('viewAny', PolicedWidget::class))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', PolicedWidget::class))->toBeFalse();
});

it('converges on re-run rather than accumulating stale tokens', function () {
    $owner = User::create(['name' => 'Ida', 'email' => 'ida@example.test', 'password' => 'password-1234']);
    $team = app(TeamProvisioner::class)->personalTeamFor($owner);

    $roleModel = app(config('permission.models.role'))
        ->where('team_id', $team->getKey())->where('name', Role::Owner->value)->firstOrFail();

    $before = $roleModel->permissions()->count();
    expect($before)->toBeGreaterThan(0);

    // A host retiers the role down; the sync must REMOVE, not merely add.
    config()->set('beam.accounts.roles.abilities', ['owner' => ['view']]);
    app(RolePermissions::class)->syncTo($roleModel, Role::Owner);

    expect($roleModel->fresh()->permissions()->count())->toBeLessThan($before);
});

/**
 * The backfill half. Every beam host in existence provisioned its teams before any permission row
 * was written, so the provisioner hook alone leaves them denied — measured at `~/Herd/beam`, where
 * the two demo teams came back with 30 tokens each and two earlier personal teams still had 0.
 */
it('backfills a role row that was provisioned before permissions existed', function () {
    $owner = User::create(['name' => 'Legacy', 'email' => 'legacy@example.test', 'password' => 'password-1234']);
    $team = app(TeamProvisioner::class)->personalTeamFor($owner);

    $roleModel = app(config('permission.models.role'))
        ->where('team_id', $team->getKey())->where('name', Role::Owner->value)->firstOrFail();

    // Strip it back to the pre-2026-09-05 state: a role row holding nothing.
    $roleModel->permissions()->detach();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($roleModel->fresh()->permissions()->count())->toBe(0);

    app(RolePermissionsSeeder::class)->run();

    expect($roleModel->fresh()->permissions()->count())->toBeGreaterThan(0);
});

it('leaves a role the host defined itself alone', function () {
    $bespoke = app(config('permission.models.role'))::findOrCreate('data-steward', 'web');
    $bespoke->syncPermissions([app(config('permission.models.permission'))::findOrCreate('bespoke.thing', 'web')]);

    app(RolePermissionsSeeder::class)->run();

    expect($bespoke->fresh()->permissions()->pluck('name')->all())->toBe(['bespoke.thing']);
});
