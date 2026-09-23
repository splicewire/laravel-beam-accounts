<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Splicewire\Beam\Accounts\Data\AccessGrantData;
use Splicewire\Beam\Accounts\Data\ViewRequestData;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Accounts\Sharing\AccessGrants;
use Splicewire\Beam\Accounts\Sharing\ViewRequests;
use Splicewire\Beam\Accounts\Tests\Fixtures\Shareable;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

class SharingFrameResourceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class, FrameServiceProvider::class,
            DataFiltersServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', ['web', 'auth']);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(DB::connection()->getPdo(), DB::connection('central')->getPdo());

        // Use the actual discovered declarations through Frame's existing producer slot.
        // Presentation eligibility remains unchanged until the separate registration cutover.
        foreach (array_keys(self::ledgers()) as $key) {
            $this->assertFalse(app(ParticleResourceRegistryAdapter::class)->has($key));
            $resource = app(ParticleResourceRegistry::class)->get($key);
            app(CompositeResourceRegistry::class)->register($resource->toResourceDefinition());
            $this->assertTrue(app(ResourceRegistry::class)->has($key));
        }
    }

    public static function ledgers(): array
    {
        return [
            'access-grants' => ['access-grants', AccessGrant::class, AccessGrantData::class, 'grantee'],
            'view-requests' => ['view-requests', ViewRequest::class, ViewRequestData::class, 'requester'],
        ];
    }

    private function user(string $name): User
    {
        return User::create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'secret']);
    }

    private function row(string $model, string $owner, User $actor, array $attributes = []): AccessGrant|ViewRequest
    {
        $target = $owner === 'grantee' ? 'grantable' : 'requestable';
        $defaults = $owner === 'grantee' ? ['ability' => 'view', 'effect' => 'allow'] : ['status' => 'pending'];

        return $model::create(array_merge($defaults, [
            $owner.'_type' => $actor->getMorphClass(), $owner.'_id' => (string) $actor->getKey(),
            $target.'_type' => 'shareable', $target.'_id' => 'target-1',
        ], $attributes));
    }

    private function snapshot(string $model): array
    {
        return $model::query()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    }

    #[DataProvider('ledgers')]
    public function test_the_discovered_ledger_has_only_list_lifecycle(string $key, string $model, string $data, string $owner): void
    {
        $definition = app(ResourceRegistry::class)->get($key);
        $this->assertFalse($definition->creatable);
        $this->assertFalse($definition->editable);
        $this->assertFalse($definition->deletable);
        $this->assertFalse($definition->showable);
    }

    #[DataProvider('ledgers')]
    public function test_a_missing_actor_cannot_match_a_fabricated_owner(string $key, string $model, string $data, string $owner): void
    {
        $actor = $this->user('Ada');
        $this->row($model, $owner, $actor);
        $this->row($model, $owner, $actor, [$owner.'_type' => 'user', $owner.'_id' => '']);
        $this->row($model, $owner, $actor, [$owner.'_id' => '']);

        Auth::forgetGuards();
        $this->assertNull(Auth::user());
        $this->assertSame([], $data::scope($model::query())->pluck('id')->all());

    }

    #[DataProvider('ledgers')]
    public function test_a_null_identifier_cannot_match_an_empty_owner_but_zero_is_valid(string $key, string $model, string $data, string $owner): void
    {
        $actor = new User;
        $this->row($model, $owner, $actor);
        $this->actingAs($actor);
        $this->assertSame([], $data::scope($model::query())->pluck('id')->all());

        $zero = (new User)->forceFill(['id' => 0]);
        $row = $this->row($model, $owner, $zero);
        $this->actingAs($zero);
        $this->assertSame([$row->getKey()], $data::scope($model::query())->pluck('id')->all());
    }

    #[DataProvider('ledgers')]
    public function test_own_and_foreign_details_are_unsupported_without_disabling_lists(string $key, string $model, string $data, string $owner): void
    {
        $ada = $this->user('Ada');
        $own = $this->row($model, $owner, $ada);
        $foreign = $this->row($model, $owner, $this->user('Bo'));
        $this->actingAs($ada)->getJson('/frame/resources/'.$key)->assertOk()->assertJsonCount(1, 'data');
        foreach ([$own, $foreign] as $row) {
            $this->getJson('/frame/resources/'.$key.'/records/'.$row->getKey())->assertStatus(405);
        }
    }

    #[DataProvider('ledgers')]
    public function test_lists_follow_the_live_actor_and_both_morph_coordinates(string $key, string $model, string $data, string $owner): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $first = $this->row($model, $owner, $ada);
        $second = $this->row($model, $owner, $bo);
        $this->row($model, $owner, $ada, [$owner.'_type' => 'role']);
        foreach ([[$ada, $first], [$bo, $second]] as [$actor, $row]) {
            $response = $this->actingAs($actor)->getJson('/frame/resources/'.$key)->assertOk();
            $this->assertSame([$row->getKey()], array_column($response->json('data'), 'id'));
            $this->assertSame($data::project($row)->toArray(), $response->json('data.0'));
        }
    }

    #[DataProvider('ledgers')]
    public function test_pagination_and_summary_keep_the_same_owned_population(string $key, string $model, string $data, string $owner): void
    {
        $ada = $this->user('Ada');
        $own = [];
        for ($day = 1; $day <= 3; $day++) {
            $own[] = $this->row($model, $owner, $ada, ['created_at' => '2026-09-0'.$day.' 12:00:00']);
        }
        $this->actingAs($ada)->getJson('/frame/resources/'.$key.'/summary')->assertOk()->assertJsonPath('figures.0.value', 3);
        $this->row($model, $owner, $this->user('Bo'), ['created_at' => '2026-09-04 12:00:00']);
        $this->getJson('/frame/resources/'.$key.'/summary')->assertOk()->assertJsonPath('figures.0.value', 3);

        foreach ([1 => [$own[2]->getKey(), $own[1]->getKey()], 2 => [$own[0]->getKey()]] as $page => $ids) {
            $response = $this->getJson('/frame/resources/'.$key.'?per_page=2&page='.$page)->assertOk()
                ->assertJsonPath('page', $page)->assertJsonPath('total', 3)->assertJsonPath('perPage', 2);
            $this->assertSame($ids, array_column($response->json('data'), 'id'));
        }
    }

    #[DataProvider('ledgers')]
    public function test_query_controls_and_metadata_offer_no_foreign_owner_selector(string $key, string $model, string $data, string $owner): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $own = $this->row($model, $owner, $ada);
        $this->row($model, $owner, $bo);
        $url = '/frame/resources/'.$key;
        $this->actingAs($ada);
        $this->getJson($url.'?'.http_build_query(['filter' => [$owner.'_id' => $bo->getKey()]]))->assertStatus(400);
        $response = $this->getJson($url)->assertOk();
        $this->assertSame([$own->getKey()], array_column($response->json('data'), 'id'));
        $properties = $this->getJson($url.'/filters/schema')->assertOk()->json('data.properties');
        $this->assertArrayNotHasKey($owner.'Id', $properties);
        $this->assertSame('created_at', $properties['id']['x-sort']['name']);
        foreach ($properties as $property) {
            $this->assertArrayNotHasKey('x-filter', $property);
        }
        $this->getJson($url.'?filterVariant=users')->assertNotFound();
        $this->getJson($url.'/filters/options/users')->assertNotFound();
    }

    #[DataProvider('ledgers')]
    public function test_generic_writes_cannot_mutate_either_actors_ledger(string $key, string $model, string $data, string $owner): void
    {
        $ada = $this->user('Ada');
        $own = $this->row($model, $owner, $ada);
        $foreign = $this->row($model, $owner, $this->user('Bo'));
        $before = $this->snapshot($model);
        $url = '/frame/resources/'.$key;
        $this->actingAs($ada)->postJson($url, ['ability' => 'manage', 'status' => 'approved'])->assertForbidden();
        foreach ([$own, $foreign] as $row) {
            $this->putJson($url.'/records/'.$row->getKey(), ['ability' => 'manage', 'status' => 'approved'])->assertForbidden();
            $this->deleteJson($url.'/records/'.$row->getKey())->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot($model));
    }

    #[DataProvider('ledgers')]
    public function test_guests_are_refused_by_real_authentication(string $key, string $model, string $data, string $owner): void
    {
        $row = $this->row($model, $owner, $this->user('Ada'));
        $before = $this->snapshot($model);
        $url = '/frame/resources/'.$key;
        foreach ([$url, $url.'/summary', $url.'/filters/schema', $url.'/records/'.$row->getKey()] as $read) {
            $this->getJson($read)->assertUnauthorized();
        }
        $this->postJson($url, [])->assertUnauthorized();
        $this->putJson($url.'/records/'.$row->getKey(), [])->assertUnauthorized();
        $this->deleteJson($url.'/records/'.$row->getKey())->assertUnauthorized();
        $this->assertSame($before, $this->snapshot($model));
    }

    public function test_domain_actions_keep_their_idempotency_effects_and_decision_projection(): void
    {
        $owner = $this->user('Owner');
        $recipient = $this->user('Recipient');
        $target = Shareable::create(['user_id' => $owner->getKey(), 'visibility' => 'private']);
        $otherTarget = Shareable::create(['user_id' => $owner->getKey(), 'visibility' => 'private']);
        $grants = app(AccessGrants::class);
        $requests = app(ViewRequests::class);

        $view = $grants->share($target, $recipient);
        $this->assertSame($view->getKey(), $grants->share($target, $recipient)->getKey());
        $manage = $grants->share($target, $recipient, 'manage');
        $deny = $grants->deny($target, $recipient);
        $this->actingAs($recipient);
        $rows = $this->getJson('/frame/resources/access-grants')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$view->getKey(), $manage->getKey(), $deny->getKey()], array_column($rows, 'id'));
        $this->assertEqualsCanonicalizing(['allow', 'allow', 'deny'], array_column($rows, 'effect'));
        $this->assertSame(2, $grants->revoke($target, $recipient, 'view'));
        $this->getJson('/frame/resources/access-grants')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ability', 'manage');

        $request = $requests->request($target, $recipient);
        $this->assertSame($request->getKey(), $requests->request($target, $recipient)->getKey());
        $this->getJson('/frame/resources/view-requests')->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.decidedAt', null);
        $requests->approve($request);
        $requests->approve($request);
        $this->assertSame(1, AccessGrant::query()->where('ability', 'view')->where('effect', 'allow')->count());
        $declined = $requests->request($otherTarget, $recipient);
        $requests->decline($declined);
        $this->assertSame(2, AccessGrant::count());
        $rows = $this->getJson('/frame/resources/view-requests')->assertOk()->json('data');
        $indexed = array_column($rows, null, 'id');
        $this->assertSame('approved', $indexed[$request->getKey()]['status']);
        $this->assertSame($request->fresh()->decided_at->toIso8601String(), $indexed[$request->getKey()]['decidedAt']);
        $this->assertSame('declined', $indexed[$declined->getKey()]['status']);
        $this->assertSame($declined->fresh()->decided_at->toIso8601String(), $indexed[$declined->getKey()]['decidedAt']);

        // Being the target owner does not turn these into an incoming management ledger.
        $this->actingAs($owner)->getJson('/frame/resources/access-grants')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/frame/resources/view-requests')->assertOk()->assertJsonCount(0, 'data');
    }
}
