<?php

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Localization\PreferredLocale;
use Splicewire\Beam\Accounts\Localization\PreferredLocaleSource;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification;
use Splicewire\Beam\Accounts\Teams\InvitationMailer;
use Splicewire\Beam\Accounts\Tests\Fixtures\LocalizedUser;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;
use Splicewire\Beam\Localization\DeliveryRecipient;
use Splicewire\Beam\Localization\LocaleScope;

/*
 * localization 36: a person chooses their language (ruling 03; owner resolutions (a) and (d)).
 * - The choice is explicit and saved on the central identity; null or empty clears it; negotiation never writes it.
 * - It is beam's person slot for a request and for a delivery.
 * - The four named reader cases: an existing member, a guest, a new member (self-addressed verification), and an
 *   invitee (known or unknown, in the invitation workspace's language, never the inviter's).
 */

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config([
        'beam.localization.supported' => ['en', 'es', 'de', 'fr'],
        'beam.localization.http_profile' => true,
        'auth.providers.users.model' => LocalizedUser::class,
        'beam.accounts.user_model' => LocalizedUser::class,
    ]);
    PreferredLocale::flush();
    // The tenant slot: a delivery's workspace default, or (for a request) the fixed "tenant English" of ruling 03's
    // named cases.
    app()->bind(TenantLocaleSource::class, fn () => new class implements TenantLocaleSource
    {
        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            if ($recipient instanceof DeliveryRecipient) {
                // Tenancy's adapter reads the workspace's data; here the invitation's team carries the default.
                return $recipient->workspace instanceof Team ? $recipient->workspace->getAttribute('default_locale') : null;
            }

            return $request !== null ? 'en' : null;
        }
    });
    Route::middleware('web')->get('/__locale', fn () => app()->getLocale());
    // A reset the person asked for, sent from inside their own routed request (the fixture has no reset-token table).
    Route::middleware('web')->post('/__self-reset/{email}', function (string $email) {
        LocalizedUser::query()->firstWhere('email', $email)->sendPasswordResetNotification('token');

        return 'sent';
    });
});

function localizedMember(string $email = 'ana@example.test', ?string $preference = null): LocalizedUser
{
    $user = LocalizedUser::query()->create(['name' => 'Ana', 'email' => $email, 'password' => bcrypt('password-1234')]);
    if ($preference !== null) {
        PreferredLocale::save($user, $preference);
    }

    return $user->refresh();
}

it('binds the person slot to the central preference', function () {
    expect(app(PersonLocaleSource::class))->toBeInstanceOf(PreferredLocaleSource::class);
});

it('saves an explicit choice, keeps it when omitted, clears it on null, and refuses an unsupported one', function () {
    $ana = localizedMember();

    $this->actingAs($ana)->patch('/settings/profile', ['name' => 'Ana', 'email' => $ana->email, 'preferredLocale' => 'es'])
        ->assertRedirect(route('profile.edit'));
    expect($ana->refresh()->preferred_locale)->toBe('es');

    $this->actingAs($ana)->patch('/settings/profile', ['name' => 'Ana B', 'email' => $ana->email]);
    expect($ana->refresh()->preferred_locale)->toBe('es', 'omitted leaves the choice as it was');

    $this->actingAs($ana)->patch('/settings/profile', ['name' => 'Ana', 'email' => $ana->email, 'preferredLocale' => null]);
    expect($ana->refresh()->preferred_locale)->toBeNull('null is automatic');

    $this->actingAs($ana)->patch('/settings/profile', ['name' => 'Ana', 'email' => $ana->email, 'preferredLocale' => 'xx'])
        ->assertSessionHasErrors('preferredLocale');
    expect($ana->refresh()->preferred_locale)->toBeNull();
});

it('offers the picker its state on the profile page', function () {
    $ana = localizedMember(preference: 'de');

    $props = $this->actingAs($ana)->get('/settings/profile', ['X-Inertia' => 'true'])->json('props');

    expect($props['preferredLocale'])->toBe('de')->and($props['supportedLocales'])->toBe(['en', 'es', 'de', 'fr']);
});

it('lets an existing member\'s browser Spanish beat tenant English with no choice saved, and never writes it', function () {
    $ana = localizedMember();

    expect($this->actingAs($ana)->get('/__locale', ['Accept-Language' => 'es-ES,es;q=0.9'])->getContent())->toBe('es')
        ->and($ana->refresh()->preferred_locale)->toBeNull('negotiation never writes the preference');
});

it('lets a saved choice beat the browser on the next page and after signing in again', function () {
    $ana = localizedMember(preference: 'de');

    expect($this->actingAs($ana)->get('/__locale', ['Accept-Language' => 'es'])->getContent())->toBe('de');
    auth()->logout();
    expect($this->actingAs($ana->refresh())->get('/__locale', ['Accept-Language' => 'es'])->getContent())->toBe('de');
});

it('gives a guest the browser\'s supported language, else the tenant default', function () {
    expect($this->get('/__locale', ['Accept-Language' => 'es'])->getContent())->toBe('es')
        ->and($this->get('/__locale', ['Accept-Language' => 'ja, *;q=0.5'])->getContent())->toBe('en', 'unsupported and wildcard continue to the tenant default')
        ->and($this->get('/__locale', ['Accept-Language' => 'es;q=0, de'])->getContent())->toBe('de', 'q=0 is never chosen');
});

it('sends a new member\'s verification in the language of the request that registered, and saves nothing', function () {
    Notification::fake();
    // The listener a Laravel host wires by default (the framework's EventServiceProvider); testbench has none.
    Event::listen(Registered::class, SendEmailVerificationNotification::class);

    $this->post('/register', ['name' => 'Nuevo', 'email' => 'nuevo@example.test', 'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234'], ['Accept-Language' => 'es'])->assertRedirect();

    $member = LocalizedUser::query()->firstWhere('email', 'nuevo@example.test');
    expect($member->preferred_locale)->toBeNull();
    Notification::assertSentTo($member, VerifyEmail::class, fn ($n, $channels, $notifiable, $locale) => $locale === 'es');
});

it('sends a self-requested reset in the request\'s language, and any other delivery in the saved choice', function () {
    Notification::fake();
    $ana = localizedMember(preference: 'de');

    $this->post('/__self-reset/'.$ana->email, [], ['Accept-Language' => 'es'])->assertOk();
    Notification::assertSentTo($ana, ResetPassword::class, fn ($n, $c, $notifiable, $locale) => $locale === 'es');

    $ana->notify(new VerifyEmail);
    Notification::assertSentTo($ana, VerifyEmail::class, fn ($n, $c, $notifiable, $locale) => $locale === 'de');
});

/** An invitation to a workspace with a default language, sent while the INVITER's request is Spanish. */
function inviteFromSpanish(string $email, ?string $workspaceDefault): void
{
    $invitation = new Invitation(['email' => $email, 'role' => 'member', 'team_id' => 'acme']);
    $invitation->setAttribute('token', 'tok');
    $redemption = Mockery::mock(Splicewire\Beam\Accounts\Teams\InvitationRedemption::class);
    $redemption->shouldReceive('teamName')->andReturn('Acme');
    $redemption->shouldReceive('acceptUrl')->andReturn('https://acme.test/invitations/tok');
    $redemption->shouldReceive('inviterName')->andReturn('Olive');
    $redemption->shouldReceive('expiresAt')->andReturn(Illuminate\Support\Carbon::parse('2026-11-14 12:00'));
    $team = new Team(['name' => 'Acme']);
    $team->setAttribute('default_locale', $workspaceDefault);
    $redemption->shouldReceive('team')->andReturn($team);

    LocaleScope::run('es', fn () => (new InvitationMailer($redemption))->send($invitation));
}

it('never lets a Spanish inviter make an unknown English-workspace invite Spanish', function () {
    Notification::fake();
    inviteFromSpanish('nobody@example.test', 'en');

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $mail, $c, $notifiable, $locale) {
        $text = LocaleScope::run((string) $locale, fn () => implode(' ', [...$mail->toMail($notifiable)->introLines, ...$mail->toMail($notifiable)->outroLines]));

        return $locale === 'en' && str_contains($text, 'Sat, Nov 14, 2026');
    });
});

it('sends an unknown invitee the workspace\'s language, with the expiry date formatted in it', function () {
    Notification::fake();
    inviteFromSpanish('nadie@example.test', 'de');

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $mail, $c, $notifiable, $locale) {
        $text = LocaleScope::run((string) $locale, fn () => implode(' ', [...$mail->toMail($notifiable)->introLines, ...$mail->toMail($notifiable)->outroLines]));

        return $locale === 'de' && str_contains($text, 'Nov') && str_contains($text, 'Sa');
    });
});

it('sends a known invitee their own saved language, whatever the workspace default', function () {
    Notification::fake();
    localizedMember('known@example.test', 'fr');
    inviteFromSpanish('known@example.test', 'de');

    Notification::assertSentOnDemand(TeamInvitationNotification::class, fn ($mail, $c, $notifiable, $locale) => $locale === 'fr');
});

it('reads a tenant-side replica\'s preference from its central identity, never a replica column', function () {
    $ana = localizedMember(preference: 'fr');
    $replica = new class extends Model
    {
        protected $table = 'users';

        public $incrementing = false;

        protected $keyType = 'string';

        public function getCentralModelName(): string
        {
            return LocalizedUser::class;
        }

        public function getGlobalIdentifierKeyName(): string
        {
            return 'id';
        }

        public function getGlobalIdentifierKey(): mixed
        {
            return $this->getAttribute('id');
        }
    };
    $replica->setRawAttributes(['id' => $ana->getKey(), 'preferred_locale' => 'de']);

    expect(PreferredLocale::of($replica))->toBe('fr')
        ->and(app(PersonLocaleSource::class)->locale(null, $replica))->toBe('fr');
});

it('treats a host without the column as having no preference, and skips the write', function () {
    Illuminate\Support\Facades\Schema::table('users', fn ($t) => $t->dropColumn('preferred_locale'));
    PreferredLocale::flush();
    $ana = LocalizedUser::query()->create(['name' => 'Ana', 'email' => 'nocol@example.test', 'password' => 'x']);

    PreferredLocale::save($ana, 'es');

    expect(PreferredLocale::of($ana->refresh()))->toBeNull();
});
