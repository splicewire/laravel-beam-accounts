<?php

use Orchestra\Testbench\TestCase;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Accounts\Data\LoginInputData;
use Splicewire\Beam\Accounts\Data\LoginResponseData;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\LoginController;
use Splicewire\Beam\Data\ResponseBody;

class LoginDeclarationTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('data-schemas.base_uri', 'https://connector.test/schemas');
    }
}

pest()->extend(LoginDeclarationTestCase::class);

it('declares login credentials and the existing success and unauthorized envelopes', function () {
    $method = new ReflectionMethod(LoginController::class, 'login');
    expect($method->getAttributes(RequestFromData::class)[0]->newInstance()->dataClass)->toBe(LoginInputData::class);
    $responses = array_map(fn ($attribute) => $attribute->newInstance(), $method->getAttributes(ResponseFromData::class));
    expect(array_column($responses, 'status'))->toBe([200, 401])
        ->and(array_unique(array_column($responses, 'dataClass')))->toBe([LoginResponseData::class]);
    foreach ([true, false] as $success) {
        $message = $success ? null : 'Unauthorized.';
        $runtime = new ResponseBody(success: $success, message: $message);
        expect((new LoginResponseData(null, $success, $message))->toArray())->toEqual($runtime->toResponseArray());
    }
    $schema = app(Generator::class)->forResponse()->generate(new ReflectionClass(LoginResponseData::class));
    expect(array_keys($schema['properties']))->toEqualCanonicalizing(['data', 'success', 'message', 'limit', 'offset', 'total']);
    $request = app(Generator::class)->forRequest()->generate(new ReflectionClass(LoginInputData::class));
    expect($request['required'])->toContain('email', 'password')->not->toContain('remember');
    Illuminate\Support\Facades\Auth::shouldReceive('attempt')->once()->with(['email' => 'user@test.com', 'password' => 'wrong'])->andReturn(false);
    $response = (new LoginController)->login(new LoginInputData('user@test.com', 'wrong'), new Illuminate\Http\Request);
    expect($response->statusCode)->toBe(401)->and($response->toResponseArray())->toEqual((new LoginResponseData(null, false, 'Unauthorized.'))->toArray());
});
