<?php

use Splicewire\Beam\Accounts\Sharing\Sharing;
use Splicewire\Beam\Accounts\Tests\Fixtures\Shareable;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * particle-operation-surface 19, RULING 1 — `Sharing::attachTo()` declares its `$resourceKey` as a
 * real `ParticleResource` from the same call that registers its five ops, so an op's `{id}` resolves
 * THROUGH the registry rather than through `RecordSubject`'s bare
 * `$operation->model::query()->findOrFail($id)` fallback (which applies none of a resource's `scope`,
 * `routeKey` or `includes`).
 *
 * ⚠️ **This is not a fix for a live defect, and the record should say so.** Beam's
 * `RecordSubject.php:26-30` enumerates these ops as a live anchor population of six. Measured
 * 2026-08-31 by a booted registry probe at every `~/Herd/*` root: the factory is FIVE ops, it has
 * exactly ONE call site in the estate (`~/Herd/audiostud`, key `'songs'`), and audiostud declares
 * `songs` as a real resource — so not one of them has ever been an anchor at any host. What follows
 * pins the behaviour for the host that attaches to a key it has NOT declared, and pins the guard
 * that keeps today's hosts unaffected.
 */
it('declares the resource key it attaches ops to, when nothing else has', function () {
    $resources = app(ParticleResourceRegistry::class);

    expect($resources->has('shareables'))->toBeFalse();

    Sharing::attachTo('shareables', Shareable::class);

    $resource = $resources->get('shareables');

    expect($resource)->toBeInstanceOf(ParticleResource::class)
        ->and($resource->modelClass())->toBe(Shareable::class);
});

it('opens no affordance on a host-supplied model whose capability it cannot know', function () {
    Sharing::attachTo('shareables-affordances', Shareable::class);

    $resource = app(ParticleResourceRegistry::class)->get('shareables-affordances');

    // `BackingResolver::assertAffordancesWithinCapability()` THROWS at registration for an affordance
    // opened past a backing's capability. `$model` arrives from the host, so a closed declaration is
    // the only one this package can make honestly — and it is also the true one: an anchor resolves
    // a subject, it is never written through. The sharing verbs write via their own handlers.
    expect($resource->readOnly)->toBeTrue()
        ->and($resource->editable)->toBeFalse()
        ->and($resource->deletable)->toBeFalse()
        ->and($resource->showable)->toBeFalse()
        ->and($resource->isFramed())->toBeFalse();
});

/**
 * The guard, and the reason it is not optional. Registering at an already-taken key does NOT throw —
 * it REPLACES, silently. Without `has()` this factory would be free to overwrite a host's own
 * declaration, `scope` gate and all, purely on provider boot order. This is the case that is live at
 * audiostud today, where `songs` is already declared.
 */
it('yields to a declaration the host already made, rather than replacing it', function () {
    $resources = app(ParticleResourceRegistry::class);

    $resources->register(new ParticleResource(
        key: 'shareables-host-owned',
        backing: Shareable::class,
        label: 'Host Shareables',
        scope: fn ($query) => $query->where('visibility', 'private'),
    ));

    Sharing::attachTo('shareables-host-owned', Shareable::class);

    $resource = $resources->get('shareables-host-owned');

    // The host's declaration survives intact — label, framing and, critically, its row-level gate.
    expect($resource->label)->toBe('Host Shareables')
        ->and($resource->isFramed())->toBeTrue()
        ->and($resource->scope)->not->toBeNull()
        ->and($resource->readOnly)->toBeFalse();
});
