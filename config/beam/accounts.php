<?php

return [
    // The session guard the account surface runs on. Satellites are session/cookie
    // consumer apps — the token 'api' guard is a separate, opt-in door (issue 07).
    'guard' => 'web',

    // The Authenticatable model registrations create and the profile surface edits.
    // null falls back to the default auth provider model.
    'user_model' => null,

    // The tenant-side (per-tenant replica) user model the central User syncs into, as a
    // SyncMaster. The base User names it via config rather than importing a host class, so
    // beam-accounts stays host-agnostic (ADR-0138). A host that resource-syncs users into
    // tenant schemas binds this to its own model (e.g. \App\Models\TenantUser::class);
    // null is a safe null-object (the framework Authenticatable).
    'tenant_user_model' => null,

    // Register the package's settings routes (profile/security). Turn off if the host
    // wants to wire the macro itself.
    'register_routes' => env('ACCOUNT_REGISTER_ROUTES', true),

    // Wire Fortify as the default auth substrate (registration/reset actions +
    // login/two-factor rate limiters). A host that consumes only the code primitive
    // (models/contracts/enum/traits) over its OWN auth — e.g. the platform app on
    // Sanctum, not Fortify — turns this off to keep its auth surface untouched.
    'bootstrap_fortify' => env('ACCOUNT_BOOTSTRAP_FORTIFY', true),

    // The SPA reset route the reset-link email points at, carrying the broker token +
    // email in the query string. Default targets the beam SPA under /ui; a host with a
    // different SPA host/path overrides via env without touching the notification class.
    'password_reset_url' => env('PASSWORD_RESET_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/ui/reset-password'),

    // Load the package's teams/memberships/invitations migrations. A host composing the
    // primitive over its own tables (the platform app over `tenant_users`) turns this
    // off so the engine tables are never created.
    'register_migrations' => env('ACCOUNT_REGISTER_MIGRATIONS', true),

    // Prefix + route middleware for the settings surface.
    'routes' => [
        'prefix' => 'settings',
        'middleware' => ['web', 'auth'],
    ],

    // Name given to the personal team provisioned on registration. {name} is the user's name.
    'personal_team_name' => "{name}'s Team",

    // Demo subjects + the `splicewire:beam:account-login-as` affordance — a standardized way to land in
    // the app as a known subject at a known access level (owner/admin/member/solo) and
    // verify the account/billing/admin surfaces gate correctly. A development/preview
    // convenience, never for real end-users.
    'demo' => [
        // Master switch. null (default) = on in every non-production environment, off in
        // production. Set true to allow in a preview deploy — there the login-as links
        // must be signed (the artisan command mints them), so it opens no hole.
        'enabled' => env('ACCOUNT_DEMO_ENABLED'),

        // Deterministic credentials the DemoTeamSeeder provisions and login-as targets.
        'password' => env('ACCOUNT_DEMO_PASSWORD', 'password'),
        'email_domain' => env('ACCOUNT_DEMO_EMAIL_DOMAIN', 'example.test'),

        // Where a successful demo login lands. Satellites point this at their home.
        'redirect' => '/',

        // URL prefix for the signed login-as route.
        'login_as_prefix' => 'account/login-as',
    ],

    // The "proprietary API layer" seam — a satellite's second door, for exposing its
    // own token-authenticated API to its own end-users/mobile clients. Prepared but
    // NOT provisioned: default-off, wired to no consumer, no endpoints. When a real
    // consumer appears, install laravel/sanctum and flip `enabled` — no rebuild.
    //
    // Sanctum (not Passport): a satellite exposing its own API wants
    // personal-access-tokens + SPA-cookie auth, not a full OAuth2 authorization server.
    // Passport stays the platform's concern (the operator console is the OAuth provider).
    'api' => [
        'enabled' => false,
        'guard' => 'api',
        'driver' => 'sanctum',
        // null falls back to the same provider as the web guard.
        'provider' => null,

        // Scoped-PAT enforcement (ADR-0109). When true, the acting API token's abilities
        // become the permission-cascade's credential-scope, so a scoped token can do at
        // most `token abilities ∩ the user's live permissions`. Default-off and a pure
        // no-op when off. Blast-radius control, not a trust boundary — a scoped token is
        // still not safe to hand to an untrusted party. A host that mints scoped PATs
        // (its own Tokens UI) flips this on.
        'scope_enforcement' => false,
    ],

    // The per-host KEY-MANAGEMENT seam. beam operates separately from splicewire, so a
    // beam site manages keys only for ITSELF — each site owns its own keys, there is no
    // central token store and no cross-host reach. The core primitive
    // (Keys\DeterministicToken — a reproducible, reset-surviving PAT minter) is always
    // available to call from PHP (seeders use it directly); this toggle only gates the
    // HOST-FACING affordance: the `splicewire:beam:accounts-mint-key` artisan command, so a
    // non-satellite beam site that never seeds keys gets nothing extra. Default-off,
    // mirroring the `api` seam — prepared, opt-in, no rebuild to activate.
    'keys' => [
        'enabled' => env('ACCOUNT_KEYS_ENABLED', false),

        // The table the deterministic minter upserts into (Sanctum's shape). Override
        // only if the host renamed its personal-access-tokens table.
        'table' => 'personal_access_tokens',
    ],

    // Reusable capability links (ADR-0009, tracer 05): the ShareLink primitive + ShareLinks
    // action are always callable from PHP; this flag gates the HOST-FACING affordance (the
    // satellite's /s/{token} resolver + copy-link UI, tracer 06). Mirrors the `keys`/`api`
    // seams — prepared, opt-in.
    'share_links' => [
        'enabled' => env('ACCOUNT_SHARE_LINKS_ENABLED', true),
    ],
];
