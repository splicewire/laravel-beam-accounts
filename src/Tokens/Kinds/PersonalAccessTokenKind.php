<?php

namespace Splicewire\Beam\Accounts\Tokens\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Localization\AccountError;
use Splicewire\Beam\Accounts\Tokens\SamePrincipalTokenKind;
use Splicewire\Beam\Accounts\Tokens\WidestTokenScope;
use Splicewire\Beam\Localization\CodedValidationException;

/**
 * `personal-access-token`: a token a user makes for themself on the Tokens page (ADR 0006 rule 3).
 *
 * It is not a login token. An empty request, or one containing `*`, becomes the user's widest named set
 * ({@see WidestTokenScope}), never `*`. Any other request must sit inside that set, or it is refused with
 * `token_abilities`, the same code the Tokens page already answers with.
 */
class PersonalAccessTokenKind extends SamePrincipalTokenKind
{
    /** The kind's registry name ({@see \Splicewire\Beam\Accounts\Tokens\TokenKindRegistry::BUILT_IN}). */
    public const NAME = 'personal-access-token';

    public function __construct(private WidestTokenScope $widest) {}

    public function abilities(Authenticatable $principal, ?array $requested): array
    {
        $widest = $this->widest->for($principal);
        $requested = array_values(array_unique($requested ?? []));

        if ($requested === [] || in_array('*', $requested, true)) {
            return $widest;
        }

        $overreach = array_values(array_diff($requested, $widest));

        if ($overreach !== []) {
            throw CodedValidationException::for('abilities', AccountError::TokenAbilities, ['permissions' => implode(', ', $overreach)]);
        }

        return $requested;
    }
}
