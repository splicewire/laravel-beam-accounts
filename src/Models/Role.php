<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;

/**
 * `spatie/laravel-permission`'s Role, keyed by uuid — the six-line class that reconciles a constraint
 * **this package itself imposes** (beam-facade tickets 79, 98).
 *
 * ## The constraint, and why the package that creates it owns the fix
 * beam-accounts' `create_permission_tables` stub declares `roles.id` and `permissions.id` as
 * `uuid(...)->primary()` — a cross-host morph-key convention the stub's own docblock argues at length,
 * because `model_has_roles.model_id` is JOINed against the holder's uuid key and Postgres has no
 * implicit `uuid ↔ varchar` cast. Spatie's stock model assumes an auto-increment integer PK and so
 * generates no key on create. {@see TeamProvisioner::syncSpatieRole()} then calls
 * `app(config('permission.models.role'))::findOrCreate(...)`, which inserts a row with no `id` and
 * dies on a NOT NULL constraint.
 *
 * `HasUuids` is the whole repair: it is what actually generates a key on create. The package that
 * ships both the migration and the call site owns the class that makes them agree — before ticket 98
 * this class existed only as three byte-identical `App\Models\Role` copies across the starters, each
 * re-deriving the reason in its own docblock.
 *
 * ## Nothing binds this
 * **Deliberately.** This package does NOT default `config('permission.models.role')` to it, and a
 * test asserts that it never starts. Host census (2026-08-26, re-measured for ticket 98): of the
 * hosts that install beam-accounts, `~/Herd/audiostud`, `~/Herd/numero` and `~/Herd/fable` run
 * **stock integer-keyed Spatie** over an older `bigIncrements('id')` publish of the permission
 * tables — self-consistent today, and `fable` carries no `config/permission.php` at all, so it is
 * precisely the host a package-level default WOULD reach. Defaulting would push uuid strings at a
 * bigint primary key at three live hosts.
 *
 * That is the `permission-cascade.visibility_model` lesson in `BeamAccountsServiceProvider`'s own
 * docblock, met again: a default that is pure-additive when unconfigured is safe to set, and a
 * default that switches an already-populated storage shape is not.
 *
 * ## How a host reaches it
 * By extending, keeping app-namespace identity — `class Role extends Splicewire\Beam\Accounts\Models\Role`
 * in `App\Models`, which is what the starters ship and what the five `App\Models\Role` hosts already
 * bind. Ticket 59 is refined by this class, not overturned: an app-namespace Role is still correct for
 * a host that wants its own identity or behaviour; it just stops re-deriving the reason.
 *
 * `splicewire/tower`'s pair is deliberately NOT folded onto this one. It additionally pins
 * `protected $connection = 'central'`, which is the Role-is-per-tenant-furniture contradiction
 * `CentralPinJustificationAudit` names as ADR-sized and deferred — absorbing it here would silently
 * resolve that ADR.
 */
class Role extends SpatieRole
{
    use HasUuids;
}
