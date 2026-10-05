<?php

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;

/*
 * app-walkthrough APP-09 (APP-21): an enum that already has a human label() declares it to the schema generator
 * (ProvidesEnumLabel), so a rendered option reads "Owner", never its backing value.
 */
class EnumLabelsRoleHolder extends Data
{
    public function __construct(public ?Role $value = null) {}
}

class EnumLabelsProvenanceHolder extends Data
{
    public function __construct(public ?TokenProvenance $value = null) {}
}

it('emits the declared labels as enumNames', function (string $holder, string $enum) {
    $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass($holder));
    $short = (new ReflectionClass($enum))->getShortName();

    expect(is_subclass_of($enum, ProvidesEnumLabel::class))->toBeTrue()
        ->and($schema['$defs'][$short]['enumNames'] ?? null)->toBe(array_map(fn ($case) => $case->label(), $enum::cases()));
})->with([
    'Role' => [EnumLabelsRoleHolder::class, Role::class],
    'TokenProvenance' => [EnumLabelsProvenanceHolder::class, TokenProvenance::class],
]);
