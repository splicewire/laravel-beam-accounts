<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * ux-walkthrough UX-11 (IA-5): `GET /logout` renders a packaged confirm page that POSTs; it never signs anyone out on a
 * GET (a link, a prefetch or an image tag would). A guest is sent to '/'. A host that declares its own GET /logout
 * (the flagship sends it to its SPA's confirm) wins.
 */

it('asks a signed-in user to confirm, with a CSRF form that POSTs logout, and signs no one out', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);

    $page = $this->actingAs($user)->get('/logout')->assertOk()->getContent();

    expect($page)->toContain('method="POST"')
        ->and($page)->toContain('action="'.route('logout').'"')
        ->and($page)->toContain('name="_token"');
    $this->assertAuthenticatedAs($user);
});

it('sends a guest home', function () {
    $this->get('/logout')->assertRedirect('/');
});

it('is named logout.confirm', function () {
    expect(Route::has('logout.confirm'))->toBeTrue();
});
