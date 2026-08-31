<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * An invitation can own the team of ANY {@see TeamContract} implementation, whatever its key type.
 *
 * ## The defect this closes
 *
 * `TeamContract::teamKey()` returns `int|string` and the interface docblock is explicit that it
 * "says nothing about the backing table, key type, or provisioning: those are the host's private
 * seam." `beam_invitations` was the one place in this package that contradicted that — its owner
 * column was `foreignId('team_id')->constrained(Beam::table('teams'))`, which is simultaneously a
 * promise about WIDTH (bigint) and about WHICH TABLE. A host whose team is string-keyed —
 * `splicewire/laravel-beam-tenancy`'s `Tenant`, ids like `system`/`demo` — implements the contract
 * by interface and could not be pointed at by an invitation, which is what forked a duplicate
 * `TenantInvitation`/`tenant_invitations` off this one.
 *
 * ## Why the estate's existing tests could not see it
 *
 * `TeamResolverSeamTest` has been inserting `team_id => 'host-team-1'` into that bigint column and
 * passing, because sqlite is dynamically typed and stores whatever it is handed. The declared-type
 * half of this claim therefore lives in
 * {@see \Splicewire\Beam\Accounts\Tests\FixtureSchemaMatchesShippedStubsTest::test_the_shipped_stubs_do_not_pin_the_invitation_team_key_to_a_bigint()},
 * which reads `Schema::getColumns()` off the shipped stub. What is asserted HERE is the half a
 * schema read cannot see: that both key shapes round-trip WITHOUT SILENT COERCION — the risk the
 * string column buys, and the reason `Invitation` casts the column rather than leaving `5` and
 * `"5"` both reachable.
 */
beforeEach(function () {
    Schema::create('host_teams', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('host_team_users', function (Blueprint $table): void {
        $table->id();
        $table->string('host_team_id');
        $table->uuid('user_id');
        $table->string('role')->default('member');
        $table->timestamp('removed_at')->nullable();
        $table->timestamps();
        $table->unique(['host_team_id', 'user_id']);
    });
});

it('owns a string-keyed host team without coercing its key', function () {
    $team = HostTeam::create(['id' => 'beam_demo', 'name' => 'Demo']);

    $invitation = Invitation::create([
        'team_id' => $team->teamKey(),
        'email' => 'invitee@example.test',
        'role' => 'member',
        'token' => 'tok-string',
    ]);

    $fresh = Invitation::findOrFail($invitation->getKey());

    // The key survives as itself. A bigint column would have silently stored 0 on Postgres or
    // errored outright; the danger of the string column is the opposite one, a key that comes back
    // in a different type than it went in.
    expect($fresh->team_id)->toBe('beam_demo')
        ->and($fresh->team_id)->toBe((string) $team->teamKey());
});

it('still owns a bigint-keyed beam team, read back as one type rather than two', function () {
    $owner = User::create([
        'name' => 'Owner',
        'email' => 'owner-bigint@example.test',
        'password' => 'x',
    ]);

    $team = Team::create(['user_id' => $owner->getKey(), 'name' => 'Bigint Team']);

    $invitation = Invitation::create([
        'team_id' => $team->teamKey(),
        'email' => 'invitee-bigint@example.test',
        'role' => 'member',
        'token' => 'tok-bigint',
    ]);

    $fresh = Invitation::findOrFail($invitation->getKey());

    // `team_id` is cast, so an int key written and a string key written are indistinguishable on
    // read — which is what makes `where('team_id', $key)` a safe comparison for either host.
    expect($fresh->team_id)->toBe((string) $team->getKey())
        ->and($fresh->team_id)->not->toBe($team->getKey());

    // The relation is unchanged for the implementation that always worked.
    expect($fresh->team)->not->toBeNull()
        ->and($fresh->team->getKey())->toBe($team->getKey());
});

it('scopes the invitation list by a string team key without matching a numeric one', function () {
    HostTeam::create(['id' => 'beam_demo', 'name' => 'Demo']);
    config(['beam.accounts.teams.resolver' => fn (): ?object => HostTeam::find('beam_demo')]);

    Invitation::create(['team_id' => 'beam_demo', 'email' => 'mine@example.test', 'role' => 'member', 'token' => 'a']);
    Invitation::create(['team_id' => '1', 'email' => 'theirs@example.test', 'role' => 'member', 'token' => 'b']);

    expect(InvitationData::scope(Invitation::query())->pluck('email')->all())
        ->toBe(['mine@example.test']);

    config(['beam.accounts.teams.resolver' => null]);
});
