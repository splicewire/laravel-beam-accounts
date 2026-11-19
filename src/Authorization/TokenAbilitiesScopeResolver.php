<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Closure;
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

    /** The Sanctum validation callback this class installed, so installing it again is a no-op. */
    private static ?Closure $refusal = null;

    /**
     * Fleet ADR 0006 C5: while `beam.accounts.api.scope_enforcement` is off, Sanctum refuses a bearer whose token is
     * scoped (abilities other than `*`), so the token authenticates nobody and gains nothing; a `*` token, a session
     * and an unknown token are unchanged. While enforcement is on, the callback passes the guard's own answer through
     * and the scope narrows the token instead.
     *
     * The setting is read on every validation, not at install, so one installation serves both states. A callback a
     * host installed before this one still runs after it, but only for a token this veto lets through: neither the
     * guard's own answer nor that callback can admit a scoped token while enforcement is off.
     */
    public static function refuseScopedTokensWhileUnenforced(): void
    {
        if (! class_exists(Sanctum::class)
            || (self::$refusal !== null && Sanctum::$accessTokenAuthenticationCallback === self::$refusal)) {
            return;
        }

        $previous = Sanctum::$accessTokenAuthenticationCallback;

        self::$refusal = static function ($token, bool $isValid) use ($previous): bool {
            // An unconditional veto, ahead of the host's callback and whatever validity Sanctum computed: a callback that
            // turns false into true must not admit a scoped token either (codex cs02, blocking 2).
            if (! config('beam.accounts.api.scope_enforcement', false) && self::isScoped($token)) {
                return false;
            }

            return is_callable($previous) ? (bool) $previous($token, $isValid) : $isValid;
        };

        Sanctum::authenticateAccessTokensUsing(self::$refusal);
    }

    /** Whether a token row carries a real scope: an ability list without `*` (null abilities are `*`). */
    public static function isScoped(mixed $token): bool
    {
        $abilities = $token->abilities ?? ['*'];

        return ! in_array('*', (array) $abilities, true);
    }

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

        // '*' is the unscoped default (login tokens); an explicit list is a real scope.
        if (! self::isScoped($token)) {
            return null;
        }

        return array_values((array) $token->abilities);
    }
}
