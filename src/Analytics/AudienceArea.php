<?php

namespace Splicewire\Beam\Accounts\Analytics;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;

/**
 * Audience (analytics-area ticket 11): who the reader's site serves, from the indexes that already list them.
 *
 *  - In **Tenant scope** (a workspace's admin): its **Members**, the members who **joined in the period**, and its
 *    **Pending invites**, from the `members` and `invitations` indexes, which list the current team's.
 *  - In **Install scope** (the operator): the install's **Users** and **Teams**, from the `users` and `teams` indexes.
 *
 * No figure is a second count. Each is the total its index lists to the same reader, read through that index's own
 * scoped count path ({@see AudienceDestinations}): Members = the `members` list's rows, Pending invites = the
 * `invitations` list (its `scope()` is the current team's unaccepted invitations), Users and Teams = those lists. New
 * members are the `members` rows whose Joined date falls in the period. Each figure links to its index where this reader's
 * rail has it. An index this reader may not read draws no figure.
 *
 * The scope is 05's {@see \Splicewire\Beam\Analytics\Scope\AnalyticsScope}, decided at the request's boundary; this
 * area holds no topology code. Synthetic tenants: charter ruling (C), 2026-11-10. The Users and Teams figures equal their
 * indexes and do not apply `SyntheticReporting`, because this package's users and teams are not tenants; that toggle
 * governs counts of tenants, which laravel-beam-tenancy's own reportable count makes.
 *
 * Scaffolded by `splicewire:beam:analytics:make-area AudienceArea` (ticket 11), then filled in.
 */
#[Area(key: 'audience', label: 'Audience', icon: 'Users', order: 40, ability: null)]
final class AudienceArea implements AnalyticsArea
{
    public function __construct(
        private readonly AudienceDestinations $indexes,
    ) {}

    /** @return list<AnalyticsFigureData> */
    public function measure(AnalyticsReading $reading): array
    {
        return $reading->scope->isTenant() ? $this->workspace($reading) : $this->install();
    }

    /** @return list<AnalyticsFigureData> */
    private function workspace(AnalyticsReading $reading): array
    {
        $figures = [];

        $members = $this->indexes->members();
        if ($members !== null) {
            $href = $this->indexes->href('members');
            $since = CarbonImmutable::now()->subDays($reading->period->days);
            $joined = array_filter($members, fn (MembershipData $member): bool => $member->joinedAt !== null
                && CarbonImmutable::parse($member->joinedAt)->greaterThanOrEqualTo($since));

            $figures[] = new AnalyticsFigureData(id: 'members', label: __('beam-accounts::messages.analytics_members'), value: count($members), href: $href);
            $figures[] = new AnalyticsFigureData(id: 'new-members', label: __('beam-accounts::messages.analytics_new_members', ['days' => $reading->period->days]), value: count($joined), href: $href);
        }

        $invites = $this->indexes->count('invitations');
        if ($invites !== null) {
            $figures[] = new AnalyticsFigureData(id: 'pending-invites', label: __('beam-accounts::messages.analytics_pending_invites'), value: $invites, href: $this->indexes->href('invitations'));
        }

        return $figures;
    }

    /** @return list<AnalyticsFigureData> */
    private function install(): array
    {
        $figures = [];

        foreach (['users', 'teams'] as $key) {
            $label = $key === 'users' ? __('beam-accounts::messages.analytics_users') : __('beam-accounts::messages.analytics_teams');
            $count = $this->indexes->count($key);
            if ($count !== null) {
                $figures[] = new AnalyticsFigureData(id: $key, label: $label, value: $count, href: $this->indexes->href($key));
            }
        }

        return $figures;
    }
}
