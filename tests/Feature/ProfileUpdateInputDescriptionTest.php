<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Accounts\Data\ProfileUpdateInputData;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\ProfileController;

it('documents preferredLocale in the request schema of PATCH /api/v1/me', function () {
    // Exercise the real API declaration and the request-schema attribute mechanism without adding a Scribe dependency.
    $this->app->register(LaravelDataSchemasServiceProvider::class);
    $directory = sys_get_temp_dir().'/accounts-profile-description-'.bin2hex(random_bytes(8));
    $this->app->instance(SchemaRegistry::class, new FilesystemSchemaRegistry($directory));

    try {
        Route::prefix('api/v1')->name('api.v1.')->group(fn () => Route::splicewireAccountsMeV1());
        $route = Route::getRoutes()->match(Request::create('/api/v1/me', 'PATCH'));
        $controller = ltrim($route->getControllerClass(), '\\');
        expect($controller)->toBe(ProfileController::class)
            ->and($route->getActionMethod())->toBe('update');

        $input = (new ReflectionMethod($controller, 'update'))->getParameters()[0]->getType();
        expect($input)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($input->getName())->toBe(ProfileUpdateInputData::class);

        $schema = app(Generator::class)->forRequest()->generate(new ReflectionClass($input->getName()));
        expect($schema['properties'])->toHaveKey('preferredLocale');
        expect($schema['properties']['preferredLocale']['description'] ?? null)->toBeString()
            ->not->toBe('')->toContain('locale', 'automatic');
    } finally {
        File::deleteDirectory($directory);
    }
});
