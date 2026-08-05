<?php

namespace Splicewire\Beam\Accounts\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a Sanctum personal access token came from — the signal that drives the settings
 * Tokens page's type chip + facet filter (composition-authoring-ux ticket 10). Stamped at
 * every creation site; legacy rows were backfilled once via {@see self::infer()}. Column is
 * authoritative thereafter — the inference lives only in the backfill migration (and a
 * defensive null-fallback), never as the read-time source of truth.
 */
#[TypeScript]
enum TokenProvenance: string
{
    /** A token the user deliberately created on the Tokens page (incl. the `*-satellite` PATs). */
    case Api = 'api';

    /** A browser/login session token, minted per auth response and named after the User-Agent. */
    case Session = 'session';

    /** A local-only developer token: `dev-login-as`, `dev-verify`, seeded `PostmanRuntime` churn. */
    case Dev = 'dev';

    /** A `broker:{slug}` provisioning token minted by the tenant grant pipeline. */
    case Broker = 'broker';

    /** A token minted by a passwordless passkey (WebAuthn) sign-in (login-branding-passkey ticket 09). */
    case Passkey = 'passkey';

    /** A `federation-resolve:{grant}` token minted at grant issuance for the resolve loopback (ADR-0126). */
    case Federation = 'federation';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'API',
            self::Session => 'Session',
            self::Dev => 'Dev',
            self::Broker => 'Broker',
            self::Passkey => 'Passkey',
            self::Federation => 'Federation',
        };
    }

    /**
     * Best-effort classification from a token's name + abilities. Used ONLY to backfill
     * unstamped legacy rows (in the migration) and as a defensive fallback when the column
     * is null — never as the ongoing read-time mechanism. Nothing is hidden on the strength
     * of this, so a mis-classification is cosmetic (wrong chip), never a hidden credential.
     *
     * @param  array<int, string>  $abilities
     */
    public static function infer(string $name, array $abilities): self
    {
        if (str_starts_with($name, 'broker:') || in_array('tenant:provision', $abilities, true)) {
            return self::Broker;
        }

        if (str_starts_with($name, 'federation-resolve:') || in_array('federation:resolve', $abilities, true)) {
            return self::Federation;
        }

        if (in_array($name, ['dev-login-as', 'dev-verify'], true) || str_starts_with($name, 'PostmanRuntime')) {
            return self::Dev;
        }

        // Session tokens are named after the raw User-Agent (AuthUserResource), which effectively
        // always begins with the "Mozilla/…" product token for real browsers.
        if (str_starts_with($name, 'Mozilla/') || str_contains($name, 'HeadlessChrome')) {
            return self::Session;
        }

        return self::Api;
    }
}
