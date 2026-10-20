<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;

/*
 * Owner re-walk WS-05 (2026-10-19): the package's `/operator` home showed the site owner a developer's note ("basic on
 * purpose… Publish resources/js/pages/operator/dashboard.tsx (vendor:publish …) to eject"). It is now a real operator
 * home: the user, team and pending-invitation counts, and links to the operator pages the host mounts. The eject note
 * is a developer's, so it shows only when the host turns `beam.accounts.operator_shell.developer_note` on: not in
 * production, and not merely because the site runs locally or with debug on, where the owner is the one signed in
 * (review nit). Asserted on the rendered page, the package's interface for this route.
 */

beforeEach(function () {
    $this->operator = User::create(['name' => 'Olive Operator', 'email' => 'olive@example.test', 'password' => 'x']);
    Gate::define('entitlement:os.operate', fn ($user) => $user->getKey() === $this->operator->getKey());
});

/** Production: no local environment and no debug, which is what a site owner meets. */
function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => false]);
}

/** The operator pages a host like the marketing site mounts, plus the ones the home must not link to. */
function mountHostOperatorPages(): void
{
    Route::middleware(['web', 'auth', 'can:entitlement:os.operate'])->group(function () {
        Route::get('/operator/entries', fn () => 'entries')->name('operator.entries');
        Route::get('/operator/admin', fn () => 'admin')->name('operator.admin');
        Route::get('/operator/customers', fn () => 'customers')->name('operator.customers.index');
        Route::get('/operator/users/{user}', fn () => 'user')->name('operator.users.show');
        Route::post('/operator/reindex', fn () => 'ok')->name('operator.reindex');
        Route::get('/api/operator/stats', fn () => [])->name('operator.api.stats');
        Route::get('/operator/debug', fn () => 'debug')->name('operator.debug');
    });
    Route::middleware(['web', 'auth', 'can:billing.manage'])
        ->get('/operator/billing', fn () => 'billing')->name('operator.billing');
    Route::getRoutes()->refreshNameLookups();

    config(['beam.accounts.operator_shell.areas' => ['operator.admin' => 'Everything else', 'operator.debug' => false]]);
}

function seedOperatorFigures(User $owner): void
{
    User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    User::create(['name' => 'Bo', 'email' => 'bo@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'Acme', 'personal_team' => false]);

    Invitation::create(['team_id' => $team->id, 'email' => 'p1@example.test', 'role' => 'member', 'token' => 'pending-1']);
    Invitation::create(['team_id' => $team->id, 'email' => 'p2@example.test', 'role' => 'member', 'token' => 'pending-2']);
    Invitation::create(['team_id' => $team->id, 'email' => 'done@example.test', 'role' => 'member', 'token' => 'accepted', 'accepted_at' => now()]);
}

it('shows a site owner the counts and the operator pages, and no developer note', function () {
    asProduction();
    mountHostOperatorPages();
    seedOperatorFigures($this->operator);

    $page = $this->actingAs($this->operator)->get('/operator')->assertOk()->getContent();

    expect($page)
        ->toContain('<b>3</b><span>Users</span>')
        ->toContain('<b>1</b><span>Teams</span>')
        ->toContain('<b>2</b><span>Pending invitations</span>')
        ->toContain('<a href="/operator/entries">Entries</a>')
        ->toContain('<a href="/operator/admin">Everything else</a>')
        ->toContain('<a href="/operator/customers">Customers</a>');

    // Not linked: a page that needs a record, a write, an API door, one the host hid, one the viewer's gate refuses,
    // and the home itself.
    expect($page)
        ->not->toContain('/operator/users/')
        ->not->toContain('/operator/reindex')
        ->not->toContain('/api/operator/stats')
        ->not->toContain('/operator/debug')
        ->not->toContain('/operator/billing')
        ->not->toContain('href="/operator"');

    // The developer's eject instructions never reach a site owner.
    expect($page)
        ->not->toContain('vendor:publish')
        ->not->toContain('dashboard.tsx')
        ->not->toContain('eject')
        ->not->toContain('basic on purpose')
        ->not->toContain('laravel-beam-accounts')
        ->not->toContain('Developer note');
});

it('does not show the developer note on its own, whether the site runs locally or with debug on', function () {
    // Local, debug on: the owner browsing their own site locally is the operator who is signed in.
    app()->detectEnvironment(fn () => 'local');
    config(['app.debug' => true]);

    expect($this->actingAs($this->operator)->get('/operator')->assertOk()->getContent())
        ->not->toContain('Developer note')
        ->not->toContain('vendor:publish')
        ->not->toContain('dashboard.tsx')
        ->not->toContain('operator_shell');
});

it('shows the developer note when the host turns the flag on, and says it is a developer-only note behind that flag', function () {
    asProduction();
    config(['beam.accounts.operator_shell.developer_note' => true]);

    expect($this->actingAs($this->operator)->get('/operator')->assertOk()->getContent())
        ->toContain('Developer note')
        ->toContain('<code>beam.accounts.operator_shell.developer_note</code>')
        ->toContain('not for operators')
        ->toContain('vendor:publish --tag=beam-accounts-operator-shell')
        ->toContain('resources/js/pages/operator/dashboard.tsx');
});

it('leaves out an operator page whose domain needs a value this home does not have, rather than failing the home', function () {
    asProduction();
    mountHostOperatorPages();
    // A page mounted for a workspace's own host: no link to it can be built from /operator, which is on no workspace.
    Route::domain('{workspace}.example.test')
        ->middleware(['web', 'auth', 'can:entitlement:os.operate'])
        ->get('/operator/workspace-tools', fn () => 'tools')
        ->name('operator.workspace-tools');
    Route::getRoutes()->refreshNameLookups();

    $page = $this->actingAs($this->operator)->get('/operator')->assertOk()->getContent();

    expect($page)
        ->toContain('<a href="/operator/entries">Entries</a>')
        ->toContain('<a href="/operator/customers">Customers</a>')
        ->not->toContain('workspace-tools')
        ->not->toContain('Workspace tools');
});

it('says what belongs under Manage when the host mounts no operator pages', function () {
    asProduction();

    $page = $this->actingAs($this->operator)->get('/operator')->assertOk()->getContent();

    expect($page)
        ->toContain('This site has no operator pages yet. When it adds some, they are listed here.')
        ->not->toContain('class="areas"');
});

it('leaves out a figure this host does not have rather than printing a zero', function () {
    asProduction();
    config(['beam.accounts.teams.model' => false]);
    Schema::drop(Beam::table('invitations'));

    $page = $this->actingAs($this->operator)->get('/operator')->assertOk()->getContent();

    expect($page)
        ->toContain('<b>1</b><span>Users</span>')
        ->not->toContain('<span>Teams</span>')
        ->not->toContain('<span>Pending invitations</span>');
});

it('still refuses someone who cannot operate', function () {
    $member = User::create(['name' => 'Mo', 'email' => 'mo@example.test', 'password' => 'x']);

    $this->actingAs($member)->get('/operator')->assertForbidden();
});

it('hands an ejected page the same figures and links, keeping the props it always read', function () {
    asProduction();
    mountHostOperatorPages();
    seedOperatorFigures($this->operator);

    // The route prefers the host's own page the moment it exists on disk.
    $file = resource_path('js/pages/operator/dashboard.tsx');
    $created = array_values(array_filter(
        [resource_path('js'), resource_path('js/pages'), resource_path('js/pages/operator')],
        fn (string $dir): bool => ! is_dir($dir),
    ));
    File::ensureDirectoryExists(dirname($file));
    File::put($file, 'export default function OperatorDashboard() { return null; }');

    try {
        $this->actingAs($this->operator)
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/operator')
            ->assertOk()
            ->assertJsonPath('component', 'operator/dashboard')
            ->assertJsonPath('props.staff.name', 'Olive Operator')
            ->assertJsonPath('props.staff.email', 'olive@example.test')
            ->assertJsonPath('props.stats.users', 3)
            ->assertJsonPath('props.stats.teams', 1)
            ->assertJsonPath('props.stats.pendingInvitations', 2)
            ->assertJsonPath('props.areas', [
                ['label' => 'Entries', 'href' => '/operator/entries'],
                ['label' => 'Everything else', 'href' => '/operator/admin'],
                ['label' => 'Customers', 'href' => '/operator/customers'],
            ]);
    } finally {
        File::delete($file);
        foreach (array_reverse($created) as $dir) {
            File::deleteDirectory($dir);
        }
    }
});
