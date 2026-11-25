# Package API roots (expand, owner-review 13c)

Accounts exports are inert. The host calls each inside its own authentication and tenancy boundary, outside its version prefix, before a site catch-all. All use `beam.accounts.api_root` (default `beam/accounts`). Keep the old mounts until the owner authorizes ticket 16.

| Export | Host enclosure | Legacy source kept |
|---|---|---|
| `AccountsRoutes::auth()` | guest/central API | `splicewireAccountsAuthV1` |
| `AccountsRoutes::session()` | central authenticated API | `splicewireAccountsSessionV1` |
| `AccountsRoutes::sessionToken()` | explicit web session bridge | `splicewireAccountsSessionTokenV1` |
| `AccountsRoutes::me()` | authenticated tenant API | `splicewireAccountsMeV1` |
| `AccountsRoutes::mount()` | authenticated account API | `splicewireAccountApiRoutes` / account-api.php |
| `AccountsRoutes::sharingLedger()` | optional authenticated API | `Sharing::ledgerResources` |

There is no aggregate across these guards. `mount()` does not opt into sharing ledgers. `sharingLedger()` exposes only access-grants/view-requests indexes and the particle mounter's standard scoped hook-event catalogs; it carries the existing resource declarations, scopes and abilities. Sharing operations attached by `Sharing::attachTo` remain at their parent's root. Account/team pages and OAuth browser redirects remain host/realm URLs.

The default account API macro resolves the same package key; its explicit legacy prefix parameter is retained. No provider automatically mounts the new API exports. The new exports are named `beam.accounts.api.*`, so a host can mount them beside a legacy exposure at ANOTHER prefix (the flagship's api/v1/beam/accounts) without a name collision. The bare legacy macro shares the exports' root (`beam.accounts.api_root`), so do not mount it beside `mount()`: their URIs overwrite each other whichever registers second, and after `route:cache` the replaced names are gone. Runtime discovery mounted after boot must be refreshed by the host as documented by Beam. The flagship's composition and generated clients move in 14a, not this package expand.

Root01:42Z (the commerce precedent, applied to accounts in the 13c amend): the new exports use `beam.accounts.api.*` names to coexist with the legacy `beam.accounts.*`. The flagship's legacy mount (`routes/tenant.php`, api/v1/beam/accounts: tokens, members, invitations) keeps its names, so mounting both no longer collides at `route:cache`. The host renames nothing; 14a adopts the new names. Until then, by-name lookups in this package (`TokensEndpoints`) resolve the legacy names, so they need the legacy mount; ticket15 moves them to the adopted canonical route. Ticket16 returns the one `NAME_PREFIX` constant in `AccountsRoutes` to `beam.accounts.` when the legacy mounts are removed.

The bare legacy macro (`splicewireAccountApiRoutes()` with no prefix) keeps the legacy `beam.accounts.*` names: internally it mounts the same routes at the same root under the legacy stem through `AccountsRoutes::mountLegacy()`, which is `@internal` (hosts call the macro, not that method), and it now enforces ApiRoot catch-all ordering as well; the explicit-prefix path preserves its previous mounting behavior. An empty/slash-only configured root uses the package default. ApiRoot requires Beam at or beyond ticket12's c583da31; the landing owner must resolve that prerequisite before any dependent package. Live development checkouts can still predate it and must be brought forward by their owner.

Excluded browser team setup/redeem POST operations remain with their web guest/auth page flow, just like OAuth (coordinator01:46Z). This does not turn UserData/TeamData's Frame declarations into generic REST CRUD. The API token/member/invitation endpoints already mount from account-api.php. Ticket15 must make TokensEndpoints resolve the adopted `beam.accounts.api.*` route; until then a host keeps a legacy mount, whose `beam.accounts.*` names TokensEndpoints reads. See Beam docs/core-routes.md for post-boot ResourceDiscoveryAutoMounter refresh when a host adds routes after boot.
