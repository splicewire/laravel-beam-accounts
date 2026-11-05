<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountData;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Splicewire\Beam\Accounts\Data\PlanData;
use Splicewire\Beam\Accounts\Data\ProfileData;
use Splicewire\Beam\Accounts\Http\Concerns\SharesAccountShell;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * make-surface 01: the `accountShell` share every starter hand-copied, packaged. The trait reads the host-bound
 * provider for a signed-in user, answers null for a guest, and degrades to null when the provider throws.
 */
function accountShellSharer(): object
{
    return new class
    {
        use SharesAccountShell;

        public function shared(Request $request): ?AccountShellData
        {
            return $this->accountShell($request);
        }
    };
}

function requestFor(?Authenticatable $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('shares null for a guest and never asks the provider', function () {
    app()->bind(AccountShellProvider::class, fn () => throw new RuntimeException('a guest must not reach the provider'));

    expect(accountShellSharer()->shared(requestFor(null)))->toBeNull();
});

it('shares what the host-bound provider returns for a signed-in user', function () {
    $user = User::create(['name' => 'Drew', 'email' => 'drew@example.test', 'password' => 'secret']);
    app()->bind(AccountShellProvider::class, fn () => new class implements AccountShellProvider
    {
        public function shellFor(?Authenticatable $user): ?AccountShellData
        {
            return new AccountShellData(
                plan: new PlanData(tier: 'pro', label: 'Pro'),
                profile: new ProfileData(handle: '@'.$user->getAttribute('name'), avatar: 'D', metrics: []),
                account: new AccountData(email: (string) $user->getAttribute('email'), paymentMethodLabel: null),
            );
        }
    });

    $shell = accountShellSharer()->shared(requestFor($user));

    expect($shell)->toBeInstanceOf(AccountShellData::class)
        ->and($shell->plan->tier)->toBe('pro')
        ->and($shell->profile->handle)->toBe('@Drew')
        ->and($shell->account->email)->toBe('drew@example.test');
});

it('shares the null default when the host binds no provider', function () {
    $user = User::create(['name' => 'Drew', 'email' => 'drew@example.test', 'password' => 'secret']);

    expect(accountShellSharer()->shared(requestFor($user)))->toBeNull();
});

it('degrades to null when the provider throws', function () {
    $user = User::create(['name' => 'Drew', 'email' => 'drew@example.test', 'password' => 'secret']);
    app()->bind(AccountShellProvider::class, fn () => new class implements AccountShellProvider
    {
        public function shellFor(?Authenticatable $user): ?AccountShellData
        {
            throw new RuntimeException('host product data unavailable');
        }
    });

    expect(accountShellSharer()->shared(requestFor($user)))->toBeNull();
});
