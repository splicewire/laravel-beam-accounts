<?php

use function Splicewire\Beam\Accounts\accountGuard;

use Splicewire\Beam\Accounts\Concerns\ProfileValidationRules;

it('runs on the web/session guard only', function () {
    expect(accountGuard())->toBe('web');
    expect(config('fortify.guard'))->toBe('web');
});

it('exposes profile validation rules bound to the configured user model', function () {
    $rules = new class
    {
        use ProfileValidationRules;

        public function expose(): array
        {
            return $this->profileRules();
        }
    };

    $profileRules = $rules->expose();

    expect($profileRules)->toHaveKeys(['name', 'email']);
    expect($profileRules['name'])->toContain('required', 'string', 'max:255');
    expect($profileRules['email'])->toContain('email');
});

it('registers the settings surface routes', function () {
    expect(Route::has('profile.edit'))->toBeTrue();
    expect(Route::has('profile.update'))->toBeTrue();
    expect(Route::has('profile.destroy'))->toBeTrue();
    expect(Route::has('security.edit'))->toBeTrue();
    expect(Route::has('user-password.update'))->toBeTrue();
});
