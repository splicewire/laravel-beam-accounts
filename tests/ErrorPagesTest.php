<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Http\ErrorPages;

/**
 * {@see ErrorPages} — a browser's 403/404/419/500/503 renders the packaged `error` Inertia page with the
 * original status, while every JSON response and every deliberately-built response is left alone.
 *
 * Driven through the real exception handler, registered the way a host's `bootstrap/app.php` does
 * (`shouldRenderJsonWhen` for `api/*`, then `ErrorPages::register()`), and read as an Inertia visit
 * (`X-Inertia`) so the assertion is on the page object rather than on a Blade root view the testbench
 * app does not have.
 */
beforeEach(function () {
    $exceptions = new Exceptions(app(ExceptionHandler::class));
    $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    ErrorPages::register($exceptions);

    Route::get('boom/policy', fn () => throw new AuthorizationException);
    Route::get('boom/abort', fn () => abort(403, 'You are not a member of this tenant.'));
    Route::get('boom/missing', fn () => throw (new ModelNotFoundException)->setModel('App\\Models\\Secret', [7]));
    Route::get('boom/expired', fn () => throw new TokenMismatchException);
    Route::get('boom/server', fn () => throw new RuntimeException('SQLSTATE secret detail'));
    Route::get('boom/down', fn () => abort(503, 'Back at noon.'));
    Route::get('boom/chosen', fn () => throw new HttpResponseException(response('custom', 403)));
    Route::get('api/boom/policy', fn () => throw new AuthorizationException);

    $this->inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
});

it('renders a policy refusal as the error page, status and message intact', function () {
    $this->get('/boom/policy', $this->inertia)
        ->assertStatus(403)
        ->assertJsonPath('component', 'error')
        ->assertJsonPath('props.status', 403)
        // The harness looks for this exact sentence on the refused page.
        ->assertJsonPath('props.message', 'This action is unauthorized.');
});

it('passes an abort() message through for a 403', function () {
    $this->get('/boom/abort', $this->inertia)
        ->assertStatus(403)
        ->assertJsonPath('props.message', 'You are not a member of this tenant.');
});

it('never shows a 404 or 500 exception message', function () {
    $missing = $this->get('/boom/missing', $this->inertia)->assertStatus(404)->assertJsonPath('component', 'error');
    expect($missing->json('props.message'))->not->toContain('Secret');

    $unrouted = $this->get('/no/such/page', $this->inertia)->assertStatus(404);
    expect($unrouted->json('props.title'))->toBe('Page not found');

    config()->set('app.debug', false);
    $server = $this->get('/boom/server', $this->inertia)->assertStatus(500)->assertJsonPath('component', 'error');
    expect($server->json('props.message'))->not->toContain('SQLSTATE');
});

it('renders 419 and 503 with their own status', function () {
    $this->get('/boom/expired', $this->inertia)->assertStatus(419)->assertJsonPath('props.title', 'This page expired');
    $this->get('/boom/down', $this->inertia)->assertStatus(503)->assertJsonPath('props.message', 'Back at noon.');
});

it('keeps the debug page for a 500 while app.debug is on', function () {
    config()->set('app.debug', true);

    $response = $this->get('/boom/server', $this->inertia)->assertStatus(500);

    expect($response->headers->get('X-Inertia'))->toBeNull();
});

it('leaves JSON error responses exactly as they were', function () {
    $this->getJson('/boom/policy')
        ->assertStatus(403)
        ->assertExactJson(['message' => 'This action is unauthorized.']);

    // `api/*` is JSON by the host's rule even for a request that did not ask for it.
    $this->get('/api/boom/policy')
        ->assertStatus(403)
        ->assertExactJson(['message' => 'This action is unauthorized.']);
});

it('does not replace a response somebody built on purpose', function () {
    $this->get('/boom/chosen', $this->inertia)->assertStatus(403)->assertSee('custom');
});
