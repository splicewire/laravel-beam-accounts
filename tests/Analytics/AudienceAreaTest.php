<?php

namespace Splicewire\Beam\Accounts\Tests\Analytics;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Accounts\Analytics\AudienceArea;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AreaDefinition;
use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;

/**
 * The Audience area (analytics-area ticket 11) through the frame socket: beam-accounts contributes it through the
 * analytics area registry; in Tenant scope a workspace's admin reads its members, new members and pending invites, in
 * Install scope an operator reads users and teams; each figure is its index's total for the same reader; each link is
 * the index's own leaf on the reader's rail.
 */
class AudienceAreaTest extends AudienceAreaTestCase
{
    /** A workspace with three members (one joined 40 days ago) and two pending invitations beside an accepted one. */
    private function workspace(): User
    {
        [$owner, $team] = $this->ownerWithTeam();
        foreach (['ann', 'old'] as $name) {
            $member = User::create(['name' => ucfirst($name), 'email' => "{$name}@example.test", 'password' => 'x']);
            Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
        }
        Membership::query()->where('user_id', User::query()->where('email', 'old@example.test')->value('id'))
            ->update(['created_at' => CarbonImmutable::now()->subDays(40)]);

        Invitation::create(['team_id' => $team->id, 'email' => 'p1@example.test', 'role' => 'member', 'token' => 't1']);
        Invitation::create(['team_id' => $team->id, 'email' => 'p2@example.test', 'role' => 'member', 'token' => 't2']);
        Invitation::create(['team_id' => $team->id, 'email' => 'done@example.test', 'role' => 'member', 'token' => 't3'])
            ->forceFill(['accepted_at' => now()])->save();

        $this->analysts[] = $owner->email;

        return $owner;
    }

    private function install(): User
    {
        $operator = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'x']);
        $this->analysts[] = $this->operators[] = $operator->email;
        foreach (['a', 'b'] as $name) {
            $user = User::create(['name' => $name, 'email' => "{$name}@users.test", 'password' => 'x']);
            Team::create(['user_id' => $user->id, 'name' => "Team {$name}", 'personal_team' => false]);
        }

        return $operator;
    }

    public function test_beam_accounts_contributes_the_audience_area_through_the_analytics_registry(): void
    {
        $areas = $this->app->make(AnalyticsAreaRegistry::class);

        $this->assertSame(AudienceArea::class, $areas->resolve('audience'));
        $this->assertSame('splicewire/laravel-beam-accounts', $areas->registrantOf('audience'));
        $this->assertNotContains(AudienceArea::class, AnalyticsAreaRegistry::PACKAGE);
        $this->assertSame(['entitlement:analytics.view'], AreaDefinition::of(AudienceArea::class)->requires());
    }

    public function test_a_workspace_admin_reads_members_new_members_and_pending_invites_each_its_index_total(): void
    {
        $this->inTenantScope();
        $this->actingAs($this->workspace());

        $figures = $this->figures();
        $members = $this->listed('members');
        $since = CarbonImmutable::now()->subDays(AnalyticsPeriod::OVERVIEW_DAYS);

        $this->assertSame(['members', 'new-members', 'pending-invites'], array_keys($figures));
        $this->assertSame([3, 2, 2], [$figures['members']['value'], $figures['new-members']['value'], $figures['pending-invites']['value']]);
        $this->assertSame(count($members), $figures['members']['value'], 'Members = the members index');
        $this->assertSame(
            count(array_filter($members, fn (array $row): bool => $row['joinedAt'] !== null && CarbonImmutable::parse($row['joinedAt'])->greaterThanOrEqualTo($since))),
            $figures['new-members']['value'],
            'New members = the members index rows joined in the period',
        );
        $this->assertSame(count($this->listed('invitations')), $figures['pending-invites']['value'], 'Pending invites = the invitations index');
        $this->assertSame('New members, last 28 days', $figures['new-members']['label']);
    }

    public function test_an_operator_reads_users_and_teams_each_its_index_total_in_both_synthetic_reporting_states(): void
    {
        // The operator's console: the teams index admits an operator only on an operator mount (ModelOrOperatorReadPolicy).
        $this->frameInRealm('operator');
        $this->actingAs($this->install());

        $seen = [];
        foreach ([false, true] as $exclude) {
            // Charter ruling (C), 2026-11-10: the toggle governs counts of TENANTS; this package's users and teams are not
            // tenants, so these figures equal their indexes whichever way it is set. The deploy-time key is set here
            // directly: laravel-beam-tenancy, which owns the runtime override, is not part of this package.
            config(['beam.tenancy.synthetic.exclude_from_reporting' => $exclude]);

            $figures = $this->figures();
            $this->assertSame(['users', 'teams'], array_keys($figures), 'toggle '.var_export($exclude, true));
            $this->assertSame(count($this->listed('users')), $figures['users']['value'], 'Users = the users index, toggle '.var_export($exclude, true));
            $this->assertSame(count($this->listed('teams')), $figures['teams']['value'], 'Teams = the teams index, toggle '.var_export($exclude, true));
            $this->assertSame(['Users', 'Teams'], [$figures['users']['label'], $figures['teams']['label']], 'no label claims synthetic members are left out');
            $seen[] = [$figures['users']['value'], $figures['teams']['value']];
        }

        // The users index's default scope is the shared-team roster, which for a team-less operator is themself.
        $this->assertSame([1, 2], $seen[0]);
        $this->assertSame($seen[0], $seen[1], 'the toggle moves neither figure');

        // An operator console's host widens that index through its seam; the figure widens with it, still its total.
        config(['beam.accounts.users.scope' => fn ($query) => $query]);
        $figures = $this->figures();
        $this->assertSame(3, $figures['users']['value']);
        $this->assertSame(count($this->listed('users')), $figures['users']['value'], 'Users = the users index under the host seam');
    }

    public function test_each_link_is_its_indexs_leaf_on_the_readers_rail_and_only_there(): void
    {
        $this->inTenantScope();
        $this->actingAs($owner = $this->workspace());

        // No navigation contributor at all: no rail, so no link.
        $this->assertSame([null, null, null], array_column($this->figures(), 'href'));

        // A rail with both leaves.
        $this->rail(['members.index' => '/workspace/team', 'invitations.index' => '/workspace/invites']);
        $figures = $this->figures();
        $this->assertSame('/workspace/team', $figures['members']['href']);
        $this->assertSame('/workspace/team', $figures['new-members']['href']);
        $this->assertSame('/workspace/invites', $figures['pending-invites']['href']);

        // The reader's rail does not carry the invitations leaf: no link to it.
        $this->rail(['members.index' => '/workspace/team']);
        $this->assertNull($this->figures()['pending-invites']['href']);

        // The leaf is on the rail but the realm's route context does not bind it: no route, so no link.
        $this->rail(['members.index' => '/workspace/team', 'invitations.index' => '/workspace/invites'], bound: ['members.index']);
        $this->assertNull($this->figures()['pending-invites']['href']);
        $this->assertSame('/workspace/team', $this->figures()['members']['href'], 'control');
    }

    public function test_an_index_this_reader_may_not_read_draws_no_figure(): void
    {
        $this->inTenantScope();
        [$owner, $team] = $this->ownerWithTeam();
        $member = User::create(['name' => 'Mia', 'email' => 'mia@example.test', 'password' => 'x']);
        Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
        $member->switchTeam($team);
        $this->analysts[] = $member->email;
        Invitation::create(['team_id' => $team->id, 'email' => 'p@example.test', 'role' => 'member', 'token' => 'tm']);

        $this->actingAs($member->fresh());

        $figures = $this->figures();
        $this->getJson('/frame/resources/invitations')->assertForbidden();
        $this->assertArrayNotHasKey('pending-invites', $figures, 'a member does not read the invitations index');
        $this->assertSame(2, $figures['members']['value'], 'control: the members index is this member\'s to read');
    }
}
