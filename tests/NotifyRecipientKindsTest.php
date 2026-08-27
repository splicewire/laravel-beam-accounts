<?php

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Notify\MembershipDirectory;
use Splicewire\Beam\Accounts\Notify\RolesRecipientKind;
use Splicewire\Beam\Accounts\Notify\TeamsRecipientKind;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Notifications\Contracts\AccountsDirectory;
use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Recipients\Kinds\AddressRecipientKind;
use Splicewire\Beam\Notifications\Recipients\NoRecipientsForKind;

/**
 * This package's half of beam-facade 159: the `to_roles:` / `to_teams:` recipient kinds and the
 * `AccountsDirectory` they resolve through.
 *
 * These are the tests that did not exist for two years. beam-notifications declared the seam and
 * proved role resolution with a fixture INSIDE its own suite, so its suite was green while
 * `to_roles:` threw at every host in the estate (beam-facade 100/79). The kinds live here now, against
 * real memberships, and the fixture is gone.
 */
function member(string $email, string $name = 'Member'): User
{
    return User::create(['name' => $name, 'email' => $email, 'password' => 'secret']);
}

function teamWith(User $owner, string $name, array $members = []): Team
{
    $provisioner = app(TeamProvisioner::class);
    $team = Team::create(['user_id' => $owner->getKey(), 'name' => $name, 'personal_team' => false]);

    $provisioner->addMember($owner, $team, Role::Owner);

    foreach ($members as $member => $role) {
        $provisioner->addMember($members[$member] ?? $member, $team, $role);
    }

    return $team;
}

it('binds the AccountsDirectory port to the memberships-backed implementation', function () {
    expect(app(AccountsDirectory::class))->toBeInstanceOf(MembershipDirectory::class);
});

it('registers to_roles and to_teams into the notify registry without either package probing the other', function () {
    expect(config('beam.notifications.recipient_kinds.to_roles'))->toBe(RolesRecipientKind::class)
        ->and(config('beam.notifications.recipient_kinds.to_teams'))->toBe(TeamsRecipientKind::class);
});

it('APPENDS rather than assigning, so beam-notifications own `to` kind survives', function () {
    // The failure this guards is silent: `config()->set('…recipient_kinds', [...])` would leave the
    // keyword with roles and teams and no `to:` at all, and nothing would say so until a schema that
    // mails a literal address stopped mailing it.
    //
    // Stated against the concern rather than against a booted notify package, because the notify
    // package is not in this harness — and putting it there would prove the wrong thing anyway: its
    // config merge happens at REGISTER time, so any ordering a test could construct by registering it
    // late is an ordering no host has (see WiresNotifyRecipients on why the append lives in boot).
    config()->set('beam.notifications.recipient_kinds', ['to' => AddressRecipientKind::class]);

    (fn () => $this->bootNotifyRecipients())->call(app()->getProvider(BeamAccountsServiceProvider::class));

    expect(config('beam.notifications.recipient_kinds'))->toHaveKeys(['to', 'to_roles', 'to_teams']);
});

it('resolves to_roles: to every member holding that membership role', function () {
    $owner = member('owner@site.test', 'Owner');
    $admin = member('admin@site.test', 'Admin');

    $team = teamWith($owner, 'Acme');
    app(TeamProvisioner::class)->addMember($admin, $team, Role::Admin);

    $recipients = app(DefaultRecipientResolver::class)->resolve(['to_roles' => ['admin']], []);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->isNotifiable())->toBeTrue()
        ->and($recipients[0]->notifiable->email)->toBe('admin@site.test');
});

it('reads memberships.role, NOT spatie roles — the vocabulary syncRoles() would wipe', function () {
    $owner = member('owner@site.test', 'Owner');
    $team = teamWith($owner, 'Acme');

    // A hand-assigned spatie role, the obvious way to spell `to_roles: ['support']` today. It is not
    // a membership, so it names nobody here — and TeamProvisioner::syncSpatieRole()'s replace-all
    // syncRoles() would silently wipe it on the next membership write anyway (beam-facade 156).
    expect(fn () => app(DefaultRecipientResolver::class)->resolve(['to_roles' => ['support']], []))
        ->toThrow(NoRecipientsForKind::class);
});

it('resolves to_teams: by SLUG to every member of that team', function () {
    $owner = member('owner@site.test', 'Owner');
    $second = member('second@site.test', 'Second');

    $team = teamWith($owner, 'Acme Support');
    app(TeamProvisioner::class)->addMember($second, $team, Role::Member);

    expect($team->slug)->toBe('acme-support');

    $recipients = app(DefaultRecipientResolver::class)->resolve(['to_teams' => [$team->slug]], []);

    expect($recipients)->toHaveCount(2)
        ->and(collect($recipients)->map(fn ($r) => $r->notifiable->email)->sort()->values()->all())
        ->toBe(['owner@site.test', 'second@site.test']);
});

it('throws on an unknown team slug rather than notifying nobody', function () {
    expect(fn () => app(DefaultRecipientResolver::class)->resolve(['to_teams' => ['no-such-team']], []))
        ->toThrow(NoRecipientsForKind::class);
});

it('dedupes a member who holds the same role in two teams', function () {
    $owner = member('owner@site.test', 'Owner');
    $admin = member('admin@site.test', 'Admin');

    foreach (['One', 'Two'] as $name) {
        $team = teamWith($owner, $name);
        app(TeamProvisioner::class)->addMember($admin, $team, Role::Admin);
    }

    expect(app(MembershipDirectory::class)->membersOfRole('admin'))->toHaveCount(1);
});

it('scopes to the connection and nothing narrower — no ambient current-team filtering', function () {
    // 100 D4. A `to_roles:` audience must not depend on who happened to be logged in when the record
    // was written, so the directory reads every membership in the database regardless of
    // `current_team_id`.
    $owner = member('owner@site.test', 'Owner');
    $other = member('other@site.test', 'Other');

    teamWith($owner, 'One');
    teamWith($other, 'Two');

    expect(app(MembershipDirectory::class)->membersOfRole('owner'))->toHaveCount(2);
});
