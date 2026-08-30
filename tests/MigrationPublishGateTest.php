<?php

use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * `publish_migrations`/`publish_auth_migrations` (renamed from `register_*` at beam-docs-satellite
 * ticket 25 — the old names said "register" while gating publish) gate what
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

it('excludes the teams estate when publish_migrations is off', function () {
    config(['beam.accounts.publish_migrations' => false]);

    $declared = declaredMigrations();

    foreach ([
        'shared/create_teams_table',
        'shared/create_memberships_table',
        'shared/add_current_team_id_to_users_table',
        'shared/create_invitations_table',
        'shared/create_access_grants_table',
        'shared/create_view_requests_table',
    ] as $teamsFile) {
        expect($declared)->not->toContain($teamsFile);
    }

    // The auth estate is untouched by this flag.
    expect($declared)->toContain('shared/create_users_table');
    expect($declared)->toContain('shared/create_permission_tables');
});

it('excludes the auth estate when publish_auth_migrations is off', function () {
    config(['beam.accounts.publish_auth_migrations' => false]);

    $declared = declaredMigrations();

    foreach ([
        'shared/create_users_table',
        'shared/create_permission_tables',
        'create_passkeys_table',
        'create_personal_access_tokens_table',
        'add_provenance_and_archived_to_personal_access_tokens_table',
        'tenant/create_userables_table',
        'tenant/create_guest_tokens_table',
        'tenant/relax_guest_token_landing_url_nullability',
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
    // 16th: shared/create_impersonation_events_table, added with the impersonation lift
    // (particle-identity-resources ticket 03). It rides the TEAMS estate rather than AUTH — it is
    // operator tooling over the team-scoped surfaces, not part of the identity schema a host would
    // turn off when it owns its own auth tables.
    expect($declared)->toContain('shared/create_impersonation_events_table');
    // 17th: create_personal_access_tokens_table (beam-docs-satellite ticket 25). It must precede its
    // own provenance ALTER, which package-tools guarantees by stamping in listed order.
    expect($declared)->toContain('create_personal_access_tokens_table');
    expect(array_search('create_personal_access_tokens_table', $declared, true))
        ->toBeLessThan(array_search('add_provenance_and_archived_to_personal_access_tokens_table', $declared, true));
    // And tenant/relax_guest_token_landing_url_nullability, the ALTER that reaches schemas already
    // migrated when the create stub still declared `landing_url` NOT NULL. It must follow its own
    // create for the same stamping reason as the PAT pair above.
    expect(array_search('tenant/create_guest_tokens_table', $declared, true))
        ->toBeLessThan(array_search('tenant/relax_guest_token_landing_url_nullability', $declared, true));
    expect($declared)->toHaveCount(17);
});

/**
 * The rename has to be non-breaking: a host that published `config/beam/accounts.php` BEFORE ticket 25
 * carries `register_auth_migrations => false` and no `publish_*` key at all. `mergeConfigFrom` merges
 * the package defaults UNDERNEATH that file, so the new key would arrive as `true` and silently
 * re-enable a publish the host deliberately turned off — which is precisely the class of bug this
 * ticket exists to fix, and it would have been introduced by fixing it.
 */
it('still honours the deprecated register_* keys', function () {
    config(['beam.accounts.register_auth_migrations' => false]);

    expect(declaredMigrations())->not->toContain('shared/create_users_table');

    config(['beam.accounts.register_auth_migrations' => true, 'beam.accounts.register_migrations' => false]);

    expect(declaredMigrations())->not->toContain('shared/create_teams_table');
});
