<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasswordResetController;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Ops\RedeemInvitation;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Localization\PseudoLocale;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LocalizationAmendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createPersonalAccessTokensSchema();
        config(['cache.default' => 'array', 'app.debug' => false]);
        Route::splicewireAccountApiRoutes();
        Route::get('/loc22-amend/redeem', fn (Request $request) => $this->refuse($request));
        Route::post('/loc22-amend/reset', [PasswordResetController::class, 'reset']);
        Route::get('/loc22-amend/me-policy', function (Request $request) {
            $resource = app(\Splicewire\Beam\Particle\ParticleResourceRegistry::class)->get('me');
            app(\Splicewire\Beam\Accounts\Authorization\MePolicy::class)->inspect(null, $resource, $request)->authorize();
        });
        Route::get('/loc22-amend/members-policy', function (Request $request) {
            $resource = app(\Splicewire\Beam\Particle\ParticleResourceRegistry::class)->get('members');
            app(\Splicewire\Beam\Accounts\Authorization\TeamMemberReadPolicy::class)->inspect($request->user(), $resource, $request)->authorize();
        });
        Route::post('/loc22-amend/team-engine/{team}', function (Request $request, Team $team) {
            app(\Splicewire\Beam\Accounts\Teams\TeamMembers::class)->invite($team, $request->user(), 'engine@example.test');
        });
        Route::getRoutes()->refreshNameLookups();
    }

    private function refuse(Request $request): never
    {
        // Exercise the real refusal, including its Laravel abort mapping, without invitation state setup.
        (new \ReflectionMethod(RedeemInvitation::class, 'refuse'))->invoke(null, 'nope', $request);
        throw new \LogicException('The refusal returned.');
    }

    public function test_real_invitation_refusal_preserves_not_found_parent(): void
    {
        try {
            $this->refuse(Request::create('/unused', 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
            $this->fail('Expected a refusal.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(NotFoundHttpException::class, $exception);
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame('This invitation link is not valid.', $exception->getMessage());
        }
    }

    public function test_password_framework_overrides_keep_stable_codes(): void
    {
        $this->passwordOverrides(true);
    }

    public function test_password_framework_copy_is_preserved(): void
    {
        $this->passwordOverrides(false);
    }

    private function passwordOverrides(bool $coded): void
    {
        $broker = \Mockery::mock();
        Password::shouldReceive('broker')->andReturn($broker);
        foreach ([Password::INVALID_TOKEN => 'reset_invalid_token', Password::INVALID_USER => 'reset_invalid_user', Password::RESET_THROTTLED => 'reset_throttled'] as $key => $code) {
            app('translator')->addLines([$key => 'Host copy for '.$key], 'en');
            $broker->shouldReceive('reset')->once()->andReturn($key);
            $line = 'Host copy for '.$key;
            $body = ['message' => $line, 'errors' => ['email' => [$line]]];
            if ($coded) {
                $body['codes'] = ['email' => [$code]];
            }
            $response = $this->postJson('/loc22-amend/reset', ['email' => 'x@example.test', 'token' => 'x', 'password' => 'password-1234', 'passwordConfirmation' => 'password-1234'])
                ->assertUnprocessable();
            if ($coded) {
                $response->assertExactJson($body);
            } else {
                $response->assertJsonPath('message', $line)->assertJsonPath('errors.email.0', $line);
            }
        }
    }

    public function test_real_error_english_status_and_messages(): void
    {
        foreach ($this->journey() as [$response, $status, $message]) {
            $response->assertStatus($status)->assertJsonPath('message', $message);
        }
    }

    public function test_real_error_json_is_exact_and_additive(): void
    {
        foreach ($this->journey() as [$response, $status, $message, $code, $field]) {
            $body = ['message' => $message];
            if ($field !== null) {
                $body['errors'] = [$field => [$message]];
                $body['codes'] = [$field => [$code]];
            } else {
                $body['code'] = $code;
            }
            $response->assertStatus($status)->assertExactJson($body);
        }
    }

    private function journey(): array
    {
        $owner = AmendTokenUser::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
        $member = AmendTokenUser::create(['name' => 'Mem', 'email' => 'mem@example.test', 'password' => 'x']);
        $loner = AmendTokenUser::create(['name' => 'Lone', 'email' => 'lone@example.test', 'password' => 'x']);
        $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => false]);
        Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
        Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
        $owner->switchTeam($team);
        $member->switchTeam($team);
        $this->actingAs($loner->fresh());
        $rows = [[$this->getJson('/beam/accounts/members'), 403, 'No active team.', 'no_active_team', null]];
        $rows[] = [$this->getJson('/loc22-amend/me-policy'), 403, 'Authentication required.', 'authentication_required', null];
        $rows[] = [$this->getJson('/loc22-amend/members-policy'), 403, 'Reading [members] requires membership in the current team.', 'resource_membership_required', null];
        $this->actingAs($member->fresh());
        $rows[] = [$this->postJson('/loc22-amend/team-engine/'.$team->id), 403, 'Only a team owner may manage members.', 'team_manage_required', null];
        $rows[] = [$this->putJson('/beam/accounts/members/'.$owner->id.'/role', ['role' => 'member']), 403, 'Only a team owner can manage members.', 'team_owner_required', null];
        $rows[] = [$this->postJson('/beam/accounts/invitations', ['email' => 'x@example.test', 'role' => 'member']), 403, 'Only owners and admins can manage invitations.', 'invitation_manager_required', null];
        $this->actingAs($owner->fresh());
        $rows[] = [$this->postJson('/beam/accounts/invitations', ['email' => 'mem@example.test', 'role' => 'member']), 422, 'mem@example.test is already a member of this team.', 'already_member', 'email'];
        $rows[] = [$this->postJson('/beam/accounts/tokens', ['name' => 'X', 'abilities' => ['not.held']]), 422, 'A token can only be scoped to permissions you hold. Not held: not.held.', 'token_abilities', 'abilities'];
        $rows[] = [$this->getJson('/loc22-amend/redeem'), 404, 'This invitation link is not valid.', 'invitation_invalid', null];

        return $rows;
    }

    private function roleOwner(): void
    {
        $owner = AmendTokenUser::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x']);
        $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => false]);
        Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
        $owner->switchTeam($team);
        $this->actingAs($owner->fresh());
    }

    public function test_coded_exception_preserves_laravel_json_flags_and_headers(): void
    {
        Route::get('/loc22-amend/headers', fn () => throw new class(403, \Splicewire\Beam\Accounts\Localization\AccountError::NoActiveTeam) extends \Splicewire\Beam\Accounts\Localization\AccountHttpException
        {
            public function getHeaders(): array
            {
                return ['X-Accounts-Refusal' => 'kept'];
            }
        });
        $response = $this->getJson('/loc22-amend/headers')->assertForbidden()->assertHeader('X-Accounts-Refusal', 'kept');
        $this->assertSame(json_encode(['message' => 'No active team.', 'code' => 'no_active_team'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $response->getContent());
    }

    public function test_packaged_view_locale_tags_use_bcp47_separators(): void
    {
        app()->setLocale('en_US');
        $html = view('beam-accounts::logout-confirm', ['appName' => 'Brand', 'action' => '/logout'])->render();
        $this->assertStringContainsString('lang="en-US"', $html);
        $this->assertStringNotContainsString('lang="en_US"', $html);
    }

    public function test_roles_endpoint_english_shape_is_unchanged(): void
    {
        $this->roleOwner();
        $all = [['value' => 'owner', 'label' => 'Owner'], ['value' => 'admin', 'label' => 'Admin'], ['value' => 'member', 'label' => 'Member']];
        $this->getJson('/beam/accounts/members/roles')->assertOk()->assertExactJson(['data' => ['assignable' => $all, 'invitable' => array_slice($all, 1)]]);
    }

    public function test_roles_endpoint_pseudo_marks_labels_without_changing_values(): void
    {
        config(['beam.localization.pseudo' => true]);
        app()->setLocale('en-XA');
        $this->roleOwner();
        $response = $this->getJson('/beam/accounts/members/roles')->assertOk();
        $this->assertSame(['owner', 'admin', 'member'], array_column($response->json('data.assignable'), 'value'));
        foreach (array_merge($response->json('data.assignable'), $response->json('data.invitable')) as $option) {
            $this->assertTrue(PseudoLocale::isMarked($option['label']), $option['label']);
        }
    }
}

class AmendTokenUser extends User
{
    use HasApiTokens;

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
