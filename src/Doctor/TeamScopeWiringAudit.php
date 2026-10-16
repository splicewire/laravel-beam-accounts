<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Accounts\Http\Middleware\SetCurrentTeamPermissions;

/**
 * **The one mandatory host line, finally checked** (fail-closed-guards ticket 02; architecture-pass
 * B5-1). This package's roles are team-scoped (`roles.team_id`), so spatie's team scope must be
 * bound to the signed-in user's current team on every web request — and the starter that carries
 * the wiring says exactly what its absence does: "WITHOUT THIS LINE THE HOST AUTHORIZES NOTHING,
 * and does so silently … a user resolves ZERO roles, `BaseModelPolicy::viewAny()` finds no
 * `<alias>.view` token, and every cascade-policed resource is denied to its own team owner."
 *
 * Two adapters exist in the estate, and exactly one should be wired:
 *  - the `splicewire.team` middleware this package aliases ({@see SetCurrentTeamPermissions},
 *    aliased in {@see \Splicewire\Beam\Accounts\Concerns\WiresMiddleware}) placed in the host's
 *    `web` group — the plain-host shape;
 *  - beam-tenancy's permissions bootstrapper (named below as a literal: this package must not
 *    require beam-tenancy), which rebinds the scope per tenant context — the multi-tenant shape.
 *
 * Zero wired is a FAIL, not a warn: absence is a silent total denial on the package's primary path,
 * which is the precise failure mode the estate's instruments-that-lie doctrine exists to kill.
 * Both wired is a warn — the second write is at best redundant and at worst a fight.
 */
class TeamScopeWiringAudit implements DoctorAudit
{
    /** beam-tenancy's adapter, by literal — a soft reference, never a require. */
    private const TENANCY_BOOTSTRAPPER = 'Splicewire\\Beam\\Tenancy\\PermissionsTenancyBootstrapper';

    public function __construct(
        private readonly Router $router,
        private readonly Repository $config,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $adapters = [];

        $web = $this->router->getMiddlewareGroups()['web'] ?? [];
        if (in_array('splicewire.team', $web, true) || in_array(SetCurrentTeamPermissions::class, $web, true)) {
            $adapters[] = "the `splicewire.team` middleware in the `web` group";
        }

        if (in_array(self::TENANCY_BOOTSTRAPPER, (array) $this->config->get('tenancy.bootstrappers', []), true)) {
            $adapters[] = 'beam-tenancy\'s PermissionsTenancyBootstrapper';
        }

        return match (count($adapters)) {
            0 => [Finding::fail(
                'team-scope-unwired',
                'No team-scope adapter is wired, so this host AUTHORIZES NOTHING, silently: roles are '
                .'team-scoped and the registrar\'s team id stays null, every cascade-policed resource '
                .'denies its own team owner. Add `splicewire.team` to the web middleware group (plain '
                .'host) or beam-tenancy\'s PermissionsTenancyBootstrapper (multi-tenant host).',
            )],
            1 => [Finding::pass('team-scope-wired', "Team scope is bound by {$adapters[0]}.")],
            default => [Finding::warn(
                'team-scope-double-wired',
                'Both team-scope adapters are wired ('.implode(' AND ', $adapters).'); the second '
                .'write is redundant at best. Keep the one that matches the host\'s tenancy posture.',
            )],
        };
    }
}
