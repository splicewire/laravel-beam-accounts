<?php

use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

// ⚠️ The two seams this file used to open with are GONE (particle-contribution-seam 18): a bound
// `AuthUserExtrasContributor` port with a Null default (VALUE), and a config-swappable
// `beam.accounts.data.auth_user` class-string (SHAPE). Each held ONE slot, so two packages could
// never both extend the projection. Extra fields now arrive as named slices on the `me` particle
// resource, contributed by the packages that own them — asserted in `MeResourceTest`, not here,
// because this class no longer knows the seam exists.
it('has no host-extension seam left on the DTO', function () {
    expect(interface_exists('Splicewire\\Beam\\Accounts\\Contracts\\AuthUserExtrasContributor'))->toBeFalse();
    expect(config('beam.accounts.data'))->toBeNull();
});

it('projects the identity core for a central user', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);

    $data = AuthUserData::fromUser($user, 'plain-text-token');

    expect($data)->toBeInstanceOf(AuthUserData::class);
    expect($data->id)->toBe((string) $user->getKey());
    expect($data->name)->toBe('Ada');
    expect($data->email)->toBe('ada@example.test');
    expect($data->accessToken)->toBe('plain-text-token');
    // Central context: roles/permissions empty, no current tenant, not demo, not root.
    expect($data->roles)->toBe([]);
    expect($data->permissions)->toBe([]);
    expect($data->tenants)->toBe([]);
    expect($data->tenant)->toBeNull();
    expect($data->isDemo)->toBeFalse();
    expect($data->isRoot)->toBeFalse();
});

it('mirrors a null access token', function () {
    $user = User::create(['name' => 'Bo', 'email' => 'bo@example.test', 'password' => 'secret']);

    expect(AuthUserData::fromUser($user)->accessToken)->toBeNull();
});

it('reports isRoot for a central Root user', function () {
    $user = User::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'secret']);
    // Root is assigned on the central (null) team — BeamAccounts::isRoot() flips there to check.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    // Resolved from config, not `Spatie\Permission\Models\Role` directly — that is what
    // TeamProvisioner::syncSpatieRole() does, and on the uuid-keyed fixture the stock model cannot
    // mint a key at all (beam-facade 138).
    $role = app(config('permission.models.role'))::create(['name' => 'Root', 'guard_name' => 'web', 'team_id' => null]);
    $user->assignRole($role);

    expect(AuthUserData::fromUser($user, null)->isRoot)->toBeTrue();
});

it('degrades cleanly standalone — contributed fields are ABSENT, not empty', function () {
    $user = User::create(['name' => 'Solo', 'email' => 'solo@example.test', 'password' => 'secret']);

    // `fromUser` is the identity core and nothing else: no contribution folds here, by design
    // (ticket 16 §A4 — four of its five callers mint a token and none is a particle read).
    $array = AuthUserData::fromUser($user, 'tok')->toArray();

    // The contributed slices (`commerce`, `embed`) never appear — not as [] or null, literally
    // absent, which is the distinction the retired port's `[]` Null default could not make.
    expect($array)->not->toHaveKey('commerce');
    expect($array)->not->toHaveKey('embed');
    expect($array)->not->toHaveKey('entitlements');
    expect($array)->not->toHaveKey('platformEmbedPk');
    // `access_token` (snake) is the WIRE key — mapped via #[MapOutputName] to preserve byte-for-byte
    // parity with the retired AuthUserResource the SPA reads (HTTP-07, first consumer to pin the wire);
    // the PHP property stays camel `accessToken`.
    expect(array_keys($array))->toBe([
        'id', 'name', 'email', 'access_token', 'roles', 'permissions', 'tenants', 'isRoot', 'isDemo', 'tenant',
    ]);
});

it('projects the identity core for a tenant user', function () {
    $user = User::create(['name' => 'Ty', 'email' => 'ty@example.test', 'password' => 'secret']);

    // Stand up a tenant context: bind a fake tenant and flip tenancy()->initialized so the
    // tenant branch runs (roles/permissions live, tenants list empty, current tenant + demo set).
    $tenant = new FakeTenant('acme', synthetic: true);
    app()->instance(Tenant::class, $tenant);

    $tenancy = new Tenancy;
    $tenancy->initialized = true;
    $tenancy->tenant = $tenant;
    app()->instance(Tenancy::class, $tenancy);

    try {
        $data = AuthUserData::fromUser($user, 'tok');
    } finally {
        app()->forgetInstance(Tenancy::class);
    }

    expect($data->tenant)->toBe('acme');
    expect($data->isDemo)->toBeTrue();
    expect($data->tenants)->toBe([]);
    expect($data->roles)->toBe([]);        // no tenant-scoped roles assigned in the fixture
    expect($data->permissions)->toBe([]);
});

class FakeTenant implements Tenant
{
    public function __construct(private string $key, private bool $synthetic = false) {}

    public function isSynthetic(): bool
    {
        return $this->synthetic;
    }

    public function getTenantKey(): string
    {
        return $this->key;
    }

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getInternal(string $key)
    {
        return null;
    }

    public function setInternal(string $key, $value)
    {
        return $this;
    }

    public function run(callable $callback)
    {
        return $callback($this);
    }
}

// ── the tenants list is a DECLARED shape, not a hand-rolled array ────────────────────────────────

it('projects each tenant through a declared DTO, not an ad-hoc array', function () {
    $row = Splicewire\Beam\Accounts\Data\TenantRefData::from([
        'id' => 't1',
        'name' => 'Acme',
        'domain' => 'acme.test',
        'primaryHost' => 'acme.example.com',
    ]);

    expect($row)->toBeInstanceOf(Splicewire\Beam\Accounts\Data\TenantRefData::class)
        ->and($row->primaryHost)->toBe('acme.example.com');
});

it('keeps the tenant row wire keys EXACTLY as the SPA already reads them', function () {
    // ⚠️ These four keys are a published contract. `SystemZone.tsx:27` does
    // `tenant.primaryHost?.split('.')[0]` to label the tenant switcher, and `stores/user.ts` types
    // it by hand. `primaryHost` is camelCase because that is what ships today — declaring it is the
    // point; renaming it to `primary_host` would be a breaking change wearing a cleanup's clothes.
    $keys = array_keys(Splicewire\Beam\Accounts\Data\TenantRefData::from([
        'id' => 't1', 'name' => 'Acme', 'domain' => null, 'primaryHost' => null,
    ])->toArray());

    expect($keys)->toBe(['id', 'name', 'domain', 'primaryHost']);
});
