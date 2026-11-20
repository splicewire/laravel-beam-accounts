<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\DecisionNameSource;

/**
 * The widest named set a user can scope a token to (ADR 0006, "Everything I hold changes meaning"): the user's
 * permission-name snapshot plus the names every {@see DecisionNameSource} offers: the cascade's own
 * ({@see CascadeDecisionNameSource}, built in) and each one listed in `beam.accounts.tokens.decision_name_sources`.
 *
 * This is what an empty or "everything" personal-token request becomes ({@see Kinds\PersonalAccessTokenKind}),
 * and the ceiling the Tokens page picker validates against. Never `*`.
 */
class WidestTokenScope
{
    /**
     * Sources read whatever a host's config says: `mergeConfigFrom` merges only the top level, so a host's published
     * `tokens` array replaces the package's whole and would otherwise drop the cascade's names from the widest set.
     */
    public const BUILT_IN_SOURCES = [CascadeDecisionNameSource::class];

    /** @return list<string> */
    public function for(Authenticatable $user): array
    {
        $names = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->all()
            : [];

        $sources = array_unique([...self::BUILT_IN_SOURCES, ...(array) config('beam.accounts.tokens.decision_name_sources', [])], SORT_REGULAR);

        foreach ($sources as $source) {
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
