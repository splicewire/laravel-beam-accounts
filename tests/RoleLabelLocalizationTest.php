<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Localization\BeamSchemaText;
use Splicewire\Beam\Localization\DeclaredText;

/*
 * localization 69b: the Role labels ship as catalog lines at the key beam's ladder derives, and the accept-invitation
 * page carries the same label as `roleLabel`, so a translated sentence never names the role in raw English.
 */

const L69B_ENGLISH = ['Owner' => 'Owner', 'Admin' => 'Admin', 'Member' => 'Member'];

function l69bRoleKey(Role $case): string
{
    return 'beam-accounts::enums.Splicewire/Beam/Accounts/Enums/Role.cases.'.$case->name.'.label';
}

/** A French line for one Role case, the way a host's lang/vendor/beam-accounts/fr/enums.php adds it. */
function l69bFrench(string $case, string $label): void
{
    app('translator')->addLines(['enums.Splicewire/Beam/Accounts/Enums/Role.cases.'.$case.'.label' => $label], 'fr', 'beam-accounts');
}

function l69bInvite(string $role): Invitation
{
    $owner = User::create(['name' => 'Olive Owner', 'email' => 'owner@example.test', 'password' => 'password-1234']);
    /** @var Team $team */
    $team = app(TeamProvisioner::class)->createTeamFor($owner, 'Acme');

    return Invitation::create([
        'team_id' => (string) $team->getKey(),
        'email' => 'guest@example.test',
        'role' => $role,
        'token' => str_repeat('b', 32).bin2hex(random_bytes(16)),
        'invited_by' => $owner->getKey(),
    ]);
}

function l69bAcceptPage(Invitation $invitation): Illuminate\Testing\TestResponse
{
    test()->withoutMiddleware(ValidateCsrfToken::class);

    return test()->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->get(app(InvitationRedemption::class)->acceptUrl($invitation))
        ->assertOk()
        ->assertJsonPath('component', 'auth/accept-invitation');
}

it('derives each Role label key in the beam-accounts namespace, and the package catalog answers it', function () {
    foreach (Role::cases() as $case) {
        $key = app(DeclaredText::class)->key('enums', Role::class, 'cases.'.$case->name.'.label');
        expect($key)->toBe(l69bRoleKey($case))
            ->and(app('translator')->has($key, 'en', false))->toBeTrue("no catalog line at {$key}")
            ->and(__($key))->toBe(L69B_ENGLISH[$case->name]);
    }

    // The package's own file holds exactly the enum's cases: a new case without a line is caught here.
    $catalog = require __DIR__.'/../lang/en/enums.php';
    expect(array_keys($catalog['Splicewire/Beam/Accounts/Enums/Role']['cases']))->toBe(array_map(fn (Role $c) => $c->name, Role::cases()));
});

it('keeps every English Role label byte-identical to the declared label', function () {
    foreach (Role::cases() as $case) {
        expect(app(BeamSchemaText::class)->enumCaseLabel($case, $case->label()))
            ->toBe(L69B_ENGLISH[$case->name])
            ->toBe($case->label())
            ->toBe(ucfirst($case->value));
    }
});

it('lets a locale line override one Role label and leaves the others on the English catalog', function () {
    l69bFrench('Admin', 'Administrateur');
    app()->setLocale('fr');

    expect(app(BeamSchemaText::class)->enumCaseLabel(Role::Admin, Role::Admin->label()))->toBe('Administrateur')
        ->and(app(BeamSchemaText::class)->enumCaseLabel(Role::Owner, Role::Owner->label()))->toBe('Owner');
});

it('sends the accept-invitation page the role label beside the raw role', function () {
    l69bAcceptPage(l69bInvite('admin'))
        ->assertJsonPath('props.role', 'admin')
        ->assertJsonPath('props.roleLabel', 'Admin');
});

it('sends the accept-invitation page the overriding locale line as the role label', function () {
    l69bFrench('Member', 'Membre');
    app()->setLocale('fr');

    l69bAcceptPage(l69bInvite('member'))
        ->assertJsonPath('props.role', 'member')
        ->assertJsonPath('props.roleLabel', 'Membre');
});

it('sends no role label for a role string outside the Role enum, so the page derives it from the role', function () {
    $response = l69bAcceptPage(l69bInvite('auditor'))->assertJsonPath('props.role', 'auditor');

    expect($response->json('props'))->toHaveKey('roleLabel')
        ->and($response->json('props.roleLabel'))->toBeNull();
});

it('keeps the English invitation mail line and shows the overriding locale line in it', function () {
    $invitation = l69bInvite('admin');
    $intro = fn () => implode(' ', (new TeamInvitationNotification($invitation, 'https://app.test/accept', 'Acme', 'Olive Owner', '2030-01-01'))
        ->toMail(new Illuminate\Notifications\AnonymousNotifiable)->introLines);

    expect($intro())->toContain('Olive Owner invited you to join Acme as Admin.');

    l69bFrench('Admin', 'Administrateur');
    app()->setLocale('fr');
    expect($intro())->toContain('as Administrateur.');
});
