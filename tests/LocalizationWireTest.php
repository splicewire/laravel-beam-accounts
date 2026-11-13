<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use PHPUnit\Framework\Attributes\Group;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\LoginController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyLoginController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasswordResetController;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Passkeys\PasskeyAuthenticator;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Localization\PseudoLocale;

/** The same real controller journey at English base/candidate and en-XA candidate. */
class LocalizationWireTest extends TestCase
{
    private bool $pseudo = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createPersonalAccessTokensSchema();
        config(['cache.default' => 'array', 'app.debug' => false]);
        Route::splicewireAccountApiRoutes();
        Route::post('loc22/login', [LoginController::class, 'login']);
        Route::post('loc22/passkey-login', [PasskeyLoginController::class, 'verify']);
        Route::post('loc22/passkey-store', [PasskeyController::class, 'store']);
        Route::post('loc22/password-email', [PasswordResetController::class, 'sendResetLink']);
        Route::post('loc22/password-reset', [PasswordResetController::class, 'reset']);
        Route::post('loc22/logout', [\Splicewire\Beam\Accounts\Http\Controllers\Api\V1\LogoutController::class, 'logout']);
        Route::delete('loc22/passkeys/{id}', function (\Illuminate\Http\Request $request, string $id) {
            return app(PasskeyController::class)->destroy($request, \Laravel\Passkeys\Passkey::query()->findOrFail($id));
        });
        Route::getRoutes()->refreshNameLookups();
        $this->app->instance(PasskeyAuthenticator::class, \Mockery::mock(PasskeyAuthenticator::class));
    }

    private function message($response, int $status, string $english, ?string $code = null): void
    {
        $response->assertStatus($status);
        if ($this->pseudo) {
            $this->assertTrue(PseudoLocale::isMarked($response->json('message')), $english);
            if ($code !== null) {
                $actual = $response->json('code') ?? $response->json('meta.code');
                $this->assertSame($code, $actual);
            }
        } else {
            $response->assertJsonPath('message', $english);
        }
    }

    public function test_english_response_snapshots_at_each_changed_endpoint(): void
    {
        $this->journey();
    }

    #[Group('localization-pseudo')]
    public function test_pseudo_messages_at_each_changed_endpoint(): void
    {
        $this->pseudo = true;
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale('en-XA');
        $this->journey();
    }

    private function journey(): void
    {
        $this->message($this->postJson('/loc22/login', ['email' => 'absent@example.test', 'password' => 'wrong']), 401, 'Unauthorized.', 'unauthorized');
        $this->message($this->postJson('/loc22/passkey-login', ['handle' => 'expired', 'credential' => ['id' => 'unused']]), 401, 'This passkey challenge has expired. Please try again.', 'passkey_challenge_expired');
        $this->message($this->postJson('/loc22/passkey-store', ['handle' => 'expired', 'name' => 'Key', 'credential' => ['id' => 'unused']]), 400, 'This passkey challenge has expired. Please try again.', 'passkey_challenge_expired');

        $store = \Mockery::mock(\Splicewire\Beam\Accounts\Passkeys\PasskeyChallengeStore::class);
        $store->shouldReceive('pull')->andReturn(\Webauthn\PublicKeyCredentialRequestOptions::create(challenge: 'challenge-bytes'));
        $this->app->instance(\Splicewire\Beam\Accounts\Passkeys\PasskeyChallengeStore::class, $store);
        $this->app->make(PasskeyAuthenticator::class)->shouldReceive('resolveUserFromAssertion')->once()->andReturn(null);
        $b64 = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $credential = [
            'id' => $b64('credential-id'), 'rawId' => $b64('credential-id'), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $b64(json_encode(['type' => 'webauthn.get', 'challenge' => $b64('challenge-bytes'), 'origin' => 'https://test'])),
                'authenticatorData' => $b64(str_repeat("\x00", 32).chr(1).str_repeat("\x00", 4)),
                'signature' => $b64('signature'), 'userHandle' => $b64('user-handle'),
            ],
        ];
        $this->message($this->postJson('/loc22/passkey-login', ['handle' => 'valid-challenge', 'credential' => $credential]), 401, 'Passkey verification failed.', 'passkey_verification_failed');

        $broker = \Mockery::mock();
        \Illuminate\Support\Facades\Password::shouldReceive('broker')->andReturn($broker);
        $broker->shouldReceive('sendResetLink')->andReturn(\Illuminate\Support\Facades\Password::RESET_LINK_SENT);
        $this->message($this->postJson('/loc22/password-email', ['email' => 'absent@example.test']), 200, 'If an account exists for that email, a reset link is on its way.');
        $broker->shouldReceive('reset')->andReturn(\Illuminate\Support\Facades\Password::PASSWORD_RESET, \Illuminate\Support\Facades\Password::INVALID_TOKEN);
        $input = ['email' => 'absent@example.test', 'token' => 'token', 'password' => 'password-1234', 'passwordConfirmation' => 'password-1234'];
        $this->message($this->postJson('/loc22/password-reset', $input), 200, 'Your password has been reset.');
        $invalid = $this->postJson('/loc22/password-reset', $input)->assertUnprocessable();
        if ($this->pseudo) {
            $this->assertTrue(PseudoLocale::isMarked($invalid->json('errors.email.0')));
            $invalid->assertJsonPath('codes.email.0', 'reset_invalid_token');
        } else {
            $invalid->assertJsonPath('errors.email.0', 'This password reset token is invalid.');
        }

        $owner = LocalizationTokenUser::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
        $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
        Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
        $owner->switchTeam($team);
        $this->actingAs($owner->fresh());

        $root = '/beam/accounts';
        $this->message($this->postJson("$root/tokens/missing/renew", []), 404, 'Token not found', 'token_not_found');
        $this->message($this->postJson("$root/tokens/missing/rotate", []), 404, 'Token not found', 'token_not_found');
        $this->message($this->deleteJson("$root/tokens/missing"), 404, 'Token not found', 'token_not_found');
        $this->message($this->deleteJson("$root/tokens/missing/permanent"), 404, 'Token not found', 'token_not_found');
        $created = $this->postJson("$root/tokens", ['name' => 'CI'])->assertCreated()->json('data');
        $id = $created['id'];
        $this->message($this->deleteJson("$root/tokens/$id/permanent"), 422, 'Archive the token before deleting it.', 'token_delete_active');
        $this->message($this->deleteJson("$root/tokens/$id"), 200, 'Token archived');
        $this->message($this->deleteJson("$root/tokens/$id"), 409, 'Token already archived', 'token_already_archived');
        $this->message($this->postJson("$root/tokens/$id/renew", []), 422, 'Cannot renew an archived token.', 'token_renew_archived');
        $this->message($this->postJson("$root/tokens/$id/rotate", []), 422, 'Cannot rotate an archived token.', 'token_rotate_archived');
        $this->message($this->deleteJson("$root/tokens/$id/permanent"), 200, 'Token deleted');

        $current = $this->postJson("$root/tokens", ['name' => 'Current'])->assertCreated()->json('data');
        $this->withToken($current['token']);
        $this->message($this->postJson("$root/tokens/{$current['id']}/rotate", []), 422, 'You cannot rotate the token you are using.', 'token_rotate_current');
        $this->message($this->deleteJson("$root/tokens/{$current['id']}"), 422, 'You cannot archive the token you are using.', 'token_archive_current');
        $this->message($this->deleteJson("$root/tokens/{$current['id']}/permanent"), 422, 'You cannot delete the token you are using.', 'token_delete_current');
        $this->message($this->deleteJson("$root/tokens/sessions/others"), 200, 'Revoked 0 other session(s).');
        $this->message($this->postJson("$root/invitations/missing/resend"), 404, 'Invitation not found', 'invitation_not_found');
        $this->message($this->deleteJson("$root/invitations/missing"), 404, 'Invitation not found', 'invitation_not_found');
        $this->message($this->putJson("$root/members/missing/role", ['role' => 'member']), 404, 'Member not found', 'member_not_found');
        $this->message($this->deleteJson("$root/members/missing"), 404, 'Member not found', 'member_not_found');
        $this->message($this->putJson("$root/members/{$owner->id}/role", ['role' => 'member']), 422, 'A team must keep at least one owner.', 'last_owner');
        $this->message($this->deleteJson("$root/members/{$owner->id}"), 422, 'A team must keep at least one owner.', 'last_owner');
        \Illuminate\Support\Facades\Schema::create('passkeys', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('name');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        $passkey = new \Laravel\Passkeys\Passkey;
        $passkey->forceFill(['user_id' => $owner->id, 'name' => 'Key'])->save();
        $this->message($this->deleteJson('/loc22/passkeys/'.$passkey->id), 200, 'Passkey removed.');
        $this->message($this->postJson('/loc22/logout'), 200, 'Signed out.');
    }
}

class LocalizationTokenUser extends User
{
    use HasApiTokens;

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
