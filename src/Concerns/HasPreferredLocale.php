<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Splicewire\Beam\Accounts\Localization\PreferredLocale;

/**
 * OPT-IN recipient integration (localization 36; ruling 03 owner resolution (d)). A host composes it into its OWN user
 * model, and declares `implements \Illuminate\Contracts\Translation\HasLocalePreference`. The base
 * `Splicewire\Beam\Accounts\Models\User` does not use it, so no inheriting host changes behavior by upgrading.
 *
 *     class User extends Authenticatable implements HasLocalePreference
 *     {
 *         use HasPreferredLocale;
 *     }
 *
 * - `preferredLocale()` is the person's saved, supported language from the CENTRAL identity, or null for automatic.
 *   On a tenant-side replica it reads the central value. The framework's notification sender scopes each delivery to
 *   it.
 * - A SELF-ADDRESSED delivery sent while this person's own request runs (verification after registering, a reset they
 *   asked for) uses that request's resolved language instead: ruling 03 §3's explicit, validated per-delivery
 *   override. Outside a routed request it is the saved preference as usual.
 */
trait HasPreferredLocale
{
    /** The request's language while a self-addressed send is in flight, else null. */
    private ?string $selfAddressedLocale = null;

    public function preferredLocale(): ?string
    {
        return $this->selfAddressedLocale ?? PreferredLocale::of($this);
    }

    /** The verification mail after registering, in the language of the request that registered. */
    public function sendEmailVerificationNotification(): void
    {
        $this->sendSelfAddressed(fn () => parent::sendEmailVerificationNotification());
    }

    /** A reset the person asked for, in the language of the request that asked. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->sendSelfAddressed(fn () => parent::sendPasswordResetNotification($token));
    }

    private function sendSelfAddressed(callable $send): void
    {
        $previous = $this->selfAddressedLocale;
        $this->selfAddressedLocale = self::currentRequestLocale();
        try {
            $send();
        } finally {
            $this->selfAddressedLocale = $previous;
        }
    }

    /** The resolved language of the routed request in progress, or null outside one. */
    private static function currentRequestLocale(): ?string
    {
        if (! app()->bound('request') || request()->route() === null) {
            return null;
        }

        return app()->getLocale();
    }
}
