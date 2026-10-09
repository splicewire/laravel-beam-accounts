<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * An address that is already an ACTIVE member of the team is not invitable, through EITHER transport (the REST
 * `POST /beam/accounts/invitations` and Frame's `POST /frame/resources/invitations`, which share
 * `InvitationData::prepare()`), and in ANY letter case: emails compare case-insensitively, as invitation redemption
 * already does (`strcasecmp`). A refusal writes nothing. Round 2 of the TOWER-05 gate LOW (build-qa: a case variant
 * bypassed the exact-equality member check and re-opened the accepted invite).
 */
class InvitationActiveMemberTest extends TestCase
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

    private Team $team;

    private User $owner;

    private Invitation $accepted;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Route::splicewireAccountApiRoutes();
        Route::getRoutes()->refreshNameLookups();
        // the real particle declaration through Frame's producer slot, as SharingFrameResourceTest does
        $resource = app(ParticleResourceRegistry::class)->get('invitations');
        app(CompositeResourceRegistry::class)->register($resource->toResourceDefinition());
        $this->assertTrue(app(ResourceRegistry::class)->has('invitations'));

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
        $this->team = Team::create(['user_id' => $this->owner->id, 'name' => 'T', 'personal_team' => true]);
        Membership::create(['team_id' => $this->team->id, 'user_id' => $this->owner->id, 'role' => Role::Owner->value]);
        $this->owner->switchTeam($this->team);

        $solo = User::create(['name' => 'Solo', 'email' => 'solo@example.test', 'password' => 'x']);
        Membership::create(['team_id' => $this->team->id, 'user_id' => $solo->id, 'role' => Role::Member->value]);
        $this->accepted = Invitation::create(['team_id' => $this->team->id, 'email' => 'solo@example.test', 'role' => 'member', 'token' => str_repeat('a', 64), 'invited_by' => $this->owner->id, 'accepted_at' => now()->subDay()]);

        Auth::login($this->owner->fresh());
    }

    public static function cases(): array
    {
        $out = [];
        foreach (['rest' => '/beam/accounts/invitations', 'frame' => '/frame/resources/invitations'] as $transport => $url) {
            foreach (['solo@example.test', 'Solo@Example.TEST', ' SOLO@example.test ', 'OWNER@example.test'] as $email) {
                $out["{$transport} ".trim($email)] = [$url, $email];
            }
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_an_active_member_in_any_letter_case_is_refused_with_422_and_nothing_is_written(string $url, string $email): void
    {
        $before = Invitation::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->postJson($url, ['email' => $email, 'role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame($before, Invitation::query()->orderBy('id')->get()->map->getAttributes()->all(), 'a refusal writes nothing');
        $this->assertNotNull($this->accepted->fresh()->accepted_at);
        $this->assertSame(0, Invitation::whereNull('accepted_at')->count(), 'no Pending row');
    }

    public function test_frame_create_still_invites_a_new_address(): void
    {
        $this->postJson('/frame/resources/invitations', ['email' => 'New@Example.test', 'role' => 'member'])->assertSuccessful();

        $this->assertSame(1, Invitation::whereNull('accepted_at')->count());
    }
}
