<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
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

    /** Stancl's event, and the listener that actually runs the configured bootstrappers on it — literals, soft references. */
    private const TENANCY_INITIALIZED = 'Stancl\\Tenancy\\Events\\TenancyInitialized';

    private const BOOTSTRAP_TENANCY = 'Stancl\\Tenancy\\Listeners\\BootstrapTenancy';

    public function __construct(
        private readonly Router $router,
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $adapters = [];

        $web = $this->router->getMiddlewareGroups()['web'] ?? [];
        if (in_array('splicewire.team', $web, true) || in_array(SetCurrentTeamPermissions::class, $web, true)) {
            $adapters[] = "the `splicewire.team` middleware in the `web` group";
        }

        // The bootstrapper binds the scope only when tenancy initialization runs it: configured AND Stancl's
        // TenancyInitialized → BootstrapTenancy listener registered. Configured without that listener, tenancy still
        // initializes but the registrar's team id stays null — the same silent denial — so it is not counted.
        $bootstrapperConfigured = in_array(self::TENANCY_BOOTSTRAPPER, (array) $this->config->get('tenancy.bootstrappers', []), true);
        $bootstrapperInert = $bootstrapperConfigured && ! $this->bootstrapListenerRegistered();
        if ($bootstrapperConfigured && ! $bootstrapperInert) {
            $adapters[] = 'beam-tenancy\'s PermissionsTenancyBootstrapper';
        }

        return match (count($adapters)) {
            0 => [Finding::fail(
                'team-scope-unwired',
                'No team-scope adapter is wired, so this host AUTHORIZES NOTHING, silently: roles are '
                .'team-scoped and the registrar\'s team id stays null, every cascade-policed resource '
                .'denies its own team owner. '
                .($bootstrapperInert
                    ? 'beam-tenancy\'s PermissionsTenancyBootstrapper is configured but never runs: no '
                        .'`Stancl\\Tenancy\\Events\\TenancyInitialized` → `Stancl\\Tenancy\\Listeners\\BootstrapTenancy` '
                        .'listener is registered. Register that listener (beam-tenancy\'s event wiring does) or add '
                        .'`splicewire.team` to the web middleware group.'
                    : 'Add `splicewire.team` to the web middleware group (plain host) or beam-tenancy\'s '
                        .'PermissionsTenancyBootstrapper with its TenancyInitialized → BootstrapTenancy listener '
                        .'(multi-tenant host).'),
            )],
            1 => [Finding::pass('team-scope-wired', "Team scope is bound by {$adapters[0]}.")],
            default => [Finding::warn(
                'team-scope-double-wired',
                'Both team-scope adapters are wired ('.implode(' AND ', $adapters).'); the second '
                .'write is redundant at best. Keep the one that matches the host\'s tenancy posture.',
            )],
        };
    }

    /**
     * Whether TenancyInitialized has BootstrapTenancy among its raw listeners, in any form Laravel registers it:
     * the class string, `Class@handle`, or `[Class, 'handle']`. A closure cannot be identified, so it does not count
     * (fail closed: the audit then names the missing listener).
     */
    private function bootstrapListenerRegistered(): bool
    {
        if (! method_exists($this->events, 'getRawListeners')) {
            return false;
        }
        foreach ($this->events->getRawListeners()[self::TENANCY_INITIALIZED] ?? [] as $listener) {
            $class = match (true) {
                is_string($listener) => explode('@', $listener, 2)[0],
                is_array($listener) && is_string($listener[0] ?? null) => $listener[0],
                default => null,
            };
            if ($class === self::BOOTSTRAP_TENANCY) {
                return true;
            }
        }

        return false;
    }
}
