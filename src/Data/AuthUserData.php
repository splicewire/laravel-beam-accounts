<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Contracts\AuthUserExtrasContributor;
use Splicewire\Beam\Accounts\Support\CentralRoot;

/**
 * The pure IDENTITY-CORE auth projection — what `/me`, login, passkey-login, and profile-update
 * converge on (auth-cluster spec asset 11 §2). It carries ONLY identity fields and names no
 * commerce/embed type, so beam-accounts never depends up on a host package.
 *
 * A host that needs extra fields on the projection (e.g. Tower's `entitlements` / `platformEmbedPk`)
 * uses the two-idiom extension seam (asset 07):
 *
 *  - SHAPE (idiom #2, config-swappable class): `config('beam.accounts.data.auth_user')` — a host
 *    publishes a subclass class-string; the base is the default. `fromUser` resolves it and hydrates
 *    THAT class, so the subclass's flat top-level props fill themselves.
 *  - VALUE (idiom #1, bound port + Null default): {@see AuthUserExtrasContributor} — the host binds a
 *    contributor that returns the extra fields keyed by name; the base spreads them blind.
 *
 * Construction is `::from()` (spatie hydrates by reflection/property name), NOT positional `new`, so a
 * subclass that ADDS props needs no constructor forwarding, and the base drops any host keys it doesn't
 * declare — standalone beam-accounts degrades to a coherent identity core with the host fields ABSENT.
 */
class AuthUserData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $accessToken,
        /** @var string[] */
        public array $roles,
        /** @var string[] */
        public array $permissions,
        /** @var array<int, array<string, mixed>> */
        public array $tenants,
        public bool $isRoot,
        public ?bool $isDemo,
        public ?string $tenant,
    ) {}

    /**
     * The single build seam. Resolves the host-swapped SHAPE class + the bound VALUE contributor,
     * then hydrates by name via `::from()` — so a host subclass fills its own extra props and the
     * base drops keys it doesn't declare.
     */
    public static function fromUser(Authenticatable $user, ?string $accessToken = null): static
    {
        $core = static::identityCore($user, $accessToken);

        // VALUE seam (idiom #1): assoc array keyed by property name; [] on a null host.
        $extras = app(AuthUserExtrasContributor::class)->contribute($user);

        // SHAPE seam (idiom #2): the host-swapped class; base is the default.
        $class = config('beam.accounts.data.auth_user', static::class);

        return $class::from([...$core, ...$extras]);
    }

    /**
     * The identity-core payload, keyed by property name. The central-vs-tenant branch mirrors the
     * retired `AuthUserResource`: in tenant context roles/permissions are the live scoped set and the
     * tenants list is empty; in central context the tenants list is populated and roles/permissions
     * are empty. `isRoot` is always the CENTRAL Root role via {@see CentralRoot} (a same-package call).
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
            'isRoot' => CentralRoot::isRoot($user),
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
