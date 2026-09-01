<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresFrameResources
{
    /**
     * The account + team-admin FRAME RESOURCES (Frame OS ticket 20) — the OOTB list/detail surfaces a
     * host gets by installing beam-accounts: Tokens (list + revoke), Invitations (list + create + revoke),
     * Members (list-only). Two are attribute-declared `#[ParticleResource]` DTOs; Members is SOURCE-backed
     * (a pivot-backed list), registered imperatively as a ParticleResource (the attribute
     * can't express it). One `register()`/`registerDefinition()` call per resource is enough for BOTH the
     * REST transport and Frame's manifest — beam's merged {@see ParticleResourceRegistry} serves both off
     * the one stored declaration (the retired `AdminResourceRegistry` used to need each resource registered
     * TWICE, once per registry; that split is gone).
     *
     * **Always registers — there is no host off-switch, deliberately.**
     * {@see ParticleResourceRegistry} keys by resource key and the LAST registration wins, so a host
     * that genuinely needs a different DECLARATION overrides by registering after this package. App
     * providers boot after auto-discovered package providers, so that is the default outcome, not a race.
     *
     * ⚠️ CORRECTED 2026-09-01 (particle-manifest-repatriation 06). This paragraph used to cite
     * `~/Herd/splicewire-app` as the host "which registers tenant-scoped variants of
     * tokens/invitations/members". It no longer does, and citing it as the healthy case was the
     * mistake: all three keys carried a `superseded()` entry there, so which manifest a request saw
     * was a fact about provider order. Every host fact those restatements carried had a seam —
     * `beam.accounts.tokens.{model,scope}`, `beam.accounts.teams.resolver`, and the declaration's own
     * `showable` — and re-registering is now the LAST resort here, not the documented route.
     *
     * A host that wants certainty lists the providers explicitly in `config/app.php` (preferred), or
     * defers its own registration to an `$app->booted()` callback — the latter only works while
     * exactly one party defers.
     *
     * Still inert unless beam's particle registry is present — that guard is structural, not policy.
     *
     * **Registers DIRECTLY, not through `afterResolving` (particle-contribution-seam ticket 07).** This
     * method used to wrap the whole body in `$app->afterResolving(ParticleResourceRegistry::class, …)` on
     * the reasoning that it made the beam↔beam-accounts boot order irrelevant. It did the opposite: beam
     * resolves that singleton in its OWN `packageBooted()`, and Laravel returns a cached singleton without
     * firing resolving callbacks, so the hook never ran and all five declarations below were silently
     * absent in every host measured. The direct call needs no hook to be order-safe — beam BINDS the
     * registry in the register phase, and Laravel runs `register()` on every provider before `boot()` on
     * any, so `bound()` is already true here whatever the provider order.
     * {@see \Splicewire\Beam\Particle\DeadResolvingHookGuard}
     * now throws if anyone re-introduces the hook.
     */
    #[Chained('boot', order: 120)]
    protected function bootFrameResources(): void
    {
        // Inert unless beam's particle registry is present (a beam-less host gets nothing).
        if (
            ! class_exists(ParticleResourceRegistry::class)
            || ! class_exists(AttributedParticleDiscovery::class)
            || ! $this->app->bound(ParticleResourceRegistry::class)
        ) {
            return;
        }

        $registry = $this->app->make(ParticleResourceRegistry::class);

        // Every `#[ParticleResource]` / `#[ParticleOp]` / `#[ParticleRelative]` under this package's own
        // `src/Data`, discovered rather than hand-listed.
        //
        // ⚠️ This was `foreach ([TokenData, InvitationData, TeamData, UserData] …)`, and the list was
        // already two short: `AccessGrantData` and `ViewRequestData` are declared in the same directory
        // and reached the registry only from `Sharing::ledgerResources()`, which a host has to call.
        // A hand-list cannot report what its author forgot; a directory can only report what is there.
        //
        // Registration is idempotent by key (last wins), so `Sharing`'s own explicit `discover([...])`
        // stays valid and a host that lists these classes during a migration window gets the same result.
        $this->app->make(AttributedParticleDiscovery::class)
            ->discover(paths: [__DIR__.'/../Data']);

        // Members — backed by the team pivot rather than a plain model, so it is declared
        // imperatively (the attribute has nowhere to put a backing class). Mirrors tower's
        // TowerFrameResourceProvider.
        //
        // No `BacksModel` on the backing, deliberately: a seat is a pivot row and no single model
        // identifies it. That used to be spelled `model: null`, which only worked because frame's
        // declaration type allowed a null there and beam's did not — the merge blocker ticket 11 §A10
        // named. With the model field gone there is nothing to null out.
        //
        // ✅ `members` used to be registered by TWO packages, this one and tower, with the registry
        // last-wins by key — so whichever provider booted later won, at both `~/Herd/splicewire-app`
        // and `~/Herd/tower`. It was the estate's only genuine package-vs-package resource contest.
        // Resolved by particle-manifest-repatriation 06: tower's `MembershipResourceData` and
        // `Frame\Sources\MembershipSource` are deleted and this is the sole `members` declaration.
        //
        // ⚠️ Which made `beam.accounts.teams.resolver` load-bearing at every tower host, because
        // {@see MembershipSource} asks {@see \Splicewire\Beam\Accounts\Facades\BeamAccounts::currentTeam()}
        // where tower's source called `tenant()` directly. Tower's own `TowerFrameResourceProvider`
        // now defaults that key; without it a tower host's members list silently empties.
        $registry->register(new ParticleResource(
            key: 'members',
            backing: MembershipSource::class,
            data: MembershipData::class,
            filterable: false,
            form: 'bare',
            label: 'Members',
            group: 'Settings',
            icon: 'users',
            readOnly: true,
            deletable: false,
            editable: false,
        ));
    }
}
