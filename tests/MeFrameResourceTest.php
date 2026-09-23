<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Spatie\LaravelData\Data;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\MeController;
use Splicewire\Beam\Accounts\Models\Permission;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\ReadContext;

class MeFrameResourceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataSchemasServiceProvider::class,
            FrameServiceProvider::class,
            DataFiltersServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', MeStringKeyUser::class);
        $app['config']->set('permission-cascade.user_model', MeStringKeyUser::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('me_users', function (Blueprint $table): void {
            $table->string('user_ref')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Exercise the future typed consumer through Frame's existing producer slot,
        // without changing the Particle declaration's current discovery eligibility.
        $resource = app(ParticleResourceRegistry::class)->get('me');
        app(CompositeResourceRegistry::class)->register($resource->toResourceDefinition());
        Route::middleware(['web', 'auth'])->get('/identity', [MeController::class, 'show']);
    }

    private function user(string $name): MeStringKeyUser
    {
        return MeStringKeyUser::create([
            'user_ref' => 'person-'.$name,
            'name' => $name,
            'email' => strtolower($name).'@example.test',
            'password' => 'secret',
        ]);
    }

    public function test_the_real_list_is_confined_to_the_current_actor_on_each_request(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(DB::connection()->getPdo(), DB::connection('central')->getPdo());
        $this->assertFalse(app(ParticleResourceRegistryAdapter::class)->has('me'));
        $this->assertTrue(app(ResourceRegistry::class)->has('me'));

        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        foreach ([$ada, $bo] as $actor) {
            $response = $this->actingAs($actor)->getJson('/frame/resources/me')->assertOk();
            $this->assertSame([$actor->getKey()], array_column($response->json('data'), 'id'));
            $response->assertJsonPath('total', 1)->assertJsonPath('data.0.email', $actor->email);
        }
    }

    public function test_a_foreign_identity_cannot_be_resolved_by_its_known_key(): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $this->actingAs($ada)->getJson('/frame/resources/me/records/'.$ada->getKey())
            ->assertOk()->assertJsonPath('data.email', $ada->email);
        $this->getJson('/frame/resources/me/records/'.$bo->getKey())->assertNotFound();
    }

    public function test_the_summary_counts_the_actor_scope_instead_of_the_users_table(): void
    {
        $ada = $this->user('Ada');
        $this->user('Bo');
        $this->user('Cy');
        $this->actingAs($ada)->getJson('/frame/resources/me/summary')
            ->assertOk()->assertJsonPath('figures.0.key', 'total')->assertJsonPath('figures.0.value', 1);
    }

    public function test_query_controls_cannot_replace_the_actor_scope(): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $this->actingAs($ada);

        foreach (['id' => $bo->getKey(), 'email' => $bo->email] as $key => $value) {
            $url = '/frame/resources/me?'.http_build_query(['filter' => [$key => $value]]);
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $ada->getKey())->assertJsonPath('total', 1);
        }
        $this->getJson('/frame/resources/me?page=2&per_page=1')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('total', 1);
        $this->getJson('/frame/resources/me?filterVariant=users')->assertNotFound();
    }

    public function test_metadata_exposes_no_foreign_identity_selector(): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $calls = 0;
        DataFilter::options('other-identities', function () use (&$calls, $bo): array {
            $calls++;

            return [['value' => $bo->getKey(), 'label' => $bo->email]];
        });
        $this->actingAs($ada)->getJson('/frame/resources/me')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ada->getKey());
        $schema = $this->getJson('/frame/resources/me/filters/schema')->assertOk()
            ->assertJsonPath('data.type', 'object')->assertJsonPath('data.properties', []);
        $this->assertInstanceOf(\stdClass::class, json_decode($schema->getContent())->data->properties);
        $this->getJson('/frame/resources/me/filters/variants')->assertOk()->assertExactJson([
            'data' => ['resource' => 'me', 'variants' => []],
        ]);
        $this->getJson('/frame/resources/me/filters/options/other-identities')->assertNotFound();
        $this->getJson('/frame/resources/me/filters/users/schema')->assertNotFound();
        $this->assertSame(0, $calls);
    }

    public function test_guest_reads_are_refused_by_the_mounted_authentication_middleware(): void
    {
        $ada = $this->user('Ada');
        foreach (['', '/records/'.$ada->getKey(), '/summary', '/filters/schema', '/filters/variants', '/filters/options/other-identities'] as $suffix) {
            $this->getJson('/frame/resources/me'.$suffix)->assertUnauthorized();
        }
    }

    public function test_authenticated_generic_writes_cannot_change_either_identity(): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $before = MeStringKeyUser::orderBy('user_ref')->get()->map->getRawOriginal()->all();
        $this->actingAs($ada)->getJson('/frame/resources/me')->assertOk();
        $this->postJson('/frame/resources/me', ['name' => 'Injected', 'email' => 'new@example.test'])->assertForbidden();
        $ownUrl = '/frame/resources/me/records/'.$ada->getKey();
        $this->putJson($ownUrl, ['name' => 'Changed'])->assertStatus(405);
        $this->deleteJson($ownUrl)->assertStatus(405);
        $foreignUrl = '/frame/resources/me/records/'.$bo->getKey();
        $this->putJson($foreignUrl, ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson($foreignUrl)->assertForbidden();
        $this->assertSame($before, MeStringKeyUser::orderBy('user_ref')->get()->map->getRawOriginal()->all());
    }

    public function test_own_projection_preserves_bearer_and_contributions_without_projecting_foreign_records(): void
    {
        $ada = $this->user('Ada');
        $bo = $this->user('Bo');
        $projected = [];
        app(ResourceContributionRegistry::class)->register(new ResourceContribution(
            key: 'me',
            as: 'profile-note',
            data: MeProfileNoteData::class,
            value: function (Model $record, ReadContext $context, array $filters) use (&$projected): MeProfileNoteData {
                $projected[] = [$record->getKey(), $context->actor?->getAuthIdentifier()];

                return new MeProfileNoteData($record->email);
            },
        ));
        $this->actingAs($ada)->withToken('controlled-caller-bearer');
        $list = $this->getJson('/frame/resources/me')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.access_token', 'controlled-caller-bearer');
        $detail = $this->getJson('/frame/resources/me/records/'.$ada->getKey())->assertOk()
            ->assertJsonPath('data.profile-note.email', $ada->email);
        $singleton = $this->getJson('/identity')->assertOk();
        $this->assertSame($list->json('data.0'), $detail->json('data'));
        $this->assertSame($detail->json('data'), $singleton->json('data'));
        $this->getJson('/frame/resources/me/records/'.$bo->getKey())->assertNotFound();
        $this->assertSame(array_fill(0, 3, [$ada->getKey(), $ada->getKey()]), $projected);
        $this->withoutHeader('Authorization')->getJson('/frame/resources/me')->assertOk()
            ->assertJsonPath('data.0.access_token', null);
    }

    public static function identityReads(): array
    {
        return [
            'Frame list' => ['/frame/resources/me'],
            'Frame detail' => ['/frame/resources/me/records/person-Ada'],
            'current singleton' => ['/identity'],
        ];
    }

    #[DataProvider('identityReads')]
    public function test_identity_reads_refresh_the_real_permission_cache(string $url): void
    {
        $ada = $this->user('Ada');
        $permission = Permission::create(['name' => 'before', 'guard_name' => 'web']);
        $registrar = app(PermissionRegistrar::class);
        $this->assertSame(['before'], $registrar->getPermissions()->pluck('name')->all());
        DB::table('permissions')->where('id', $permission->getKey())->update(['name' => 'after']);
        $this->assertSame(['before'], $registrar->getPermissions()->pluck('name')->all());

        $this->actingAs($ada)->getJson($url)->assertOk();

        $this->assertSame(['after'], $registrar->getPermissions()->pluck('name')->all());
    }
}

class MeStringKeyUser extends User
{
    protected $table = 'me_users';

    protected $primaryKey = 'user_ref';
}

class MeProfileNoteData extends Data
{
    public function __construct(public string $email) {}
}
