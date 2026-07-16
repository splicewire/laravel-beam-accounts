<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Schemastud\Beam\Accounts\Tests\Fixtures\User;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->user = User::create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'email_verified_at' => now(),
        'password' => 'password-1234',
    ]);
});

it('updates the profile and clears email verification when the email changes', function () {
    $this->actingAs($this->user)
        ->patch('/settings/profile', [
            'name' => 'Ada L.',
            'email' => 'ada.new@example.test',
        ])
        ->assertRedirect(route('profile.edit'));

    $this->user->refresh();
    expect($this->user->name)->toBe('Ada L.');
    expect($this->user->email)->toBe('ada.new@example.test');
    expect($this->user->email_verified_at)->toBeNull();
});

it('keeps email verification when the email is unchanged', function () {
    $this->actingAs($this->user)
        ->patch('/settings/profile', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
        ])
        ->assertRedirect();

    $this->user->refresh();
    expect($this->user->email_verified_at)->not->toBeNull();
});

it('updates the password from the security surface', function () {
    $this->actingAs($this->user)
        ->put('/settings/password', [
            'current_password' => 'password-1234',
            'password' => 'new-password-5678',
            'password_confirmation' => 'new-password-5678',
        ])
        ->assertRedirect();

    expect(Hash::check('new-password-5678', $this->user->fresh()->password))->toBeTrue();
});

it('deletes the profile with the correct current password', function () {
    $this->actingAs($this->user)
        ->delete('/settings/profile', [
            'password' => 'password-1234',
        ])
        ->assertRedirect('/');

    expect(User::find($this->user->id))->toBeNull();
});
