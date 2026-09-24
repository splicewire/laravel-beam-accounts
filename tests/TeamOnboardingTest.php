<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * Getting a teamless user onto a team: self-service creation (`teams.create` → the `CreateTeam` op)
 * and invitation acceptance (the emailed signed `invitations.accept` link → the `RedeemInvitation` op).
 *
 * The gate is CLOSED throughout — nothing installs a blanket `Gate::before` — so the refusals below
 * (a guest, a wrong address, a used or expired link, a member inviting) are real denials, which is what
 * says this suite ran rather than passed by not running.
 *
 * The routes are the package's own auto-mount (`register_routes` defaults on in this harness), which is
 * also the assertion that the four names exist at a host that leaves the flag alone.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
});

function onboardingUser(string $email, string $name = 'Person'): User
{
    return User::create(['name' => $name, 'email' => $email, 'password' => 'password-1234']);
}

/** An owner on a shared team, created the way the self-service op creates one. */
function onboardingOwner(string $email = 'owner@example.test', string $team = 'Acme'): array
{
    $owner = onboardingUser($email, 'Olive Owner');
    $created = app(TeamProvisioner::class)->createTeamFor($owner, $team);

    return [$owner->fresh(), $created];
}

function onboardingInvite(Team $team, User $by, string $email, string $role = 'member'): Invitation
{
    return Invitation::create([
        'team_id' => (string) $team->getKey(),
        'email' => $email,
        'role' => $role,
        'token' => str_repeat('a', 32).bin2hex(random_bytes(16)),
        'invited_by' => $by->getKey(),
    ]);
}

function acceptLink(Invitation $invitation): string
{
    return app(InvitationRedemption::class)->acceptUrl($invitation);
}

// ── The mount ──────────────────────────────────────────────────────────────────────────────────

it('mounts the four routes by the names DashboardWelcome and the pages ask for', function () {
    foreach (['teams.create', 'teams.store', 'invitations.accept', 'invitations.redeem'] as $name) {
        expect(Route::has($name))->toBeTrue("route [{$name}] is not mounted");
    }

    expect(route('teams.create', [], false))->toBe('/teams/create')
        ->and(route('teams.store', [], false))->toBe('/teams/create')
        ->and(route('invitations.accept', ['token' => 'abc'], false))->toBe('/invitations/abc')
        ->and(route('invitations.redeem', ['id' => 'abc'], false))->toBe('/invitations/abc/redeem');

    // `teams.create` must be linkable with no parameters — DashboardWelcome builds it bare.
    expect(Route::getRoutes()->getByName('teams.create')->parameterNames())->toBe([]);
});

it('declares both operations with every shape slot, ungated on purpose rather than undeclared', function () {
    $operations = app(ParticleOperationRegistry::class);

    $create = $operations->get('teams', 'create');
    $redeem = $operations->get('invitations', 'redeem');

    expect($create->ability)->toBeFalse()
        ->and($create->input)->toBe(Splicewire\Beam\Accounts\Data\CreateTeamInputData::class)
        ->and($create->output)->toBe(Splicewire\Beam\Accounts\Data\TeamData::class)
        ->and($redeem->ability)->toBeFalse()
        ->and($redeem->input)->toBeFalse()
        ->and($redeem->output)->toBe(Splicewire\Beam\Accounts\Data\InvitationAcceptedData::class);
});

// ── Create a team ──────────────────────────────────────────────────────────────────────────────

it('renders the create-team page for a signed-in user and refuses a guest', function () {
    $this->getJson('/teams/create')->assertUnauthorized();

    $user = onboardingUser('solo@example.test');

    $this->actingAs($user)->withHeaders($this->inertia)->get('/teams/create')
        ->assertOk()
        ->assertJsonPath('component', 'account/create-team')
        ->assertJsonPath('props.action', '/teams/create');
});

it('creates a team: the creator is its Owner, it is their current team, and they land on the team page', function () {
    Route::get('account/team', fn () => 'team page')->name('account.team');
    Route::getRoutes()->refreshNameLookups();

    $user = onboardingUser('founder@example.test', 'Fay Founder');

    $this->actingAs($user)->post('/teams/create', ['name' => '  Rocket Club  '])
        ->assertRedirect(route('account.team'));

    $team = Team::query()->where('name', 'Rocket Club')->sole();
    $user->refresh();

    expect($team->personal_team)->toBeFalse()
        ->and((string) $team->user_id)->toBe((string) $user->getKey())
        ->and($team->memberRole($user))->toBe(Role::Owner)
        ->and((string) $user->current_team_id)->toBe((string) $team->getKey())
        ->and($user->currentTeamOrPersonal()->is($team))->toBeTrue();

    // The team-scoped spatie role carries the Owner's permission tokens, so the cascade authorizes
    // the new owner the moment the team exists — not only after a host reseeds.
    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->hasRole(Role::Owner->value))->toBeTrue()
        ->and($user->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_ends_with($name, 'invitation.create')))->not->toBeEmpty();
});

it('falls back to the dashboard when the host mounts no team page', function () {
    Route::get('dashboard', fn () => 'dash')->name('dashboard');
    Route::getRoutes()->refreshNameLookups();

    $user = onboardingUser('fallback@example.test');

    $this->actingAs($user)->post('/teams/create', ['name' => 'Fallback'])
        ->assertRedirect(route('dashboard'));
});

it('answers a JSON caller with the new team as the teams resource projects it', function () {
    $user = onboardingUser('json@example.test');

    $this->actingAs($user)->postJson('/teams/create', ['name' => 'Json Team'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Json Team')
        ->assertJsonPath('data.ownerEmail', 'json@example.test')
        ->assertJsonPath('data.memberCount', 1)
        ->assertJsonPath('data.personal', false);
});

it('refuses a blank or overlong name and a guest, and creates nothing', function () {
    $user = onboardingUser('blank@example.test');

    $this->actingAs($user)->postJson('/teams/create', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->actingAs($user)->postJson('/teams/create', ['name' => str_repeat('x', 256)])->assertStatus(422);

    Auth::logout();
    $this->postJson('/teams/create', ['name' => 'Sneaky'])->assertStatus(401);

    expect(Team::query()->count())->toBe(0);
});

it('lets a user who already owns a team start another, and switches them to it', function () {
    [$owner, $first] = onboardingOwner();

    $this->actingAs($owner)->postJson('/teams/create', ['name' => 'Second'])->assertOk();

    $second = Team::query()->where('name', 'Second')->sole();
    expect((string) $owner->fresh()->current_team_id)->toBe((string) $second->getKey())
        ->and($first->memberRole($owner))->toBe(Role::Owner)
        ->and($second->memberRole($owner))->toBe(Role::Owner);
});

// ── Invitations are emailed ────────────────────────────────────────────────────────────────────

it('emails a sent invitation with a signed link to invitations.accept', function () {
    Notification::fake();
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();

    [$owner, $team] = onboardingOwner();

    $this->actingAs($owner)->postJson('/beam/accounts/invitations', ['email' => 'new@example.test', 'role' => 'admin'])
        ->assertCreated();

    $invitation = Invitation::query()->where('email', 'new@example.test')->sole();

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $mail, array $channels, object $notifiable) use ($invitation) {
        $message = $mail->toMail($notifiable);

        return $notifiable->routes['mail'] === 'new@example.test'
            && $mail->teamName === 'Acme'
            && $mail->inviterName === 'Olive Owner'
            && str_contains($mail->acceptUrl, '/invitations/'.$invitation->token)
            && str_contains($mail->acceptUrl, 'signature=')
            && $message->actionUrl === $mail->acceptUrl
            && str_contains(implode(' ', $message->introLines), 'as Admin');
    });
});

it('re-mails a resend with a fresh token, and the superseded link stops working', function () {
    Notification::fake();
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();

    [$owner, $team] = onboardingOwner();
    $this->actingAs($owner)->postJson('/beam/accounts/invitations', ['email' => 'again@example.test'])->assertCreated();
    $first = Invitation::query()->where('email', 'again@example.test')->sole();
    $oldLink = acceptLink($first);

    $this->actingAs($owner)->postJson("/beam/accounts/invitations/{$first->id}/resend")->assertOk();

    Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
    expect($first->fresh()->token)->not->toBe($first->token);

    Auth::logout();
    $this->withHeaders($this->inertia)->get($oldLink)->assertJsonPath('props.state', 'invalid');
});

it('sends no mail when the host mounts no accept route', function () {
    Notification::fake();
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'nolink@example.test');

    $routes = Route::getRoutes();
    $filtered = new Illuminate\Routing\RouteCollection;
    foreach ($routes as $route) {
        if ($route->getName() !== 'invitations.accept') {
            $filtered->add($route);
        }
    }
    Route::setRoutes($filtered);

    expect(app(Splicewire\Beam\Accounts\Teams\InvitationMailer::class)->send($invitation))->toBeFalse();
    Notification::assertNothingSent();
});

// ── Accepting ──────────────────────────────────────────────────────────────────────────────────

it('shows a guest the invitation and remembers the link as the intended URL', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'guest@example.test', 'admin');
    $link = acceptLink($invitation);

    $this->withHeaders($this->inertia)->get($link)
        ->assertOk()
        ->assertJsonPath('component', 'auth/accept-invitation')
        ->assertJsonPath('props.state', 'guest')
        ->assertJsonPath('props.teamName', 'Acme')
        ->assertJsonPath('props.email', 'guest@example.test')
        ->assertJsonPath('props.role', 'admin')
        ->assertJsonPath('props.inviterName', 'Olive Owner')
        ->assertJsonPath('props.acceptUrl', null)
        ->assertSessionHas('url.intended', $link);
});

it('registers a new invitee, returns them to the link, and seats them with the invited role on the inviting team', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'Invitee@Example.test', 'admin');
    $link = acceptLink($invitation);

    $this->withHeaders($this->inertia)->get($link)->assertOk();

    // Fortify's register response redirects to the INTENDED url — the signed link, unchanged.
    $this->post('/register', [
        'name' => 'Ivy Invitee',
        'email' => 'invitee@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ])->assertRedirect($link);

    $this->withHeaders($this->inertia)->get($link)
        ->assertJsonPath('props.state', 'ready')
        ->assertJsonPath('props.acceptUrl', "/invitations/{$invitation->token}/redeem");

    Route::get('dashboard', fn () => 'dash')->name('dashboard');
    Route::getRoutes()->refreshNameLookups();

    $this->post("/invitations/{$invitation->token}/redeem")->assertRedirect(route('dashboard'));

    $invitee = User::query()->where('email', 'invitee@example.test')->sole();

    expect($team->memberRole($invitee))->toBe(Role::Admin)
        ->and((string) $invitee->current_team_id)->toBe((string) $team->getKey())
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    expect($invitee->fresh()->hasRole(Role::Admin->value))->toBeTrue();
});

it('answers a JSON redeem with the seat taken', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'json-invitee@example.test');
    $invitee = onboardingUser('json-invitee@example.test');

    $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem")
        ->assertOk()
        ->assertJsonPath('data.teamId', (string) $team->getKey())
        ->assertJsonPath('data.teamName', 'Acme')
        ->assertJsonPath('data.role', 'member');
});

it('refuses a signed-in user whose email is not the invited one, and seats nobody', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'intended@example.test');
    $other = onboardingUser('someone-else@example.test');

    $this->actingAs($other)->withHeaders($this->inertia)->get(acceptLink($invitation))
        ->assertJsonPath('props.state', 'wrong-account')
        ->assertJsonPath('props.viewerEmail', 'someone-else@example.test')
        ->assertJsonPath('props.acceptUrl', null);

    $this->actingAs($other)->postJson("/invitations/{$invitation->token}/redeem")->assertForbidden();

    // The Inertia form gets its reason back as a field error rather than a bare 403 modal.
    $this->actingAs($other)->from(acceptLink($invitation))->post("/invitations/{$invitation->token}/redeem")
        ->assertRedirect()
        ->assertSessionHasErrors('invitation');

    expect($team->memberRole($other))->toBeNull()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('refuses a used invitation on the page and on the operation', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'twice@example.test');
    $invitee = onboardingUser('twice@example.test');
    $link = acceptLink($invitation);

    $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem")->assertOk();

    $this->actingAs($invitee)->withHeaders($this->inertia)->get($link)->assertJsonPath('props.state', 'used');
    $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem")->assertStatus(410);
});

it('refuses an expired invitation, whether the link or the invitation has aged out', function () {
    config(['beam.accounts.invitations.expires_after_days' => 3]);

    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'late@example.test');
    $invitee = onboardingUser('late@example.test');
    $link = acceptLink($invitation);

    $this->travel(4)->days();

    $this->actingAs($invitee)->withHeaders($this->inertia)->get($link)->assertJsonPath('props.state', 'expired');
    $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem")->assertStatus(410);

    expect($team->memberRole($invitee))->toBeNull();
});

it('treats a tampered, unsigned or unknown link as invalid and names no team', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'tamper@example.test');
    $link = acceptLink($invitation);

    $this->withHeaders($this->inertia)->get($link.'x')->assertJsonPath('props.state', 'invalid')->assertJsonPath('props.teamName', null);
    $this->withHeaders($this->inertia)->get("/invitations/{$invitation->token}")->assertJsonPath('props.state', 'invalid');
    $this->withHeaders($this->inertia)->get(URL::temporarySignedRoute('invitations.accept', now()->addDay(), ['token' => 'nosuchtoken']))
        ->assertJsonPath('props.state', 'invalid')
        ->assertJsonPath('props.email', null);

    $this->actingAs(onboardingUser('tamper@example.test'))->postJson('/invitations/nosuchtoken/redeem')->assertNotFound();
});

it('refuses a guest at the redeem operation', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'anon@example.test');

    $this->postJson("/invitations/{$invitation->token}/redeem")->assertStatus(401);
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('never demotes an existing member who redeems a lower-role invitation', function () {
    [$owner, $team] = onboardingOwner();
    $invitation = onboardingInvite($team, $owner, 'owner@example.test', 'member');

    $this->actingAs($owner)->postJson("/invitations/{$invitation->token}/redeem")->assertOk();

    expect($team->memberRole($owner))->toBe(Role::Owner);
});

// ── Permissions after joining ─────────────────────────────────────────────────────────────────

it('gives a joined member the member lens: they cannot invite, while the owner and an admin can', function () {
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();
    Notification::fake();

    [$owner, $team] = onboardingOwner();
    $member = onboardingUser('m@example.test');
    $admin = onboardingUser('a@example.test');

    foreach ([[$member, 'member'], [$admin, 'admin']] as [$user, $role]) {
        $invitation = onboardingInvite($team, $owner, $user->email, $role);
        $this->actingAs($user)->postJson("/invitations/{$invitation->token}/redeem")->assertOk();
    }

    $this->actingAs($member->fresh())->postJson('/beam/accounts/invitations', ['email' => 'x@example.test'])->assertForbidden();
    $this->actingAs($admin->fresh())->postJson('/beam/accounts/invitations', ['email' => 'y@example.test'])->assertCreated();
    $this->actingAs($owner->fresh())->postJson('/beam/accounts/invitations', ['email' => 'z@example.test'])->assertCreated();

    expect(Membership::query()->where('team_id', $team->getKey())->count())->toBe(3);
});
