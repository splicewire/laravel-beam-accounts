<?php

namespace Splicewire\Beam\Accounts\Entitlements\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The realm-root lookup seam `Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver`'s
 * grant cascade rides (ACC-01). The paid `splicewire/laravel-beam-ux` entry model (`BeamUxEntry`) is
 * the OOTB "realm root" grantable — but the dependency runs beam-ux → beam-accounts, never the other
 * way, so beam-accounts must stay ignorant of it. A host that wants realm-parameterized authoring
 * binds a concrete implementation via `config('beam.accounts.entitlements.realm_grantable')`
 * (beam-ux binds its own OOTB); unbound, the grant-cascade rung resolves no realms at all — the
 * null-default discipline (ADR-0009).
 */
interface RealmGrantable
{
    /** The morph type realm-root grantable rows are stored under (a HasVisibility model). */
    public function grantableMorphType(): string;

    /** The realm name a granted root row's id names, or null when the id is stale/unknown. */
    public function realmForGrantableId(string $id): ?string;

    /**
     * Every realm with a provisioned root grantable right now — drives demo/grant seeding
     * (`Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder`). Realms provisioned later need a
     * re-run of whatever seeds this list; there is no ambient "every possible realm" registry.
     *
     * @return list<string>
     */
    public function provisionedRealms(): array;

    /** The root grantable {@see Model} for a realm (find-or-create). */
    public function rootFor(string $realm): Model;
}
