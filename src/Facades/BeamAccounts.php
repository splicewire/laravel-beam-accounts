<?php

namespace Splicewire\Beam\Accounts\Facades;

use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Accounts\BeamAccountsManager;

/**
 * The BeamAccounts facade — the short static front door to the beam-accounts instance.
 *
 * It holds NO logic: every method it appears to have resolves through `__callStatic` to the
 * container-bound {@see BeamAccountsManager}, which is where the host-resolution seam prose and the
 * surface itself live. The surface is CLOSED at the six methods below.
 *
 * Deliberately NOT registered as a global alias (`extra.laravel.aliases`): every call site imports
 * this class explicitly, so a bare `\BeamAccounts` can never become a second, import-free way to say
 * `BeamAccounts::userModel()` that `surgeon:trace` cannot see.
 *
 * Called before the container is booted — from a published `config/*.php`, say — it THROWS. The
 * namespaced functions it replaces would have answered from `config()` at any time, so this is the
 * one behavioural narrowing of the move; the census found no config-time call site (the two hits in
 * `beam/market-packages.php` and `splicewire-app`'s `config/beam/accounts.php` are prose in
 * comments, not calls).
 *
 * The `@method` block below is hand-written and guarded by a reflective parity test
 * (`tests/Facade/FacadeMethodParityTest.php`), which asserts it matches the instance's public
 * methods — so the one place each signature lives stays the one place.
 *
 * @method static string userModel()
 * @method static string tenantUserModel()
 * @method static string guard()
 * @method static string tokenModel()
 * @method static object|null currentTeam()
 * @method static bool isRoot(?\Illuminate\Contracts\Auth\Authenticatable $user)
 *
 * @see BeamAccountsManager
 */
class BeamAccounts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeamAccountsManager::class;
    }
}
