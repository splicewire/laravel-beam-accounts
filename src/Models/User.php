<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

use function Splicewire\Beam\Accounts\accountTenantUserModel;

use Splicewire\Beam\Accounts\Notifications\ResetPasswordNotification;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

/**
 * The beam auth principal — the base User model of the beam-accounts engine.
 *
 * Composes the vendor auth substrate (Sanctum tokens, Spatie roles, Laravel Passkeys,
 * Notifiable, UUID keys) and the Stancl `ResourceSyncing` machinery. A host subclasses this
 * as its concrete `App\Models\User` and layers on app-specific state (tenant relations,
 * boot orchestration). The base carries only the generic auth/sync state every beam host
 * shares — including the SPA-routed password-reset notification, config-seamed so it needs
 * no host override; the one host-bound name — the tenant-side user model — is
 * resolved through config (`accountTenantUserModel()`), never imported, so the base takes no
 * edge onto any host `App\Models\*` class (ADR-0138).
 *
 * The base intentionally does NOT declare `implements SyncMaster`: that contract's
 * `tenants(): BelongsToMany` binds to the host-owned `Tenant` model + host pivot semantics
 * (role/invited_at/accepted_at on `tenant_users`), which are app-specific by nature. The
 * concrete `App\Models\User` declares `implements SyncMaster` and supplies `tenants()`,
 * keeping this base host-agnostic and instantiable while Stancl still sees a full SyncMaster
 * on the concrete central model (recohere T22).
 */
class User extends Authenticatable implements PasskeyUser
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;
    use HasUuids;

    // WebAuthn passkeys (login-branding-passkey ticket 07). The trait supplies the
    // PasskeyUser contract (passkeys() relation + stable user handle + display/username).
    // Credentials live in the CENTRAL DB alongside users + personal access tokens; the RP
    // is the central sign-in host (config('passkeys.relying_party_id') = APP_URL host), so a
    // single credential yields a token that already works across every tenant subdomain.
    use PasskeyAuthenticatable;
    use ResourceSyncing;

    protected $connection = 'central';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
        'google_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Send the password-reset link email pointing at the SPA reset route
     * (config-seamed), not Laravel's default server-rendered `password.reset` route.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'id';
    }

    public function getTenantModelName(): string
    {
        return accountTenantUserModel();
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return ['name', 'email', 'password'];
    }
}
