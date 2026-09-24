<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\InvitationMailer;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;

/**
 * The self-service team flows (laravel-beam-accounts becf70c) at a host whose team is NOT beam's `Team`
 * — `splicewire/splicewire-app`'s shape: the teams estate declared `'absent'`, so `beam_teams` and
 * `beam_memberships` do not exist, and `beam.accounts.teams.resolver` returns the host's own
 * `TeamContract` ({@see HostTeam}, a foreign-pivot stand-in for the flagship's `Tenant`).
 *
 * becf70c read the invitation's team with a hardcoded `Team::query()`, so every invitation write at
 * the flagship — Frame's and the REST survivor's, both of which now run `InvitationData::afterWrite()`
 * — died on the missing `beam_teams` table (5 flagship tests, 2026-09-24). This harness DROPS the two
 * tables after the base setup builds them, so any query against them is the same error here.
 */
class HostTeamOnboardingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Before boot: `Route::splicewireTeamRoutes()` reads the team model when it mounts.
        $app['config']->set('beam.accounts.publish_migrations', 'absent');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::drop(Beam::table('memberships'));
        Schema::drop(Beam::table('teams'));

        Schema::create('host_teams', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('host_team_users', function (Blueprint $table): void {
            $table->id();
            $table->string('host_team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['host_team_id', 'user_id']);
        });

        $team = HostTeam::create(['id' => 'host-team-1', 'name' => 'Host Team']);
        config(['beam.accounts.teams.resolver' => fn (): ?object => $team]);
    }

    private function hostOwner(): User
    {
        $owner = User::create(['name' => 'Olive Owner', 'email' => 'owner@example.test', 'password' => 'password-1234']);
        HostTeam::query()->findOrFail('host-team-1')->assignMember($owner, Role::Owner);

        return $owner;
    }

    #[Test]
    public function it_derives_no_team_model_and_mounts_none_of_the_team_routes(): void
    {
        $this->assertNull(BeamAccounts::teamModel());

        // The account surface itself still mounts; only the beam-Team flows stand down.
        foreach (['teams.create', 'teams.store', 'invitations.accept', 'invitations.redeem'] as $name) {
            $this->assertFalse(Route::has($name), "route [{$name}] mounted at a host with no beam team");
        }
    }

    #[Test]
    public function it_sends_an_invitation_to_the_host_team_without_touching_beam_teams_or_mailing(): void
    {
        Notification::fake();
        Route::splicewireAccountApiRoutes();
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs($this->hostOwner())
            ->postJson('/beam/accounts/invitations', ['email' => 'new@example.test', 'role' => 'admin'])
            ->assertCreated();

        $invitation = Invitation::query()->where('email', 'new@example.test')->sole();

        $this->assertSame('host-team-1', (string) $invitation->team_id);

        // The Frame writer's path, called directly: the same hook, the same answer.
        InvitationData::afterWrite($invitation, null);

        // The host mails through its own pipeline; beam's would name a team it cannot see.
        Notification::assertNothingSent();
    }

    #[Test]
    public function it_judges_the_link_invalid_and_names_no_team_without_a_query(): void
    {
        $owner = $this->hostOwner();
        $invitation = Invitation::create([
            'team_id' => 'host-team-1',
            'email' => 'new@example.test',
            'role' => 'member',
            'token' => str_repeat('b', 32),
            'invited_by' => $owner->getKey(),
        ]);

        $redemption = app(InvitationRedemption::class);
        $invitee = User::create(['name' => 'New', 'email' => 'new@example.test', 'password' => 'x']);

        $this->assertNull($redemption->team($invitation));
        $this->assertNull($redemption->teamName($invitation));
        $this->assertSame(InvitationRedemption::INVALID, $redemption->verdict($invitation, $invitee));
        $this->assertFalse(app(InvitationMailer::class)->send($invitation));
    }

    #[Test]
    public function it_refuses_to_create_a_beam_team_rather_than_query_a_missing_table(): void
    {
        $user = User::create(['name' => 'Solo', 'email' => 'solo@example.test', 'password' => 'x']);

        $this->expectException(LogicException::class);

        app(TeamProvisioner::class)->createTeamFor($user, 'Nope');
    }

    #[Test]
    public function it_reads_the_configured_team_model_and_declines_a_non_beam_one(): void
    {
        // A host's own team class is reached through the resolver, never provisioned by this package.
        config(['beam.accounts.teams.model' => HostTeam::class]);
        $this->assertNull(BeamAccounts::teamModel());

        config(['beam.accounts.teams.model' => false]);
        $this->assertNull(BeamAccounts::teamModel());

        // An explicit beam `Team` (or subclass) wins over the absent-estate derivation.
        config(['beam.accounts.teams.model' => Team::class]);
        $this->assertSame(Team::class, BeamAccounts::teamModel());

        config(['beam.accounts.teams.model' => null, 'beam.accounts.publish_migrations' => true]);
        $this->assertSame(Team::class, BeamAccounts::teamModel());
    }
}
