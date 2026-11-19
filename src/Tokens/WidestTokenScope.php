<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\DecisionNameSource;

/**
 * The widest named set a user can scope a token to (ADR 0006, "Everything I hold changes meaning"): the user's
 * permission-name snapshot plus the names every {@see DecisionNameSource} in
 * `beam.accounts.tokens.decision_name_sources` says the user can reach.
 *
 * This is what an empty or "everything" personal-token request becomes ({@see Kinds\PersonalAccessTokenKind}),
 * and the ceiling the Tokens page picker validates against. Never `*`.
 */
class WidestTokenScope
{
    /** @return list<string> */
    public function for(Authenticatable $user): array
    {
        $names = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->all()
            : [];

        foreach ((array) config('beam.accounts.tokens.decision_name_sources', []) as $source) {
            $resolved = is_string($source) ? app($source) : $source;

            if ($resolved instanceof DecisionNameSource) {
                foreach ($resolved->namesFor($user) as $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $names,
            fn (mixed $name): bool => is_string($name) && $name !== '' && $name !== '*',
        )));
    }
}
