<?php

namespace Splicewire\Beam\Accounts\Tests\Analytics;

use Illuminate\Auth\Access\AuthorizationException;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Accounts\Analytics\AudienceDestinations;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaSummaryProvider;
use Splicewire\Beam\Doctor\DashboardTierAudit;

/**
 * analytics-area 19 (charter shape, 2026-11-12): an Audience read with NO viewer cannot be answered, so it says so with an
 * AuthorizationException BEFORE the authorizer is asked, and the doctor's dashboard-tier audit (which classes an
 * AuthorizationException as UNKNOWN) no longer reports the card as declined. A signed-in viewer an index refuses still reads
 * null, and an entitled viewer's counts are unchanged.
 */
class AudienceAreaNoViewerTest extends AudienceAreaTestCase
{
    public function test_with_no_viewer_an_index_count_raises_authorization_instead_of_answering_null(): void
    {
        $this->frameInRealm('operator');
        $this->assertNull(auth()->user(), 'precondition: nobody is signed in');

        $this->expectException(AuthorizationException::class);
        $this->app->make(AudienceDestinations::class)->count('users');
    }

    public function test_with_no_viewer_the_members_stream_raises_authorization_instead_of_answering_null(): void
    {
        $this->inTenantScope();

        $this->expectException(AuthorizationException::class);
        $this->app->make(AudienceDestinations::class)->members();
    }

    public function test_with_no_viewer_the_audience_summary_raises_authorization_so_the_audit_can_read_unknown(): void
    {
        $this->frameInRealm('operator');
        $definition = $this->app->make(ResourceRegistry::class)->get('analytics-audience');

        $this->expectException(AuthorizationException::class);
        $this->app->make(AnalyticsAreaSummaryProvider::class)->summary($definition);
    }

    public function test_the_dashboard_tier_audit_does_not_report_the_audience_card_as_dropped_with_no_viewer(): void
    {
        $this->frameInRealm('operator');

        $details = array_map(fn ($finding) => $finding->detail, $this->app->make(DashboardTierAudit::class)->run());

        $this->assertFalse(
            (bool) array_filter($details, fn (string $d) => str_contains($d, 'ABSENT') && str_contains($d, 'analytics-audience]')),
            'the Audience card is not reported as dropped: '.implode(' | ', $details),
        );
    }

    public function test_a_signed_in_viewer_an_index_refuses_still_reads_null(): void
    {
        $this->inTenantScope();
        [, $team] = $this->ownerWithTeam();
        $member = User::create(['name' => 'Mia', 'email' => 'mia19@example.test', 'password' => 'x']);
        Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
        $member->switchTeam($team);
        $this->analysts[] = $member->email;
        $this->actingAs($member->fresh());

        // Through the area's own page (a request with this viewer): the refused index is an honest absence, not an error.
        $this->getJson('/frame/resources/invitations')->assertForbidden();
        $figures = $this->figures();
        $this->assertArrayNotHasKey('pending-invites', $figures, 'the refused index draws no figure (null, not an exception)');
        $this->assertArrayHasKey('members', $figures, 'control: this member reads the members index');
    }

    public function test_an_entitled_viewer_is_still_counted(): void
    {
        $this->frameInRealm('operator');
        $operator = User::create(['name' => 'Op', 'email' => 'op19@example.test', 'password' => 'x']);
        $this->analysts[] = $this->operators[] = $operator->email;
        $this->actingAs($operator);

        // Through the area's own page (the request carries the frame route and its realm), as the reader is drawn it.
        $figures = $this->figures();
        $this->assertSame(['users', 'teams'], array_keys($figures));
        $this->assertIsInt($figures['users']['value']);
    }
}
