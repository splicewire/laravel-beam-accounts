<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Data\BeamData;

/**
 * The pure IDENTITY-CORE auth projection — what `/me`, login, passkey-login, and profile-update
 * converge on (auth-cluster spec asset 11 §2). It carries ONLY identity fields and names no
 * commerce/embed type, so beam-accounts never depends up on a host package.
 *
 * ## Extra fields come from the particle contribution seam, not from here
 *
 * A package that owns a concern adds its own named slice of the `me` read projection by registering a
 * {@see \Splicewire\Beam\Particle\Contribution\ResourceContribution} on key `me` — beam-commerce ships
 * `commerce.entitlements`, beam-embed ships `embed.platformEmbedPk`, each from the package that owns
 * the concept. This class stays closed.
 *
 * ⚠️ Two seams used to live here and both are gone (particle-contribution-seam 16/18). A
 * config-swappable SHAPE class-string (`beam.accounts.data.auth_user`) and a bound VALUE port
 * (`AuthUserExtrasContributor`, with a Null default) each held exactly ONE slot, so two packages could
 * never both extend the projection — which is why two package-owned fields (a commerce concept and an
 * embed concept) had to be hoisted into the top host to meet. That is the same disease as tower's
 * 22-prop `tenants`, second mechanism: `tenants` was forced up by last-registration-wins on a resource
 * key, this by last-bind-wins on a container binding. `ComposeMany` cures both.
 *
 * Construction is `::from()` (spatie hydrates by reflection/property name), NOT positional `new`, so the
 * identity core is assembled by name in {@see identityCore()}. A host that installs neither contributing
 * package gets a coherent identity core with the slices ABSENT — not present-and-empty, which is the
 * distinction the old `[]` Null default could not make.
 */
#[TypeScript]
class AuthUserData extends BeamData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        // Wire parity with the retired AuthUserResource (HTTP-07): the token serializes as the
        // snake `access_token` the SPA has always read, though the property is camel. Global output
        // mapping is off (config/data.php), so the map is declared per-property here.
        #[MapOutputName('access_token')]
        public ?string $accessToken,
        /** @var string[] */
        public array $roles,
        /** @var string[] */
        public array $permissions,
        /** @var array<int, array<string, mixed>> */
        public array $tenants,
        // ⚠️ These two are camelCase ON THE WIRE, and the attributes DECLARE that rather than change
        // it. Global output mapping is off, so an undeclared property has always published its own
        // PHP name — `isRoot`/`isDemo` have been the live keys since this projection replaced
        // AuthUserResource, and the SPA reads them that way in 17 files (`user.isRoot` in
        // ui/src/app/shell/SystemZone.tsx, ui/src/features/operator/RequireRoot.tsx, ui/src/stores/user.ts;
        // `isDemo` in ui/src/app/shell/SectionBar.tsx and sectionMeta.ts). Nothing anywhere reads
        // `is_root`/`is_demo`.
        //
        // So do NOT "tidy" these to snake to match `access_token` above or the wider estate: that
        // sibling is snake because the retired resource published it snake (HTTP-07), and these are
        // camel because this projection published them camel. Both attributes are pinning what is
        // ALREADY on the wire — which is the wire-name convention's actual rule (declare the wire,
        // and the PHP spelling becomes free), not a house preference for either casing. Changing
        // either argument is a breaking API change to an authenticated response, not a cleanup.
        #[MapOutputName('isRoot')]
        public bool $isRoot,
        #[MapOutputName('isDemo')]
        public ?bool $isDemo,
        public ?string $tenant,
    ) {}

    /**
     * The single build seam for the IDENTITY CORE — the token paths' projection, and the base the `me`
     * particle resource projects before any contribution folds on.
     *
     * ⚠️ It deliberately does NOT reach for a contribution. Four of this method's five callers mint or
     * refresh a token (login, passkey-login, profile-update, log-in-as-user) and none of them is a
     * particle READ; adding a fold point inside a DTO builder so contributions reached them would be a
     * second contribution mechanism living somewhere the seam does not, which ticket 04 §A8 forbade and
     * ticket 16 §A4 refused again on measurement — the SPA's `applyLogin` consumes neither contributed
     * prop, and `fetchMe` runs on boot regardless.
     */
    public static function fromUser(Authenticatable $user, ?string $accessToken = null): static
    {
        return static::from(static::identityCore($user, $accessToken));
    }

    /**
     * The identity-core payload, keyed by property name. The central-vs-tenant branch mirrors the
     * retired `AuthUserResource`: in tenant context roles/permissions are the live scoped set and the
     * tenants list is empty; in central context the tenants list is populated and roles/permissions
     * are empty. `isRoot` is always the CENTRAL Root role via {@see BeamAccounts::isRoot()} (a same-package call).
     *
     * @return array<string, mixed>
     */
    protected static function identityCore(Authenticatable $user, ?string $accessToken): array
    {
        $core = [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => $user->name,
            'email' => $user->email,
            'accessToken' => $accessToken,
            'isRoot' => BeamAccounts::isRoot($user),
        ];

        if (tenancy()->initialized) {
            // Tenant context: roles/permissions are the tenant-scoped set; no tenants list needed.
            $roles = method_exists($user, 'roles') ? $user->roles->pluck('name')->all() : [];
            $permissions = method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')->all()
                : [];

            return [
                ...$core,
                'roles' => $roles,
                'permissions' => $permissions,
                'tenants' => [],
                'isDemo' => tenant()->isSynthetic(),
                'tenant' => tenant()->getTenantKey(),
            ];
        }

        // Central context: the tenants list is populated; roles/permissions resolve per-tenant.
        return [
            ...$core,
            'roles' => [],
            'permissions' => [],
            'tenants' => static::tenantsFor($user, $core['isRoot']),
            'isDemo' => false,
            'tenant' => null,
        ];
    }

    /**
     * The central-context tenants list: Root sees every tenant, everyone else sees their own. Read
     * off the resolved tenancy model (a class-string, never a compile-time reference — the base names
     * no host type) and the host-supplied `tenants()` relation on the User. Degrades to `[]` when a
     * standalone site has neither.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function tenantsFor(Authenticatable $user, bool $isRoot): array
    {
        if ($isRoot) {
            $model = config('tenancy.tenant_model');

            if (is_string($model) && class_exists($model)) {
                return $model::query()->with('domains')->get()
                    ->map(static fn ($t) => static::tenantRow($t))
                    ->all();
            }
        }

        if (method_exists($user, 'tenants')) {
            return $user->tenants()->with('domains')->get()
                ->map(static fn ($t) => static::tenantRow($t))
                ->all();
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function tenantRow($tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'domain' => $tenant->domains->first()?->domain,
            'primaryHost' => method_exists($tenant, 'primaryHost') ? $tenant->primaryHost() : null,
        ];
    }
}
