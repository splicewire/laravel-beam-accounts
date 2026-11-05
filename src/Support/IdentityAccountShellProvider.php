<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountData;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Splicewire\Beam\Accounts\Data\ProfileData;

/**
 * The default {@see AccountShellProvider}. It fills only what the user record itself has: the
 * profile handle (`@` + the email's local part), an initials avatar from the name, and the
 * account email. It invents no product data: there is no plan (the packaged `<AccountShell>` hides
 * the plan section when `plan` is null), no metrics, no upsells, and no payment label.
 *
 * A plan comes from whatever owns plans. Commerce contributes it when it is installed; the
 * mechanism is a follow-up. A host with real product data binds its own provider over this one,
 * and a host that wants the shell empty binds {@see NullAccountShellProvider}.
 *
 * This replaces the starters' copied `App\Account\StarterAccountShell` (make-surface 01, owner
 * ruling 2026-11-05). Its handle and initials rules are kept byte-for-byte, so a starter's profile
 * block keeps the same handle and avatar.
 */
class IdentityAccountShellProvider implements AccountShellProvider
{
    public function shellFor(?Authenticatable $user): ?AccountShellData
    {
        if ($user === null) {
            return null;
        }

        // data_get reads an Eloquent user through ArrayAccess and any other Authenticatable by property.
        $email = (string) (data_get($user, 'email') ?? '');
        $name = (string) (data_get($user, 'name') ?? $email);

        return new AccountShellData(
            plan: null,
            profile: new ProfileData(
                handle: '@'.strtok($email !== '' ? $email : 'you', '@'),
                avatar: $this->initials($name),
            ),
            account: new AccountData(email: $email),
        );
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)), array_filter($parts));

        return implode('', array_slice($letters, 0, 2)) ?: '·';
    }
}
