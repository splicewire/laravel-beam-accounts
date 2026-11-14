<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Splicewire\Beam\Accounts\Concerns\HasPreferredLocale;

/** A host user that OPTS IN to the person's language (localization 36; owner resolution (d)), as a host would. */
class LocalizedUser extends User implements HasLocalePreference, MustVerifyEmail
{
    use HasPreferredLocale;

    protected $table = 'users';
}
