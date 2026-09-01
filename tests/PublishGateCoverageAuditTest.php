<?php

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Doctor\PublishGateCoverageAudit;

/**
 * Turning a publish gate off ASSERTS the estate is already committed on this host's disk. Nothing
 * checked that until beam-docs-satellite ticket 25: `laravel-tower-starter` turned the gate off and a
 * later commit deleted two of the files it was claiming to have, which stayed invisible until a fresh
 * clone could not migrate at all.
 *
 * The fixture writes real files into a temp `database/migrations` tree rather than mocking the
 * filesystem, because the thing under test is precisely "is the file on disk" — a mocked answer would
 * be the test agreeing with itself, which is the failure mode this whole ticket kept finding.
 */
function seedCommitted(array $stems): string
{
    $root = sys_get_temp_dir().'/pgca-'.bin2hex(random_bytes(6)).'/database/migrations';

    foreach ($stems as $stem) {
        $path = $root.'/'.dirname($stem);
        @mkdir($path, 0777, true);
        // A real published copy carries an install-time stamp; the audit must match on the stem alone.
        file_put_contents($path.'/2026_08_12_181506_'.basename($stem).'.php', '<?php // fixture');
    }

    @mkdir($root, 0777, true);

    return dirname(dirname($root));
}

function runAudit(string $basePath): array
{
    app()->setBasePath($basePath);

    return (new PublishGateCoverageAudit)->run();
}

it('passes when both gates are on, without looking at the disk at all', function () {
    config([
        'beam.accounts.publish_auth_migrations' => true,
        'beam.accounts.publish_migrations' => true,
    ]);

    $findings = runAudit(seedCommitted([]));

    expect($findings)->toHaveCount(1);
    expect($findings[0]->status)->toBe(DoctorStatus::Pass);
});

it('fails when a gate is off and the estate is not committed', function () {
    config(['beam.accounts.publish_auth_migrations' => false]);

    $findings = runAudit(seedCommitted([]));

    $fails = array_values(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Fail));

    expect($fails)->toHaveCount(1);
    expect($fails[0]->detail)->toContain('publish_auth_migrations');
    expect($fails[0]->detail)->toContain('create_users_table');
});

it('passes when a gate is off and every member IS committed, ignoring the timestamp prefix', function () {
    config(['beam.accounts.publish_auth_migrations' => false]);

    $findings = runAudit(seedCommitted(BeamAccountsServiceProvider::gatedEstates()['auth_migrations']));

    expect(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Fail))->toBeEmpty();
});

/**
 * The exact tower shape: gate off, and the two files `420f9e0` deleted are the only ones missing. This
 * is the regression the audit exists for, so it is asserted as a whole rather than as "some failure".
 */
it('names exactly the members tower deleted', function () {
    config(['beam.accounts.publish_auth_migrations' => false]);

    $estate = BeamAccountsServiceProvider::gatedEstates()['auth_migrations'];
    $present = array_values(array_filter(
        $estate,
        fn (string $s) => ! in_array(basename($s), ['create_users_table', 'create_passkeys_table'], true),
    ));

    $findings = runAudit(seedCommitted($present));
    $fails = array_values(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Fail));

    expect($fails)->toHaveCount(1);
    expect($fails[0]->detail)->toContain('create_users_table');
    expect($fails[0]->detail)->toContain('create_passkeys_table');
    expect($fails[0]->detail)->toContain('2 of '.count($estate));
});

it('honours the deprecated register_* key when deciding whether to check', function () {
    config(['beam.accounts.register_auth_migrations' => false]);

    $fails = array_values(array_filter(runAudit(seedCommitted([])), fn ($f) => $f->status === DoctorStatus::Fail));

    expect($fails)->toHaveCount(1);
});

/**
 * ⚠️ The finding used to name `publish_*` unconditionally, while the gate is the AND of that and the
 * legacy `register_*`. So a host held shut by the legacy key was told to look at a key that reads
 * `true` there — and `beam-facade` 155 spent a whole section re-establishing that by hand, predicting
 * in writing that "an agent acting on the FAIL text would grep `publish_migrations` at this host" and
 * find nothing. A finding that misnames its own subject sends every reader to the wrong file.
 */
it('names the key that is actually off, not the modern spelling of it', function () {
    config([
        'beam.accounts.publish_auth_migrations' => true,   // on — must NOT be blamed
        'beam.accounts.register_auth_migrations' => false, // the legacy key is what is shut
    ]);

    $fails = array_values(array_filter(runAudit(seedCommitted([])), fn ($f) => $f->status === DoctorStatus::Fail));

    expect($fails)->toHaveCount(1)
        ->and($fails[0]->detail)->toContain('`beam.accounts.register_auth_migrations` is off')
        ->and($fails[0]->detail)->not->toContain('`beam.accounts.publish_auth_migrations` is off');
});

it('names both keys when a host has shut both, rather than picking one', function () {
    config([
        'beam.accounts.publish_auth_migrations' => false,
        'beam.accounts.register_auth_migrations' => false,
    ]);

    $fails = array_values(array_filter(runAudit(seedCommitted([])), fn ($f) => $f->status === DoctorStatus::Fail));

    expect($fails[0]->detail)->toContain('`beam.accounts.publish_auth_migrations` and `beam.accounts.register_auth_migrations`');
});

it('reports the same closed keys the gate itself consulted', function () {
    // Identity between the predicate and the message: the audit must not re-derive "which key" by a
    // second route that could drift from the one publishesEstateNamed() actually used.
    config(['beam.accounts.register_migrations' => false]);

    expect(BeamAccountsServiceProvider::publishesEstateNamed('migrations'))->toBeFalse()
        ->and(BeamAccountsServiceProvider::closedGateKeysFor('migrations'))->toBe(['beam.accounts.register_migrations'])
        ->and(BeamAccountsServiceProvider::closedGateKeysFor('auth_migrations'))->toBe([]);
});

/**
 * ## The third state
 *
 * `false` says "already committed here" and is a claim. `'absent'` says "this estate has no place
 * here" and is a different claim. Before 2026-09-01 they were spelled the same, so `splicewire-app` —
 * which must never create `beam_teams`/`beam_memberships`, and had said so in its own config docblock
 * for months — failed its own build, with both printed remedies being things it must not do.
 *
 * The tests below are in two halves, and the second half is the load-bearing one: the third state must
 * be UNREACHABLE BY OMISSION, or it is not a third state, it is a mute button on the audit.
 */
it('passes when an estate is declared absent and is absent, and says how it knows', function () {
    config(['beam.accounts.publish_migrations' => 'absent']);

    $findings = runAudit(seedCommitted([]));

    expect(array_filter($findings, fn ($f) => $f->status !== DoctorStatus::Pass))->toBeEmpty()
        ->and($findings[0]->detail)->toContain('deliberately absent')
        ->and($findings[0]->detail)->toContain('0 of 8')
        ->and($findings[0]->detail)->toContain('`beam.accounts.publish_migrations`');
});

/**
 * `'absent'` is a truthy string, so a `(bool)` cast anywhere in the gate would silently RE-OPEN the
 * publish it was written to close — the one bug that would make this state worse than not having it.
 */
it('closes the publish gate rather than re-opening it on the truthy string', function () {
    config(['beam.accounts.publish_migrations' => 'absent']);

    expect(BeamAccountsServiceProvider::publishesEstateNamed('migrations'))->toBeFalse()
        ->and(BeamAccountsServiceProvider::estateDeclaredAbsent('migrations'))->toBeTrue()
        ->and(BeamAccountsServiceProvider::closedGateKeysFor('migrations'))->toBe(['beam.accounts.publish_migrations']);
});

it('reads the third state through the deprecated register_* spelling too', function () {
    config(['beam.accounts.register_migrations' => 'absent']);

    expect(BeamAccountsServiceProvider::estateDeclaredAbsent('migrations'))->toBeTrue()
        ->and(array_filter(runAudit(seedCommitted([])), fn ($f) => $f->status !== DoctorStatus::Pass))->toBeEmpty();
});

it('tolerates the casing and whitespace an env var arrives with', function () {
    config(['beam.accounts.publish_migrations' => ' Absent ']);

    expect(BeamAccountsServiceProvider::estateDeclaredAbsent('migrations'))->toBeTrue();
});

/**
 * Absence is a claim too, so it is checked — but only to WARN. `laravel-beam-starter` owns its own
 * bigint `create_users_table` / `create_permission_tables` / `create_passkeys_table`, whose stems
 * collide exactly with three members of an auth estate it wants nothing to do with. A stem match
 * cannot tell that from a leftover partial publish, and a check whose answer depends on the host must
 * not throw.
 */
it('warns, never fails, when a host declaring an estate absent has member stems committed anyway', function () {
    config(['beam.accounts.publish_auth_migrations' => 'absent']);

    $findings = runAudit(seedCommitted(['shared/create_users_table', 'create_passkeys_table']));

    expect(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Fail))->toBeEmpty()
        ->and($findings[0]->status)->toBe(DoctorStatus::Warn)
        ->and($findings[0]->detail)->toContain('2 of 9')
        ->and($findings[0]->detail)->toContain('create_users_table');
});

/**
 * ⚠️ THE REGRESSION THAT MATTERS. Tower's `420f9e0` shape — gate off, files deleted — must still fail,
 * and so must every route a host can reach WITHOUT typing the word: an unset env var (`null`), an empty
 * one (`''`), and a plain `false`. If any of these excused the estate, the third state would have
 * quietly retired the audit instead of completing it.
 */
it('still fails every spelling that is NOT the third state', function (mixed $value) {
    config(['beam.accounts.publish_auth_migrations' => $value]);

    $fails = array_values(array_filter(runAudit(seedCommitted([])), fn ($f) => $f->status === DoctorStatus::Fail));

    expect(BeamAccountsServiceProvider::estateDeclaredAbsent('auth_migrations'))->toBeFalse()
        ->and($fails)->toHaveCount(1)
        ->and($fails[0]->detail)->toContain('9 of 9');
})->with([
    'the tower shape' => false,
    'an unset env var' => null,
    'an empty env var' => '',
    'a string zero' => '0',
]);

/** A word that is not the word buys nothing — the third state is one spelling, not "anything truthy". */
it('does not accept a near-miss word as the third state', function () {
    config(['beam.accounts.publish_auth_migrations' => 'absent-ish']);

    expect(BeamAccountsServiceProvider::estateDeclaredAbsent('auth_migrations'))->toBeFalse()
        // Truthy and not the word: the gate is OPEN, so this host publishes and is not audited at all.
        ->and(BeamAccountsServiceProvider::publishesEstateNamed('auth_migrations'))->toBeTrue();
});

/** The two estates are independently gated, and the third state must not leak across them. */
it('excuses only the estate that declared itself absent', function () {
    config([
        'beam.accounts.publish_migrations' => 'absent',
        'beam.accounts.publish_auth_migrations' => false, // the tower shape, alongside it
    ]);

    $findings = runAudit(seedCommitted([]));
    $fails = array_values(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Fail));

    expect($fails)->toHaveCount(1)
        ->and($fails[0]->detail)->toContain('publish_auth_migrations')
        ->and($fails[0]->detail)->not->toContain('deliberately absent');
});
