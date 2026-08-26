<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Splicewire\Beam\Doctor\RetiredMigrationAudit;

/**
 * beam-accounts' own retirement declarations, on the instrument beam already uses for its own. See
 * {@see RetiredMigrationAudit}, whose docblock spells out why a retirement must be DECLARED rather than
 * inferred: a retired stub's defining property is that it is *absent*, and absence is indistinguishable
 * from "never existed" or "belongs to another package".
 *
 * All eight declarations come out of one commit, `4272fbb` ("reclassifies the old host-placed `teams/`
 * directory ... into `shared/`"): seven tables were re-tiered `teams/` → `shared/` because a login's
 * team pointer has to exist wherever the login's own row does, and the eighth — the separate
 * `add_lifecycle_to_invitations_table` ALTER — was squashed into `shared/create_invitations_table`
 * pre-prod. `teams/create_visibilities_table` did NOT move and is deliberately absent from this list.
 *
 * Publishing is a COPY, so the package cannot reach back: a host installed before `4272fbb` still holds
 * the whole `teams/` set. Measured 2026-08-26 across 21 Herd hosts — `~/Herd/splicewire` carries all
 * eight, and the retired copies are currently the ONLY creators of those tables there, which is exactly
 * why this reports and never repairs. The hazard when the `shared/` set is also published is the one
 * beam's docblock records: the stale copy sorts EARLIER, creates the table in the pre-re-tier shape, and
 * the surviving convergent stub then has to converge onto a shape it never declared.
 *
 * ## Directory-scoped on purpose — a bare `add_lifecycle_to_invitations_table` is NOT retired
 *
 * The key is `<dir>/<stem>`, and the ALTER is declared only under `teams/`. `laravel-beam-starter` and
 * `laravel-satellite-starter` ship a FLAT `add_lifecycle_to_invitations_table` alongside a FLAT,
 * pre-lifecycle `create_invitations_table` — a self-contained host-tier 1:1 conversion of the old
 * `teams/` estate, not a published copy of anything. Measured at `~/Herd/audiostud`, `~/Herd/beam`, and
 * `~/Herd/satellite`, all three built from those starters. Declaring the bare stem would condemn the
 * survivor at all three and delete the only migration that adds the columns, which is precisely the
 * false positive {@see RetiredMigrationAudit}'s directory-aware match exists to avoid.
 */
class BeamAccountsRetiredMigrationAudit extends RetiredMigrationAudit
{
    /**
     * The published path with its timestamp stripped => what supersedes it.
     *
     * @var array<string, string>
     */
    public const ACCOUNTS_RETIRED = [
        'teams/create_teams_table' => 'shared/create_teams_table',
        'teams/create_memberships_table' => 'shared/create_memberships_table',
        'teams/add_current_team_id_to_users_table' => 'shared/add_current_team_id_to_users_table',
        'teams/create_invitations_table' => 'shared/create_invitations_table',
        'teams/create_access_grants_table' => 'shared/create_access_grants_table',
        'teams/create_share_links_table' => 'shared/create_share_links_table',
        'teams/create_view_requests_table' => 'shared/create_view_requests_table',

        // Squashed INTO the create rather than re-tiered alongside it — the successor names the file
        // that absorbed it, not a same-stem twin.
        'teams/add_lifecycle_to_invitations_table' => 'shared/create_invitations_table',
    ];

    public function __construct(?string $migrationsPath = null)
    {
        parent::__construct(self::ACCOUNTS_RETIRED, $migrationsPath);
    }
}
