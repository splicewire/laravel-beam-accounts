<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Accounts\Data\DemoUserLinkData;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * S1 (owner-review-20261026 ticket 01, owner-review-20261102 research 22): the local-only "log in as ANY user"
 * picker. It is the widest demo door the package has — a guest types a name and becomes that user — so it sits
 * behind a THIRD gate, `beam.accounts.demo.user_picker`, which holds only when:
 *
 *   1. the demo affordances are on ({@see BeamDemo::enabled()}),
 *   2. the host publishes login links ({@see BeamDemo::publishesLoginLinks()}), AND
 *   3. the app environment is literally `local`, AND the picker key itself is a strict true.
 *
 * Each case below boots the application in one gate posture, because the search route is MOUNTED at boot only
 * when the gate holds: a closed gate leaves no route to find, not merely a refusing one.
 */
class DemoUserPickerTest extends TestCase
{
    /** Every flag on, in `local`: the only posture that offers the picker. */
    protected function allOpenLocal(Application $app): void
    {
        $app['env'] = 'local';
        $app['config']->set('beam.accounts.demo.enabled', null);
        $app['config']->set('beam.accounts.demo.login_links', true);
        $app['config']->set('beam.accounts.demo.user_picker', true);
    }

    protected function pickerKeyOff(Application $app): void
    {
        $this->allOpenLocal($app);
        $app['config']->set('beam.accounts.demo.user_picker', false);
    }

    protected function pickerKeyJunk(Application $app): void
    {
        $this->allOpenLocal($app);
        $app['config']->set('beam.accounts.demo.user_picker', 'ture');
    }

    protected function loginLinksOff(Application $app): void
    {
        $this->allOpenLocal($app);
        $app['config']->set('beam.accounts.demo.login_links', false);
    }

    protected function demoOff(Application $app): void
    {
        $this->allOpenLocal($app);
        $app['config']->set('beam.accounts.demo.enabled', false);
    }

    /** A shared dev/preview box: every flag on, but not `local`. */
    protected function stagingAllFlagsOn(Application $app): void
    {
        $this->allOpenLocal($app);
        $app['env'] = 'staging';
    }

    /** Production with every flag forced on — including `enabled: true`, which outranks the production default. */
    protected function productionAllFlagsOn(Application $app): void
    {
        $this->allOpenLocal($app);
        $app['env'] = 'production';
        $app['config']->set('beam.accounts.demo.enabled', true);
    }

    private function seedUsers(): array
    {
        return [
            'ada' => User::create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => 'secret-pass']),
            'grace' => User::create(['name' => 'Grace Hopper', 'email' => 'grace@navy.example', 'password' => 'secret-pass']),
            'alan' => User::create(['name' => 'Alan Turing', 'email' => 'alan@example.test', 'password' => 'secret-pass']),
        ];
    }

    private function assertNoPicker(): void
    {
        $this->seedUsers();

        $this->assertFalse(BeamDemo::offersUserPicker());
        $this->assertFalse(Route::has('users.demo-search'), 'the search route is mounted with a gate closed');
        $this->assertSame([], app(DemoLoginLinks::class)->users('a'));
        $this->assertNull(app(DemoLoginLinks::class)->userSearchUrl());
        $this->get('/users/demo-search?q=ada')->assertNotFound();
    }

    public function test_the_shipped_default_is_off(): void
    {
        $shipped = require dirname(__DIR__).'/config/beam/accounts.php';

        $this->assertArrayHasKey('user_picker', $shipped['demo']);
        $this->assertFalse($shipped['demo']['user_picker']);
        $this->assertFalse(BeamDemo::offersUserPicker());
        $this->assertFalse(Route::has('users.demo-search'));
    }

    #[DefineEnvironment('allOpenLocal')]
    public function test_local_with_every_gate_open_searches_any_user_and_mints_signed_links(): void
    {
        $users = $this->seedUsers();

        $this->assertTrue(BeamDemo::offersUserPicker());
        $this->assertTrue(Route::has('users.demo-search'));
        $this->assertSame('/users/demo-search', app(DemoLoginLinks::class)->userSearchUrl());

        // By name, by email, case-insensitive — and NOT limited to demo subjects: these are ordinary users.
        $byName = app(DemoLoginLinks::class)->users('GRACE');
        $this->assertCount(1, $byName);
        $this->assertInstanceOf(DemoUserLinkData::class, $byName[0]);
        $this->assertSame((string) $users['grace']->getKey(), $byName[0]->id);
        $this->assertSame('Grace Hopper', $byName[0]->name);
        $this->assertSame('grace@navy.example', $byName[0]->email);

        $byEmail = app(DemoLoginLinks::class)->users('navy.example');
        $this->assertSame(['Grace Hopper'], array_map(fn ($u) => $u->name, $byEmail));

        // An empty query is the roster: everyone, ordered by name.
        $roster = app(DemoLoginLinks::class)->users('');
        $this->assertSame(['Ada Lovelace', 'Alan Turing', 'Grace Hopper'], array_map(fn ($u) => $u->name, $roster));

        // Every link is a signed, EXPIRING login-as link for that user's id.
        foreach ($roster as $link) {
            parse_str((string) parse_url($link->url, PHP_URL_QUERY), $query);
            $this->assertArrayHasKey('expires', $query);
            $this->assertArrayHasKey('signature', $query);
            $this->assertStringContainsString('/users/'.$link->id.'/login-as', $link->url);
        }

        // Over the wire, as a guest: the declared op answers with the same projection.
        $response = $this->getJson('/users/demo-search?q=ali')->assertOk();
        $this->assertSame([], $response->json('data.users'), 'ali matches no user');

        $response = $this->getJson('/users/demo-search?q=al')->assertOk();
        $this->assertSame(['Alan Turing'], array_column($response->json('data.users'), 'name'));
        $this->assertSame(['id', 'name', 'email', 'url'], array_keys($response->json('data.users.0')));

        // Following the link is the whole affordance: the guest is now Alan.
        $this->assertGuest();
        $this->get($response->json('data.users.0.url'))->assertRedirect();
        $this->assertAuthenticatedAs($users['alan']);
    }

    #[DefineEnvironment('allOpenLocal')]
    public function test_the_search_is_bounded_and_treats_the_query_as_text(): void
    {
        foreach (range(1, 30) as $i) {
            User::create(['name' => sprintf('User %02d', $i), 'email' => "user{$i}@example.test", 'password' => 'secret-pass']);
        }

        $this->assertCount(DemoLoginLinks::USER_SEARCH_LIMIT, app(DemoLoginLinks::class)->users(''));
        $this->assertCount(DemoLoginLinks::USER_SEARCH_LIMIT, $this->getJson('/users/demo-search')->assertOk()->json('data.users'));

        // LIKE wildcards in the query are literal, not patterns.
        $this->assertSame([], app(DemoLoginLinks::class)->users('%'));
        $this->assertSame([], app(DemoLoginLinks::class)->users('_'));

        // The op's declared input bounds the query length.
        $this->getJson('/users/demo-search?q='.str_repeat('a', 101))->assertUnprocessable();
    }

    #[DefineEnvironment('allOpenLocal')]
    public function test_a_gate_closed_after_boot_still_refuses_at_the_handler_and_the_mint(): void
    {
        $this->seedUsers();
        $this->assertTrue(Route::has('users.demo-search'));

        config()->set('beam.accounts.demo.user_picker', false);

        $this->assertSame([], app(DemoLoginLinks::class)->users('ada'));
        $this->assertNull(app(DemoLoginLinks::class)->userSearchUrl());
        $this->getJson('/users/demo-search?q=ada')->assertNotFound();
    }

    #[DefineEnvironment('pickerKeyOff')]
    public function test_picker_key_off_offers_no_picker(): void
    {
        $this->assertNoPicker();
    }

    #[DefineEnvironment('pickerKeyJunk')]
    public function test_a_misspelled_picker_key_fails_closed(): void
    {
        $this->assertNoPicker();
    }

    #[DefineEnvironment('loginLinksOff')]
    public function test_login_links_off_offers_no_picker(): void
    {
        $this->assertNoPicker();
    }

    #[DefineEnvironment('demoOff')]
    public function test_demo_off_offers_no_picker(): void
    {
        $this->assertNoPicker();
    }

    #[DefineEnvironment('stagingAllFlagsOn')]
    public function test_a_non_local_environment_offers_no_picker_even_with_every_flag_on(): void
    {
        // The other two demo doors stay open here — only the picker is local-only.
        $this->assertTrue(BeamDemo::publishesLoginLinks());
        $this->assertNoPicker();
    }

    #[DefineEnvironment('productionAllFlagsOn')]
    public function test_production_offers_no_picker_even_with_every_flag_forced_on(): void
    {
        $this->assertNoPicker();
    }
}
