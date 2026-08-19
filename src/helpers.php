<?php

namespace Splicewire\Beam\Accounts;

use Splicewire\Beam\Accounts\Facades\BeamAccounts;

/*
 * DEPRECATED COMPATIBILITY SHIM — do not add to it, and do not call these from new code.
 *
 * `fdecca2` dissolved these five functions into BeamAccountsManager behind the BeamAccounts facade
 * and deleted this file outright. That broke ELEVEN installs — six sites (beam, fable, numero,
 * schemastud, splicewire, tower) and five packages (beam-commerce, beam-licenser,
 * beam-market-packages, beam-ux, tower-market) — and not at runtime: at AUTOLOAD, so `php artisan`
 * would not boot at all.
 *
 * The mechanism is worth writing down, because it is not obvious and it defeated two attempted
 * fixes. Each consumer's `vendor/composer/installed.json` records this package's
 * `autoload.files` AS IT WAS WHEN INSTALLED. `composer dump-autoload` regenerates
 * `autoload_files.php` FROM that recorded metadata, so it faithfully reproduces the dead entry and
 * does NOT fix anything. Only re-installing the package in each consumer refreshes it — which
 * rewrites eleven tracked `composer.lock` files, while other sessions hold uncommitted work in
 * several of them.
 *
 * So the file returns, as forwarders. The API surface the deletion was after is unchanged: the
 * facade is the only documented way in, these are one line each and hold no logic. What it buys is
 * that a consumer keeps booting and migrates on its own schedule instead of on this package's.
 *
 * TO FINISH THE JOB: sweep the remaining call sites onto `BeamAccounts::`, re-lock the consumers,
 * and only then delete this file and its `composer.json` `autoload.files` entry — in that order.
 * Deleting it first is what caused this.
 */

if (! function_exists('Splicewire\Beam\Accounts\accountUserModel')) {
    /** @deprecated Use {@see BeamAccounts::userModel()}. */
    function accountUserModel(): string
    {
        return BeamAccounts::userModel();
    }
}

if (! function_exists('Splicewire\Beam\Accounts\accountTenantUserModel')) {
    /** @deprecated Use {@see BeamAccounts::tenantUserModel()}. */
    function accountTenantUserModel(): string
    {
        return BeamAccounts::tenantUserModel();
    }
}

if (! function_exists('Splicewire\Beam\Accounts\accountGuard')) {
    /** @deprecated Use {@see BeamAccounts::guard()}. */
    function accountGuard(): string
    {
        return BeamAccounts::guard();
    }
}

if (! function_exists('Splicewire\Beam\Accounts\accountTokenModel')) {
    /** @deprecated Use {@see BeamAccounts::tokenModel()}. */
    function accountTokenModel(): string
    {
        return BeamAccounts::tokenModel();
    }
}

if (! function_exists('Splicewire\Beam\Accounts\accountCurrentTeam')) {
    /** @deprecated Use {@see BeamAccounts::currentTeam()}. */
    function accountCurrentTeam(): ?object
    {
        return BeamAccounts::currentTeam();
    }
}
