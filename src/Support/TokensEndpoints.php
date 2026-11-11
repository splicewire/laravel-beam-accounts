<?php

namespace Splicewire\Beam\Accounts\Support;

/**
 * The tokens page's endpoints, built from this package's own mounted route names (frame-foundation 51).
 *
 * `Route::splicewireAccountApiRoutes()` mounts the token routes under `beam.accounts.api_root` and names them
 * `beam.accounts.tokens.*`. A host passes these to its tokens page as the `endpoints` prop, and
 * `@splicewire/beam-accounts`' `createTokensClient(request, endpoints)` sends to them, so no host writes the root and a
 * moved `ACCOUNT_API_ROOT` moves the page with it. The keys match `TokensEndpoints` in `@splicewire/beam-accounts`;
 * a record's routes hang off `index` (`{index}/{id}`, `/renew`, `/rotate`, `/permanent`).
 *
 *     Route::get('account/tokens', fn () => Inertia::render('account/tokens', ['endpoints' => TokensEndpoints::props()]));
 *
 * Call it once the API routes are mounted: an unmounted route name throws, rather than handing the page a URL that
 * 404s.
 */
final class TokensEndpoints
{
    /** @return array{index: string, permissions: string, sessionsOthers: string} */
    public static function props(): array
    {
        return [
            // tokens.index (GET) and tokens.store (POST) share this URL.
            'index' => route('beam.accounts.tokens.index', absolute: false),
            'permissions' => route('beam.accounts.tokens.permissions', absolute: false),
            'sessionsOthers' => route('beam.accounts.tokens.sessions.others', absolute: false),
        ];
    }
}
