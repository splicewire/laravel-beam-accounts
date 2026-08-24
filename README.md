# laravel-beam-accounts

The **account engine** of the schemastud stack — a capability peer of the beam family.
It is the one engine primitive both team stacks (platform tenant-membership and the
satellite account runtime) reduce onto.

```
beam            (app substrate)
beam-accounts   (account engine — Fortify default + self-service account UI + team primitives)   ← this package
```

## What it owns (engine)

- **Fortify as the default auth substrate** — session/cookie auth, wired by the service
  provider (registration + password-reset actions bound to Fortify's contracts, login +
  two-factor rate limiters). **Not Passport** — session/Fortify is the engine default, an
  overridable seam rather than a per-satellite fork.
- **The self-service account runtime** — the `settings/profile` + `settings/security`
  Inertia surface (`ProfileController`, `SecurityController`), their form requests, and the
  `Password`/`Profile` validation-rule concerns.
- **The generic team primitives** — `Team` / `Membership` / `Invitation` models, the
  `BelongsToTeams` account concern, `TeamProvisioner` (team-of-one) + `TeamMembers`
  (invite → accept → change-role → remove), the `Roles` vocabulary, and the
  `SetCurrentTeamPermissions` middleware, all on the `permission-cascade` base leaf.
- **The auth support types** — `Enums\TokenProvenance` (where a Sanctum PAT came from),
  `Auth\AuthTokenFactory` (the single session/login-token mint), and `Support\CentralRoot`
  (the flip-safe central-Root check). Relocated here from `Splicewire\Tower\*` (HTTP-04) so
  the token/root logic lives in the account engine and its consumers call *down* instead of up.
- **The auth API HTTP surface** — five `Http/Controllers/Api/V1/*` on the canonical InputData
  shape (`Login`, `PasskeyLogin`, `PasswordReset`, `Passkey` CRUD, and the API/JSON `Profile`
  variant beside the Inertia one), plus their `Data/*InputData` bodies. Relocated here from
  `Splicewire\Tower\Api\V1\*` (HTTP-07). Each drops its FormRequest for a typed
  `{Concept}InputData` (static `rules(ValidationContext)`, validated under `OnlyRequests`),
  annotates `#[ResponseFromData(AuthUserData::class)]`, and mints its token IN the controller
  (via `AuthTokenFactory`) ahead of projecting — so `AuthUserData` stays a pure projection.
  The recipe is captured once in the host's ADR-0179.

## The account-shell contract (data-shape only)

The engine also projects a **SHAPE** for the account area — the plan chip, public profile,
account rows, and upsell CTAs a host renders in its dashboard sidebar/account view. This is a
**data-shape contract, NOT a billing engine**: the package owns no Cashier/charge logic, holds
no card data, makes no gateway call, and ships no monetization copy. It defines the shape and a
bindable provider seam; the **host populates every value** from its own product data.

```
AccountShellData {
  plan:    PlanData    { tier, label, credits?, max? }
  profile: ProfileData { handle, avatar?, metrics: MetricData[] { label, value } }
  account: AccountData { email, paymentMethodLabel? }   // label is a DISPLAY STRING, e.g. "Visa •••• 4242"
  upsells: UpsellData[] { key, label, href? }
}
```

A host binds `Splicewire\Beam\Accounts\Contracts\AccountShellProvider` to fill the shape and
projects the result as an Inertia prop (mirroring how it already projects its `accountNav`):

```php
app()->bind(AccountShellProvider::class, App\Account\AudiostudAccountShell::class);
// then in HandleInertiaRequests::share():
'accountShell' => app(AccountShellProvider::class)->shellFor($request->user()),
```

The default binding is `Support\NullAccountShellProvider` (returns `null`), so a host that binds
nothing degrades gracefully — the shell renders empty rather than erroring. The metric semantics
(SONGS / PLAYS / FOLLOWERS), the plan/credit meaning, and all monetization copy are **host product
content**; the package carries only the slots.

## The auth projection (`AuthUserData`) + its host-extension seam

The engine ships the **identity-core** auth projection `Data\AuthUserData` — the DTO that `/me`,
login, passkey-login, and profile-update all converge on. It carries **only** identity fields —
`id, name, email, accessToken, roles, permissions, tenants, isRoot, isDemo, tenant` — and **names no
host type** (no commerce/embed), so beam-accounts never depends *up* on a host package. Build it with
the single seam:

```php
AuthUserData::fromUser($user, $accessToken);   // central-vs-tenant branch; isRoot via Support\CentralRoot
```

A package that owns a concern adds **its own named slice** of the projection through the particle
contribution seam — beam-accounts never learns the field exists, and unlike the two single-slot seams
this replaces, more than one package may contribute at once:

```php
// beam-commerce's own service provider — the package that OWNS entitlements ships them:
$registry->register(new ResourceContribution(
    key: 'me',
    as: 'commerce',
    data: AuthUserCommerceData::class,
    value: fn ($user, $ctx, $filters) => new AuthUserCommerceData(...),
));
```

The slice lands nested under its `as` key (`data.commerce.entitlements`), which is what makes two
packages claiming one sub-projection a loud registration conflict instead of a silent last-wins.

⚠️ The two seams this replaces are **deleted** (particle-contribution-seam 18): a config-swappable
SHAPE class-string (`beam.accounts.data.auth_user`) and a bound VALUE port
(`Contracts\AuthUserExtrasContributor`). Each held exactly one slot, so a commerce field and an embed
field could only meet in a host that saw both packages — which is how two package-owned concepts ended
up hoisted into the top host that owns neither.

**Standalone degrades cleanly:** with no contributing package installed, `GET /me` projects the pure
identity core and each slice key is **absent** — not present-and-empty, which is the distinction the
retired port's `[]` default could not make.

## What stays in the satellite

`splicewire/laravel-satellite-account` composes this engine and keeps only the pieces the
**seam** legitimately differs on: concrete tenant/demo provisioning — the `Demo` subjects,
the `DemoTeamSeeder`, and the `splicewire:beam:accounts:login-as` affordance (command + signed route +
`LogInAs` action). A multi-tenant satellite (Standwell / Entreport) that mints child-tenants
plugs its provisioning in at the same seam. Provisioning is the boundary; the account engine
is uniform.

## Auth guard

Session/Fortify web guard by default. The opt-in token `api` guard seam
(`splicewire.account.api.enabled`, default-off, Sanctum) is prepared for a satellite that
exposes its own proprietary API — never Passport.

## Conventions

Matches the Laravel/Spatie house style — no `declare(strict_types)`, no `final`, no
`readonly`. Pint config in `pint.json`.
