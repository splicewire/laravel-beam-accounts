<?php

use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * `register_migrations`/`register_auth_migrations` gate what
 * {@see BeamAccountsServiceProvider::configurePackage()} declares via `->hasMigrations()` — i.e.
 * what `vendor:publish --tag=beam-accounts-migrations` (and `splicewire:beam:install`) puts on a
 * host's disk. Turning one off must exclude that estate from the declared list entirely, not just
 * skip auto-loading (which publish-only stubs never do anyway regardless of config) — the
 * regression this test guards against: a host that opted out still got the files dumped on
 * `splicewire:beam:install` (ticket 10, migration-classification-remediation).
 *
 * Calls configurePackage() directly against a fresh Package rather than booting a whole app —
 * Orchestra's `defineEnvironment()` hook fires AFTER providers already register() (so config set
 * there can never reach a register-time read like this one); this is the only way to exercise the
 * exact function the gate lives in.
 */
function declaredMigrations(): array
{
    $provider = new BeamAccountsServiceProvider(app());
    $package = new Package;

    $provider->configurePackage($package);

    return $package->migrationFileNames;
}

it('excludes the teams estate when register_migrations is off', function () {
    config(['beam.accounts.register_migrations' => false]);

    $declared = declaredMigrations();

    foreach ([
        'shared/create_teams_table',
        'shared/create_memberships_table',
        'shared/add_current_team_id_to_users_table',
        'shared/create_invitations_table',
        'shared/create_access_grants_table',
        'shared/create_share_links_table',
        'shared/create_view_requests_table',
    ] as $teamsFile) {
        expect($declared)->not->toContain($teamsFile);
    }

    // The auth estate is untouched by this flag.
    expect($declared)->toContain('shared/create_users_table');
    expect($declared)->toContain('shared/create_permission_tables');
});

it('excludes the auth estate when register_auth_migrations is off', function () {
    config(['beam.accounts.register_auth_migrations' => false]);

    $declared = declaredMigrations();

    foreach ([
        'shared/create_users_table',
        'shared/create_permission_tables',
        'create_passkeys_table',
        'add_provenance_and_archived_to_personal_access_tokens_table',
        'tenant/create_userables_table',
        'tenant/create_guest_tokens_table',
        'tenant/create_sign_offs_table',
        'tenant/rename_userish_to_system_account',
    ] as $authFile) {
        expect($declared)->not->toContain($authFile);
    }

    // The teams estate is untouched by this flag.
    expect($declared)->toContain('shared/create_teams_table');
});

it('declares both estates by default', function () {
    $declared = declaredMigrations();

    expect($declared)->toContain('shared/create_users_table');
    expect($declared)->toContain('shared/create_teams_table');
    expect($declared)->toHaveCount(15);
});
