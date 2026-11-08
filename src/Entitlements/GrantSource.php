<?php

namespace Splicewire\Beam\Accounts\Entitlements;

/**
 * A derived grant (tutorial-dx 04): keys a principal holds for a reason other than its plan, e.g. calcucrypt's
 * `plans.purchase` for a team's Owner or Admin, or the market starter's billing key for an operator. List the class in
 * `beam.accounts.entitlements.grants`.
 *
 * Its keys are STATIC: {@see keys()} declares every key it can ever grant, so beam's key universe defines their abilities
 * and the undefined-key audit counts them before any principal is resolved. Granting a key it did not declare is refused.
 */
interface GrantSource
{
    /** @return list<string> every key {@see grantsFor()} can return */
    public static function keys(): array;

    /** @return list<string> the keys `$principal` holds from this source, a subset of {@see keys()} */
    public function grantsFor(mixed $principal): array;
}
