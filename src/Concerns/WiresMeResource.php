<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Database\Eloquent\Model;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\MeController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresMeResource
{
    /**
     * Register `me` — the caller's own identity projection, as a particle resource
     * (particle-contribution-seam 16/18).
     *
     * ## Why it is a resource at all
     *
     * So that a package which owns a concern can add its slice of it. `entitlements` (a beam-commerce
     * concept) and `platformEmbedPk` (a beam-embed one) used to reach this projection through a
     * single-slot container binding, which meant they could only meet in a host that saw both packages
     * at once — and so both were hoisted into tower, a package that owns neither. As a resource key,
     * each ships from the package that owns it and neither names the other (ticket 16 §A1).
     *
     * ## Why `me`, and not `users` or `auth-user`
     *
     * Not `users`: a second projection of one model keyed off one existing resource is the per-realm
     * overlay shape ({@see \Splicewire\Beam\Realm\RealmResourceRegistry}), which returns the base
     * unchanged whenever the realm is null — a trap this effort has walked into three times. Not
     * `auth-user`: ticket 16 measured that no such key ever existed; `/me` was a hand-written route
     * closure, which is precisely why nothing about it went through the seam.
     *
     * ## Shape
     *
     * Model-backed (the configured user model), so the contribution fold receives a real `Model` at
     * {@see \Splicewire\Beam\Http\Particle\ParticleController::projectRecord()} like every other
     * resource. `filterable: false` because there is no list — ticket 14 found the default `true` routes
     * an index through the shipped hydrator's throwing `query()`, and a singleton has no index to save.
     * `readOnly` + `frame: false`: writes are the existing `PATCH /me` profile controller's, and there is
     * no admin surface for a resource with one row per caller.
     *
     * Subject resolution is the ONE thing the generic controller cannot do here — a singleton has no
     * `{id}` — and {@see MeController} overrides exactly that, nothing else.
     */
    #[Chained('boot', order: 130)]
    protected function bootMeResource(): void
    {
        // Inert unless beam's particle registry is present (a beam-less host gets nothing).
        if (
            ! class_exists(ParticleResourceRegistry::class)
            || ! $this->app->bound(ParticleResourceRegistry::class)
        ) {
            return;
        }

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: MeController::KEY,
            backing: BeamAccounts::userModel(),
            data: AuthUserData::class,
            // `AuthUserData` is not `AuthUserData::from($user)`: the identity core branches on tenancy
            // (tenant-scoped roles vs. the central tenants list) and mirrors the caller's bearer back as
            // `access_token`. `project:` is legal residue under ticket 12 §A4's rule — it does something
            // `data::from($record)` provably cannot — and the bearer is read off the live request because
            // the closure is handed only the record.
            project: fn (Model $user): AuthUserData => AuthUserData::fromUser($user, request()?->bearerToken()),
            filterable: false,
            readOnly: true,
            frame: false,
        ));
    }
}
