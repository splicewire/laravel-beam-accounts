<?php

namespace Splicewire\Beam\Accounts\Entitlements;

/**
 * Where a COMPUTED plan lives (tutorial-dx 04): name a class implementing this in `beam.accounts.entitlements.plan` when
 * the principal's plan is not a stored value a `data_get` path can read, e.g. calcucrypt's
 * `$user->currentTeamOrPersonal()->plan`, a method rather than an attribute or a relation. A stored plan (`plan_tier`,
 * `team.plan` over a real relation) needs no class: put the path in the same setting.
 *
 * The answer is one bundle name, several, or null for none (`plan_default` then applies). Resolved from the container,
 * so a source may inject what it needs.
 */
interface PlanSource
{
    /** @return string|list<string>|null the bundle name(s) `$principal`'s plan composes */
    public function planFor(mixed $principal): string|array|null;
}
