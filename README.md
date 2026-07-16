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

## What stays in the satellite

`splicewire/laravel-satellite-account` composes this engine and keeps only the pieces the
**seam** legitimately differs on: concrete tenant/demo provisioning — the `Demo` subjects,
the `DemoTeamSeeder`, and the `account:login-as` affordance (command + signed route +
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
