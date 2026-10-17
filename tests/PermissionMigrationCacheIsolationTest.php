<?php

use Illuminate\Support\Facades\Schema;

it('creates the permission schema without resolving a cache store', function () {
    foreach ([
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions',
        'roles',
        'permissions',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    app()->instance('cache', new class
    {
        public function store(mixed $name = null): never
        {
            throw new RuntimeException('permission migration resolved the cache store');
        }
    });

    $migration = require dirname(__DIR__).'/database/migrations/shared/create_permission_tables.php.stub';

    $migration->up();

    expect(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('model_has_permissions'))->toBeTrue()
        ->and(Schema::hasTable('model_has_roles'))->toBeTrue()
        ->and(Schema::hasTable('role_has_permissions'))->toBeTrue();
});
