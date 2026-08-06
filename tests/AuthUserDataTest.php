<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Contracts\AuthUserExtrasContributor;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Support\NullAuthUserExtras;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

it('binds the Null contributor by default', function () {
    expect(app(AuthUserExtrasContributor::class))->toBeInstanceOf(NullAuthUserExtras::class);
});

it('defaults the auth_user shape class to the base AuthUserData', function () {
    expect(config('beam.accounts.data.auth_user'))->toBe(AuthUserData::class);
});

it('projects the identity core for a central user', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);

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
    $user = User::create(['name' => 'Bo', 'email' => 'bo@example.test']);

    expect(AuthUserData::fromUser($user)->accessToken)->toBeNull();
});

it('reports isRoot for a central Root user', function () {
    $user = User::create(['name' => 'Root', 'email' => 'root@example.test']);
    // Root is assigned on the central (null) team — CentralRoot flips there to check.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $role = Role::create(['name' => 'Root', 'guard_name' => 'web', 'team_id' => null]);
    $user->assignRole($role);

    expect(AuthUserData::fromUser($user, null)->isRoot)->toBeTrue();
});

it('degrades cleanly standalone — host fields are ABSENT, not empty', function () {
    $user = User::create(['name' => 'Solo', 'email' => 'solo@example.test']);

    // With the Null contributor and the base shape class, the projection is the pure identity core.
    $array = AuthUserData::fromUser($user, 'tok')->toArray();

    // The host fields (Tower's entitlements / platformEmbedPk) never appear — not as [] or null,
    // literally absent — because the base class doesn't declare them and NullAuthUserExtras adds nothing.
    expect($array)->not->toHaveKey('entitlements');
    expect($array)->not->toHaveKey('platformEmbedPk');
    // `access_token` (snake) is the WIRE key — mapped via #[MapOutputName] to preserve byte-for-byte
    // parity with the retired AuthUserResource the SPA reads (HTTP-07, first consumer to pin the wire);
    // the PHP property stays camel `accessToken`.
    expect(array_keys($array))->toBe([
        'id', 'name', 'email', 'access_token', 'roles', 'permissions', 'tenants', 'isRoot', 'isDemo', 'tenant',
    ]);
});

it('merges a host contributor extras by name via ::from()', function () {
    // A fake host: swap the SHAPE class to a subclass that adds a flat field, and bind a
    // contributor that supplies its VALUE keyed by property name.
    config(['beam.accounts.data.auth_user' => FakeHostAuthUserData::class]);
    app()->bind(AuthUserExtrasContributor::class, fn () => new class implements AuthUserExtrasContributor
    {
        public function contribute(Authenticatable $user): array
        {
            return ['badge' => 'vip'];
        }
    });

    $user = User::create(['name' => 'Cy', 'email' => 'cy@example.test']);

    $data = AuthUserData::fromUser($user, 'tok');

    expect($data)->toBeInstanceOf(FakeHostAuthUserData::class);
    // Identity core still present…
    expect($data->email)->toBe('cy@example.test');
    // …and the host field hydrated by name (no constructor forwarding needed).
    expect($data->badge)->toBe('vip');
    expect($data->toArray())->toHaveKey('badge');
});

it('projects the identity core for a tenant user', function () {
    $user = User::create(['name' => 'Ty', 'email' => 'ty@example.test']);

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

class FakeHostAuthUserData extends AuthUserData
{
    public string $badge = 'none';
}

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
