> You are in **splicewire/laravel-beam-accounts** — the account engine of the schemastud stack.

Fortify/session as the default auth substrate, the self-service account runtime (profile/security
surface + Fortify registration/reset actions), and the generic team/membership/invitation
primitives on the permission-cascade base leaf. A capability peer of the beam family; concrete
tenant-provisioning and demo logic stay in the consuming satellite. Not Passport.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
