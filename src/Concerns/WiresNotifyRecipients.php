<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Notify\MembershipDirectory;
use Splicewire\Beam\Accounts\Notify\RolesRecipientKind;
use Splicewire\Beam\Accounts\Notify\TeamsRecipientKind;
use Splicewire\Beam\Notifications\Contracts\AccountsDirectory;

/**
 * One concern of {@see BeamAccountsServiceProvider}: this package's contribution to the
 * `x-beam-notify` keyword — the `to_roles:` / `to_teams:` recipient kinds and the directory port they
 * resolve through (beam-facade 100, built by 159).
 *
 * ## Why there is no `class_exists('…\RecipientKind')` guard here
 *
 * Because none is needed, and that is the whole reason the registry shape won over the two the ticket
 * originally offered. `::class` resolves at compile time without autoloading, and a config VALUE is an
 * inert string — so on a host without `splicewire/laravel-beam-notifications` this writes two entries
 * and one binding that nothing ever reads, and no class from that package is ever loaded. Add the
 * notify package and the same entries become live with no code change on either side.
 *
 * The alternative this replaced was the reverse: beam-notifications' docblock claimed this package
 * REBOUND its `RecipientResolver`. It never did — measured 2026-08-24 and 2026-08-26, zero occurrences
 * of `RecipientResolver`, `to_roles` or `to_teams` anywhere in this package — so half the keyword threw
 * at every host in the family while the documentation read as one composer require away.
 *
 * ## Appending, not assigning
 *
 * `config()->set()` on the leaf key, never on `recipient_kinds` wholesale: the notify package registers
 * `to` there and a host may register its own kinds in its published config file. Writing the array
 * would silently amputate both. Late is fine — `RecipientKindRegistry` is a `ConfigRegistry`, which
 * reads through to the repository on every read rather than snapshotting at construction, which is
 * exactly what lets a boot this far down the chain contribute at all.
 *
 * ## …and this is a `boot` link, not a `register` one, for a reason worth keeping
 *
 * `mergeConfigFrom()` — what package-tools' `hasConfigFile()` calls — is a **shallow, top-level**
 * `array_merge($packageDefaults, $whateverIsAlreadyThere)`. So if this ran at REGISTER time and
 * happened to run before beam-notifications' own register, the notify package's later merge would see
 * a `beam.notifications` key already present, keep it wholesale, and its own `to` kind would be gone —
 * a keyword that resolves roles and teams and silently stops mailing literal addresses.
 *
 * Laravel runs every provider's `register()` before any provider's `boot()`, so a contribution made in
 * boot cannot lose that race regardless of provider order. Measured while writing the test for it: a
 * harness that registered the notify provider late reproduced exactly that erasure.
 */
trait WiresNotifyRecipients
{
    #[Chained('boot', order: 170)]
    protected function bootNotifyRecipients(): void
    {
        $this->app->bind(AccountsDirectory::class, MembershipDirectory::class);

        config()->set('beam.notifications.recipient_kinds.to_roles', RolesRecipientKind::class);
        config()->set('beam.notifications.recipient_kinds.to_teams', TeamsRecipientKind::class);
    }
}
