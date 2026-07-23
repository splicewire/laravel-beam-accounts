<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;

/**
 * Sources the permission-cascade's credential-scope from the acting Sanctum PAT's
 * abilities — the one Sanctum-aware piece of the scoped-token feature (ADR-0109).
 *
 * A scoped PAT carries a subset of permission-cascade names in its `abilities` column;
 * `['*']` means "unscoped — act fully as the user" (Sanctum's wildcard and this system's
 * default). This resolver maps the acting request's bearer token to that scope so the
 * cascade can intersect it with the user's live permissions. The token half can only ever
 * subtract; the cascade owns the authority ceiling.
 *
 * The token is resolved from the bearer (not `currentAccessToken()`) because the acting
 * principal on a tenant request is a TenantUser, which has no Sanctum token relation — the
 * central User owns the token. Mirrors ApiTokenController's own bearer lookup.
 */
class TokenAbilitiesScopeResolver implements CredentialScopeResolver
{
    /** @var array<string, array<int, string>|null> */
    private array $memo = [];

    public function resolve(): ?array
    {
        $bearer = request()?->bearerToken();

        // No bearer → cookie/session/SPA or unauthenticated → unscoped (unchanged).
        if (! $bearer) {
            return null;
        }

        if (array_key_exists($bearer, $this->memo)) {
            return $this->memo[$bearer];
        }

        return $this->memo[$bearer] = $this->scopeForBearer($bearer);
    }

    private function scopeForBearer(string $bearer): ?array
    {
        /** @var class-string<PersonalAccessToken> $model */
        $model = Sanctum::personalAccessTokenModel();
        $token = $model::findToken($bearer);

        // Unknown/expired token → unscoped; the auth guard rejects it independently.
        if (! $token) {
            return null;
        }

        $abilities = $token->abilities ?? ['*'];

        // '*' is the unscoped default (today's tokens); an explicit list is a real scope.
        if (in_array('*', $abilities, true)) {
            return null;
        }

        return array_values($abilities);
    }
}
