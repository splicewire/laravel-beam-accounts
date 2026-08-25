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
