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

    // Gate what configurePackage() declares via ->hasMigrations(). These control PUBLISH
    // (vendor:publish / splicewire:beam:install) — copying a .stub onto the host's disk — and never
    // runtime registration, which publish-only stubs do not do regardless of these flags. They were
    // named `register_*` until beam-docs-satellite ticket 25; the old keys are still honoured (see
    // below) but say the opposite of what they do, three lines under a docblock that gets it right.
    //
    // OFF IS A CLAIM, AND THE INSTALLER VERIFIES IT. Turning one of these off asserts "every member
    // of that estate is already committed on my disk" — which is what all three starters mean, having
    // published once and committed the output. `splicewire:beam:install` now checks the claim
    // ({@see \Splicewire\Beam\Doctor\PublishGateCoverageAudit}), because tower's `420f9e0` turned the
    // gate off, deleted its own `users` and `passkeys` creates, and nothing noticed until a fresh
    // install could not build a database at all.
    //
    //  - publish_auth_migrations — users/permission_tables/passkeys/PAT-create/PAT-provenance/the
    //    tenant identity estate.
    //  - publish_migrations — the teams/memberships/invitations/access-grants/share-links/
    //    view-requests estate (shared/ — identical schema on every connection; see
    //    shared/create_teams_table.php.stub's docblock). A host running its own separate team
    //    system turns this off so these tables are never published, matching splicewire-app.
    'publish_auth_migrations' => env('ACCOUNT_PUBLISH_AUTH_MIGRATIONS', env('ACCOUNT_REGISTER_AUTH_MIGRATIONS', true)),
    'publish_migrations' => env('ACCOUNT_PUBLISH_MIGRATIONS', env('ACCOUNT_REGISTER_MIGRATIONS', true)),

    // Wire Fortify as the default auth substrate (registration/reset actions +
    // login/two-factor rate limiters). A host that consumes only the code primitive
    // (models/contracts/enum/traits) over its OWN auth — e.g. the platform app on
    // Sanctum, not Fortify — turns this off to keep its auth surface untouched.
    'bootstrap_fortify' => env('ACCOUNT_BOOTSTRAP_FORTIFY', true),

    // The SPA reset route the reset-link email points at, carrying the broker token +
    // email in the query string. Default targets the beam SPA under /ui; a host with a
    // different SPA host/path overrides via env without touching the notification class.
    'password_reset_url' => env('PASSWORD_RESET_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/ui/reset-password'),

    // Prefix + route middleware for the settings surface.
    'routes' => [
        'prefix' => 'settings',
        'middleware' => ['web', 'auth'],
    ],

    // Name given to the personal team provisioned on registration. {name} is the user's name.
    'personal_team_name' => "{name}'s Team",

    // ⚠️ There is no `data.auth_user` slot any more (particle-contribution-seam 18). It held ONE
    // class-string, so two packages could never both add props to the auth projection — which is
    // why a commerce concept and an embed concept had to meet in the top host instead of shipping
    // with the packages that own them. A package now adds its own named slice of the `me` read
    // projection through the particle contribution seam
    // ({@see \Splicewire\Beam\Particle\Contribution\ResourceContribution}), which composes.

    // Demo subjects + the `splicewire:beam:accounts:login-as` affordance — a standardized way to land in
    // the app as a known subject at a known access level (owner/admin/member/solo) and
    // verify the account/billing/admin surfaces gate correctly. A development/preview
    // convenience, never for real end-users.
    'demo' => [
        // Master switch. null (default) = on in every non-production environment, off in
        // production. Set true to allow in a preview deploy — there the login-as links
        // must be signed (the artisan command mints them), so it opens no hole.
        'enabled' => env('ACCOUNT_DEMO_ENABLED'),

        // The config GATE for the DemoTeamSeeder's registration in the beam-seed manifest
        // (splicewire:beam:seed). The seeder registers unconditionally from the provider, but
        // this key decides whether it actually runs — so a production `beam:seed` never fabricates
        // demo subjects. null (default) mirrors `Demo::enabled()` (on everywhere but production);
        // set false to suppress demo seeding even in a preview, or true to force it. Explicit
        // config wins; the provider resolves the null→non-production fallback at registration.
        'seed_users' => env('ACCOUNT_SEED_DEMO_USERS'),

        // Deterministic credentials the DemoTeamSeeder provisions and login-as targets.
        'password' => env('ACCOUNT_DEMO_PASSWORD', 'password'),
        'email_domain' => env('ACCOUNT_DEMO_EMAIL_DOMAIN', 'example.test'),

        // Where a successful demo login lands. Satellites point this at their home.
        'redirect' => '/',
    ],

    /*
     * Operator impersonation (particle-identity-resources ticket 03) — the audited "act as this
     * customer" power, distinct from the demo login-as above.
     */
    'impersonation' => [
        // The table backing ImpersonationEvent. Null ⇒ `beam_impersonation_events` via the beam
        // table-prefix seam. A host that ALREADY has an `impersonation_events` table (audiostud,
        // numero) points this at it and skips the published migration — keeping its existing rows
        // and its typed foreign keys, with no data migration at all.
        'table' => null,

        // There is NO `staff_ability` key here, and that is deliberate. "This account is STAFF" —
        // the fact UserPolicy uses to refuse impersonating a peer operator — is
        // `entitlement:os.operate`, hardcoded in the policy. The estate has one staff vocabulary;
        // a config key here could only ever be a way to reintroduce a second one
        // (particle-identity-resources ticket 04, which removed the migration bridge that briefly
        // lived at this spot).

        // The ability gating the ACTOR on `impersonate` — may this principal impersonate anyone?
        // The SUBJECT-side rule (not yourself, never another staff account) is UserPolicy and is not
        // configurable. audiostud gates on its own `bypass-marquee`; the estate default is the
        // operator entitlement.
        'ability' => 'entitlement:os.operate',

        // Where each half lands. A route NAME is preferred (survives URI moves) and is resolved when
        // one exists; anything else is used as a literal path.
        'start_redirect' => '/',
        'stop_redirect' => '/',
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
    // HOST-FACING affordance: the `splicewire:beam:accounts:mint-key` artisan command, so a
    // non-satellite beam site that never seeds keys gets nothing extra. Default-off,
    // mirroring the `api` seam — prepared, opt-in, no rebuild to activate.
    'keys' => [
        'enabled' => env('ACCOUNT_KEYS_ENABLED', false),

        // The table the deterministic minter upserts into (Sanctum's shape). Override
        // only if the host renamed its personal-access-tokens table.
        'table' => 'personal_access_tokens',
    ],

    // The per-host OIDC-ISSUER seam (tenant-database-upsell ticket 16): tower proves its own
    // identity to any OIDC-federation-capable consumer (GCP Workload Identity Federation,
    // most immediately) via a self-hosted JWKS + short-lived self-signed JWTs, never a static
    // secret. Per-host, never cross-host — mirrors the `keys` seam's posture exactly, just for
    // an outbound identity instead of an inbound bearer. Default-off; a host turns this on,
    // runs `splicewire:beam:accounts:oidc:generate-signing-key` once, and the
    // `/.well-known/openid-configuration` + `/.well-known/jwks.json` routes go live.
    'oidc' => [
        'enabled' => env('ACCOUNT_OIDC_ENABLED', false),

        // Tower's issuer URL — must be the real, publicly-reachable HTTPS origin a federation
        // consumer's own outbound fetch of `{issuer}/.well-known/openid-configuration` resolves
        // against. Defaults to APP_URL; override only if the issuer must differ (e.g. a
        // dedicated subdomain).
        'issuer' => env('ACCOUNT_OIDC_ISSUER', rtrim((string) env('APP_URL', 'http://localhost'), '/')),

        // Where the RSA signing keypair lives on disk. Outside the webroot and outside any
        // publish-only stub estate — this is host-generated secret material, never shipped by
        // the package and never checked in.
        'signing_key_path' => env('ACCOUNT_OIDC_SIGNING_KEY_PATH', storage_path('app/private/oidc-signing-key.pem')),
    ],

    // Reusable capability links (ADR-0009, tracer 05): the ShareLink primitive + ShareLinks
    // action are always callable from PHP; this flag gates the HOST-FACING affordance (the
    // satellite's /s/{token} resolver + copy-link UI, tracer 06). Mirrors the `keys`/`api`
    // seams — prepared, opt-in.
    'share_links' => [
        'enabled' => env('ACCOUNT_SHARE_LINKS_ENABLED', true),
    ],

    // Declarative named ENTITLEMENT BUNDLES (Frame OS ticket 09, ADR-0013 §3/§5). A bundle is a
    // named Set<entitlementKey> — the reusable product-facing unit a plan maps to. A host declares
    // its bundles here (`name => [keys]`), maps a plan to a bundle name, and resolves a principal's
    // effective set as `plan-baseline ∪ grants − denies` via the EntitlementComposer. Empty by
    // default (an unconfigured host holds nothing — the null-default discipline, ADR-0009). This is
    // the CONSUMER/host-principal path; the multi-tenant commerce cascade
    // (Splicewire\Beam\Commerce\Entitlements\EntitlementResolver) keeps the tenant principal.
    //
    //   'entitlements' => [
    //       'bundles' => [
    //           'own-a-song'    => ['own-a-song', 'publish'],
    //           'go-songwriter' => ['own-a-song', 'go-songwriter', 'publish', 'generate'],
    //       ],
    //   ],
    //
    // The DEFAULT resolver (Entitlements\DefaultEntitlementResolver) — bound OOTB unless the host set
    // `config('permission-cascade.entitlement_resolver')` — carries NO staff flag (ACC-01 retired `is_staff`
    // entirely). It grants `author-ux-{realm}` (+ the coarse `author-ux` alias, + `os.enter`/`app-operator`
    // for the `operator` realm specifically) off `manage` grants an Owner/Admin-held Team holds on a realm's
    // root entry — data, not a boolean column. A host that wants plan/grant logic binds its own resolver
    // (which wins) and this default steps aside.
    'entitlements' => [
        'bundles' => [
            //
        ],

        // The realm-root lookup port (Entitlements\Contracts\RealmGrantable) DefaultEntitlementResolver's
        // grant cascade rides — unbound by default (null-default discipline, ADR-0009): with no realm roots
        // to resolve, the cascade grants nothing. `splicewire/laravel-beam-ux` binds its own OOTB
        // implementation (BeamUxEntry IS the realm root) when installed.
        'realm_grantable' => null,
    ],

    // The team-admin resources (Members / Invitations) scope to the acting request's team. The
    // domain-neutral default is the current user's current-or-personal team; a host whose "active
    // team" is a different notion (e.g. a per-request TENANT) binds a resolver here — a `(): ?object`
    // callable returning the scope object whose `getKey()` is the invitations/memberships `team_id`.
    'teams' => [
        'resolver' => null,
    ],

    // The API-tokens resource. `model` is the PAT model the list/revoke reads (a host with a bespoke
    // PAT — its own connection, a uuid key — binds its class; null = the package's own model). `scope`
    // is the load-bearing row-level isolation: an `(Builder, ?Authenticatable): Builder` callable; the
    // default scopes to the authenticated principal's OWN tokens.
    'tokens' => [
        'model' => null,
        'scope' => null,

        // The connection `personal_access_tokens` lives on. null = the app default, which is the only
        // answer a non-tenanted host can use. A tenanted host whose tokens live on the shared central
        // database sets 'central' here.
        //
        // This is a config key rather than a `protected $connection` on the model because a hardcoded
        // one is unoverridable off the host it was written for: `splicewire/tower`'s token model pinned
        // 'central' (beam-docs-satellite ticket 25). That resolves fine wherever stancl/tenancy clones
        // the default connection into `central` at boot — which is why it never broke — and is
        // unusable on a host composing tower without that step.
        'connection' => null,
    ],

    // The users resource — the identity roster. `scope` is the load-bearing row-level isolation on
    // the widest shared table in the package: an `(Builder, ?Authenticatable): Builder` callable
    // applied to BOTH the list and the per-record read, so the two can never disagree. The default
    // shows the acting principal themselves plus everyone they share a team with; a CENTRAL Root
    // principal sees all; an unauthenticated caller sees nothing. Bind this when your seats don't
    // live on beam's memberships table. There is no `model` key — the user model already has one
    // seam, the top-level `user_model` above; the resource's ATTRIBUTE literal is overridden by
    // subclassing the DTO (attributes cannot read config).
    'users' => [
        'scope' => null,
    ],
];
