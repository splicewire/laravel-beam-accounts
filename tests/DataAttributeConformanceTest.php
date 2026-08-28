<?php

use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Accounts\Data\AccessGrantData;
use Splicewire\Beam\Accounts\Data\InvitationData;
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
 * these six was in that state until this guard went in.
 */
$resources = [
    AccessGrantData::class,
    InvitationData::class,
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
