<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Http\ErrorPages;
use Splicewire\Beam\Accounts\Localization\AccountAuthorizationException;
use Splicewire\Beam\Accounts\Localization\AccountError;
use Splicewire\Beam\Accounts\Localization\AccountHttpException;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Notifications\ResetPasswordNotification;
use Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\PseudoLocale;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('marks every declared refusal at the actual JSON exception boundary and keeps every stable code', function () {
    config(['beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    $params = ['subject' => 'missing', 'expected' => 'known', 'email' => 'ada@example.test', 'permissions' => 'write', 'role' => 'owner', 'type' => 'stdClass', 'resource' => 'members'];
    foreach (AccountError::cases() as $case) {
        Route::get('/loc22/error/'.$case->value, fn () => $case->abort(403, $params));
        $response = $this->getJson('/loc22/error/'.$case->value)->assertForbidden()->assertJsonPath('code', $case->value);
        expect(PseudoLocale::isMarked($response->json('message')))->toBeTrue($case->value);
        expect($response->json('message'))->not->toMatch('/:(subject|expected|email|permissions|role|type|resource)\b/');
    }
});

it('keeps exception parent types and HTTP statuses, and the branded browser response still renders', function () {
    $http = new AccountHttpException(410, AccountError::InvitationUsed);
    expect($http)->toBeInstanceOf(HttpException::class)->and($http->getStatusCode())->toBe(410)
        ->and($http->getMessage())->toBe('This invitation has already been used.');
    $auth = new AccountAuthorizationException(AccountError::RemoveOwner);
    expect($auth)->toBeInstanceOf(AuthorizationException::class)->and($auth->getMessage())->toBe('The team owner cannot be removed.');
    Route::get('/loc22/authorization', fn () => throw new AccountAuthorizationException(AccountError::RemoveOwner));
    $this->getJson('/loc22/authorization')->assertForbidden()->assertExactJson(['message' => 'The team owner cannot be removed.', 'code' => 'remove_owner']);
    $exceptions = new Exceptions(app(ExceptionHandler::class));
    ErrorPages::register($exceptions);
    Route::get('/loc22/browser', fn () => AccountError::NotTeamMember->abort(403));
    $this->get('/loc22/browser', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertForbidden()->assertJsonPath('component', 'error')
        ->assertJsonPath('props.status', 403)->assertJsonPath('props.message', 'You are not a member of this team.');
});

it('marks field validation errors and preserves the codes map without changing its parent type', function () {
    config(['beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    foreach ([AccountError::AlreadyMember, AccountError::DemoUnknown, AccountError::TokenAbilities, AccountError::ResetInvalidToken, AccountError::ResetInvalidUser, AccountError::ResetThrottled] as $case) {
        Route::post('/loc22/validation/'.$case->value, fn () => throw CodedValidationException::for('email', $case, ['email' => 'ada@example.test', 'subject' => 'unknown', 'expected' => 'demo', 'permissions' => 'write']));
        $response = $this->postJson('/loc22/validation/'.$case->value)->assertUnprocessable()->assertJsonPath('codes.email.0', $case->value);
        expect(PseudoLocale::isMarked($response->json('errors.email.0')))->toBeTrue();
    }
});

it('marks both complete mail messages, including their subjects and actions, leaving authored values intact', function () {
    config(['beam.localization.pseudo' => true, 'app.name' => 'Example Brand']);
    app()->setLocale('en-XA');
    $recipient = new class implements Illuminate\Contracts\Auth\CanResetPassword
    {
        public function getEmailForPasswordReset(): string
        {
            return 'ada@example.test';
        }

        public function sendPasswordResetNotification($token): void {}
    };
    $reset = (new ResetPasswordNotification('tok'))->toMail($recipient);
    $invitation = new Invitation(['email' => 'ada@example.test', 'role' => 'member']);
    $invite = (new TeamInvitationNotification($invitation, 'https://app.test/accept', 'A & B', null, '2030-01-01'))->toMail($recipient);
    foreach ([$reset, $invite] as $mail) {
        foreach ([$mail->subject, $mail->actionText, ...$mail->introLines, ...$mail->outroLines] as $line) {
            expect(PseudoLocale::isMarked($line))->toBeTrue($line);
        }
    }
    expect($reset->subject)->toContain('Example Brand')->and(implode(' ', $invite->introLines))->toContain('A & B');
});

it('marks every product key at a JSON wire boundary and never returns an unresolved key', function () {
    config(['beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    $messages = require __DIR__.'/../lang/en/messages.php';
    Route::get('/loc22/catalog', fn () => response()->json(array_map(fn ($key) => __('beam-accounts::messages.'.$key, ['app' => 'Brand', 'name' => 'Ada', 'email' => 'ada@example.test', 'brand' => 'Brand', 'minutes' => 60, 'who' => 'Ada', 'team' => 'A & B', 'role' => 'Member', 'date' => '2030-01-01', 'count' => 2]), array_keys($messages))));
    foreach ($this->getJson('/loc22/catalog')->assertOk()->json() as $line) {
        expect(PseudoLocale::isMarked($line))->toBeTrue($line);
        expect($line)->not->toContain('beam-accounts::');
    }
});

it('marks both packaged Blade journeys through HTTP and all branded page fallback messages', function () {
    config(['beam.localization.pseudo' => true, 'beam.accounts.operator_shell.developer_note' => false]);
    app()->setLocale('en-XA');
    $user = Splicewire\Beam\Accounts\Tests\Fixtures\User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    Illuminate\Support\Facades\Gate::define('entitlement:os.operate', fn () => true);
    $logout = $this->actingAs($user)->get('/logout')->assertOk()->getContent();
    foreach (['logout_heading', 'logout_action', 'cancel'] as $key) {
        expect($logout)->toContain(e(__('beam-accounts::messages.'.$key)));
    }
    expect($logout)->toContain('lang="en-XA"');
    $operator = $this->get('/operator')->assertOk()->getContent();
    foreach (['operator_title', 'operator_users', 'operator_teams', 'operator_invitations', 'operator_manage', 'operator_empty'] as $key) {
        expect($operator)->toContain(e(__('beam-accounts::messages.'.$key)));
    }
    expect($operator)->toContain('lang="en-XA"')->not->toContain('not for operators');
    foreach ([403, 404, 419, 500, 503] as $status) {
        $page = ErrorPages::page($status);
        expect(PseudoLocale::isMarked($page->title))->toBeTrue();
        expect(PseudoLocale::isMarked($page->message))->toBeTrue();
    }
});

it('codes accounts policy responses and leaves another package code untouched', function () {
    Route::get('/loc22/our-policy', fn () => (new Splicewire\Beam\Accounts\Localization\AccountAuthorizationResponse(AccountError::AuthenticationRequired))->authorize());
    Route::get('/loc22/foreign-policy', fn () => Illuminate\Auth\Access\Response::deny('Foreign policy wording.', 'authentication_required')->authorize());
    config(['app.debug' => false]);
    $this->getJson('/loc22/our-policy')->assertForbidden()->assertExactJson(['message' => 'Authentication required.', 'code' => 'authentication_required']);
    $this->getJson('/loc22/foreign-policy')->assertForbidden()->assertExactJson(['message' => 'Foreign policy wording.']);
});
