<?php

namespace Splicewire\Beam\Accounts\Tests;

use Schemastud\DataSchemas\Contracts\ProvidesJsonSchema;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Data\BeamData;

/**
 * The reparent did the thing it was for.
 *
 * Ten particle-declared DTOs in this package extended Spatie's `Data` directly, so they answered
 * nothing when asked for their JSON Schema — the `::jsonSchema()` sugar arrives through
 * {@see \Schemastud\DataSchemas\StudData}, which {@see BeamData} has extended since beam commit
 * `66e2dff`. Swapping the parent is the whole change; this test is the witness that it is a
 * BEHAVIOURAL one rather than a compile-time one.
 *
 * It asserts through the host's CONFIGURED generator on purpose — a document whose `$schema` matches
 * `config('data-schemas.schema_version')` proves the class resolved the container binding rather
 * than a bare `new JsonSchemaGenerator`, which is the exact failure mode `DerivesJsonSchema` exists
 * to close (~26 config-blind construction sites across the estate).
 *
 * `UserData` stands for the other nine: they were reparented by one identical `use`-line swap, so a
 * per-class copy of this test would measure the same line ten times.
 */
class ParticleDtoJsonSchemaTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // A real host auto-discovers this; testbench does not. Beam requires the package, so this
        // adds no dependency — it only makes the already-present binding reachable here.
        return array_merge([LaravelDataSchemasServiceProvider::class], parent::getPackageProviders($app));
    }

    public function test_a_particle_declared_dto_reaches_beams_data_base(): void
    {
        $this->assertInstanceOf(
            ProvidesJsonSchema::class,
            $this->getMockBuilder(UserData::class)->disableOriginalConstructor()->getMock(),
        );

        $this->assertTrue(is_subclass_of(UserData::class, BeamData::class));
    }

    public function test_a_particle_declared_dto_answers_json_schema_with_the_hosts_dialect(): void
    {
        $schema = UserData::jsonSchema();

        $this->assertIsArray($schema);
        $this->assertSame(config('data-schemas.schema_version'), $schema['$schema'] ?? null);
        $this->assertSame('object', $schema['type'] ?? null);
    }
}
