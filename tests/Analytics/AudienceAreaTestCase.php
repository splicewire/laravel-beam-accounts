<?php

namespace Splicewire\Beam\Accounts\Tests\Analytics;

use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\RouteContextEntry;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Tests\TestCase;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * The Audience area's harness (analytics-area ticket 11): this package's own harness plus frame (for the list, summary
 * and manifest routes) and laravel-beam-analytics, which is a require-dev here, never a runtime edge.
 *
 * ⚠️ The analytics provider is added HERE only. The package's default {@see TestCase} does not boot it, so every other test
 * in this suite runs with analytics absent from the composition (charter ruling (A), condition 3).
 */
abstract class AudienceAreaTestCase extends TestCase
{
    /** @var list<string> emails holding `analytics.view` */
    protected array $analysts = [];

    /** @var list<string> emails holding `os.operate` */
    protected array $operators = [];

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataSchemasServiceProvider::class,
            FrameServiceProvider::class,
            DataFiltersServiceProvider::class,
            ...parent::getPackageProviders($app),
            BeamAnalyticsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('frame.middleware', []);
        $app['config']->set('cache.default', 'array');
        // Realm membership is the HOST's list (api-surface-coherence 142); this one mounts the operator's people and the
        // workspace's people where a host does.
        $app['config']->set('frame.realms', ['operator' => ['users', 'teams'], 'tenant' => ['members', 'invitations']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('entitlement:analytics.view', fn ($user = null): bool => $user !== null && in_array($user->email, $this->analysts, true));
        Gate::define('entitlement:os.operate', fn ($user = null): bool => $user !== null && in_array($user->email, $this->operators, true));
    }

    /** A team owned by `$email`, made that owner's current team, and the owner. */
    protected function ownerWithTeam(string $email = 'owner@example.test'): array
    {
        $owner = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'x']);
        $team = Team::create(['user_id' => $owner->id, 'name' => 'Acme', 'personal_team' => false]);
        Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
        $owner->switchTeam($team);

        return [$owner->fresh(), $team];
    }

    /** Frame mounted for one realm the way a host mounts it: `realm` as its routes' default (an operator mount, say). */
    protected function frameInRealm(string $realm): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'frame/')) {
                $route->defaults('realm', $realm);
            }
        }
    }

    /** Requests read in Tenant scope: beam's tenancy port reports a current tenant, as a tenancy layer's boundary would. */
    protected function inTenantScope(string $tenantId = 'acme'): void
    {
        $this->app->instance(TenancyContext::class, new class($tenantId) implements TenancyContext
        {
            public function __construct(private readonly string $id) {}

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return (object) ['id' => $this->id];
            }

            public function currentTenantId(): int|string|null
            {
                return $this->id;
            }

            public function centralConnection(): ?string
            {
                return null;
            }
        });
    }

    /**
     * The host's navigation, as beam-ux would project it: `$leaves` routeName => href are this reader's rail, and
     * `$bound` the route names this realm's route context binds (defaults to the rail's).
     *
     * @param  array<string, string>  $leaves
     * @param  list<string>|null  $bound
     */
    protected function rail(array $leaves, ?array $bound = null): void
    {
        $this->app->instance(FrameNavContributor::class, new class($leaves, $bound ?? array_keys($leaves)) implements FrameNavContributor
        {
            public function __construct(private readonly array $leaves, private readonly array $bound) {}

            public function contributeNav(?string $realm): ?array
            {
                return [
                    'nav' => ['items' => [['title' => 'People', 'routeName' => 'people.section', 'href' => '/people', 'children' => array_map(
                        fn (string $routeName, string $href): array => ['title' => $routeName, 'routeName' => $routeName, 'href' => $href, 'children' => []],
                        array_keys($this->leaves),
                        $this->leaves,
                    )]]],
                    'routeContext' => array_map(fn (string $routeName): RouteContextEntry => new RouteContextEntry($routeName, ltrim($routeName, '/')), $this->bound),
                ];
            }
        });
    }

    /** @return array<string, array{label: string, value: mixed, href: ?string}> the area page's figures, by id */
    protected function figures(): array
    {
        $out = [];
        foreach ($this->getJson('/frame/resources/analytics-audience')->assertOk()->json('data') as $row) {
            $out[$row['id']] = ['label' => $row['label'], 'value' => $row['value'], 'href' => $row['href'] ?? null];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> every row an index lists to the acting reader (fixtures are under one page) */
    protected function listed(string $resource): array
    {
        return $this->getJson("/frame/resources/{$resource}?per_page=100")->assertOk()->json('data');
    }
}
