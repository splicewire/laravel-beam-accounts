<?php

use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Accounts\Data\AccessGrantData;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Data\ShareLinkData;
use Splicewire\Beam\Accounts\Data\TeamData;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Accounts\Data\ViewRequestData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The particle doctrine's "list facets are declared on the Data class" rule, guarded.
 *
 * The doctrine is explicit that the default order is single-sourced either way — "a
 * `filterable: false` resource still reads the SAME `#[Sortable(default: true)]`" — so a resource
 * that declares none is relying on whatever order the database happens to return. Every one of
 * these seven was in that state until this guard went in.
 */
$resources = [
    AccessGrantData::class,
    InvitationData::class,
    ShareLinkData::class,
    TeamData::class,
    TokenData::class,
    UserData::class,
    ViewRequestData::class,
];

it('declares exactly one default sort per particle resource', function (string $dataClass) {
    $defaults = array_filter(
        (new ReflectionClass($dataClass))->getProperties(),
        fn (ReflectionProperty $p) => array_filter(
            array_map(fn ($a) => $a->newInstance(), $p->getAttributes(Sortable::class)),
            fn (Sortable $s) => $s->default,
        ) !== [],
    );

    // "At most one property should carry `default: true`; the reflector returns the first it
    // finds" — so two would resolve by declaration order, silently.
    expect($defaults)->toHaveCount(1);
})->with($resources);

it('describes every property of a particle resource', function (string $dataClass) {
    $undescribed = array_values(array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        array_filter(
            (new ReflectionClass($dataClass))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $p) => $p->getAttributes(Description::class) === [],
        ),
    ));

    expect($undescribed)->toBe([]);
})->with($resources);

it('carries the ParticleResource attribute it is being held to', function (string $dataClass) {
    expect((new ReflectionClass($dataClass))->getAttributes(ParticleResource::class))->not->toBeEmpty();
})->with($resources);

it('never pairs a declared scope() with a filterable resource', function (string $dataClass) {
    $attribute = (new ReflectionClass($dataClass))
        ->getAttributes(ParticleResource::class)[0]->newInstance();

    $declaresScope = method_exists($dataClass, 'scope');

    // Both index paths — ParticleController::index() and
    // ParticleFrameResourceHandler::indexQuery() — send a `filterable` resource through the
    // data-filters builder and SKIP the scope closure. data-filters' ResourceQuery::baseQuery()
    // is an unscoped Model::query() unless a host binds a `query:` class. So a resource that
    // declares a scope and is filterable, with no query class, publishes its whole table.
    //
    // TokenData is why this guard exists: it defaulted to filterable while declaring the
    // SECURITY-CRITICAL PAT isolation scope. See particle-doctrine-followups 15.
    if ($declaresScope && $attribute->filterable) {
        expect($attribute->query)->not->toBeNull(
            "{$dataClass} declares scope() and is filterable, but binds no query: class — its scope is silently inert.",
        );
    }

    expect(true)->toBeTrue();
})->with($resources);
