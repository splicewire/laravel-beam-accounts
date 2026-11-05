<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountData;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Splicewire\Beam\Accounts\Data\MetricData;
use Splicewire\Beam\Accounts\Data\PlanData;
use Splicewire\Beam\Accounts\Data\ProfileData;
use Splicewire\Beam\Accounts\Data\UpsellData;
use Splicewire\Beam\Accounts\Support\IdentityAccountShellProvider;
use Splicewire\Beam\Accounts\Support\NullAccountShellProvider;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

it('projects the account-shell shape to camelCase arrays', function () {
    $shell = new AccountShellData(
        plan: new PlanData(tier: 'free', label: 'Free', credits: 8, max: 8),
        profile: new ProfileData(
            handle: '@drew',
            avatar: 'DM',
            metrics: [new MetricData(label: 'SONGS', value: '4')],
        ),
        account: new AccountData(email: 'drew@example.test', paymentMethodLabel: 'Visa •••• 0042'),
        upsells: [new UpsellData(key: 'own-song', label: 'Own a song', href: '/own')],
    );

    expect($shell->toArray())->toBe([
        'plan' => ['tier' => 'free', 'label' => 'Free', 'credits' => 8, 'max' => 8],
        'profile' => [
            'handle' => '@drew',
            'avatar' => 'DM',
            'metrics' => [['label' => 'SONGS', 'value' => '4']],
        ],
        'account' => ['email' => 'drew@example.test', 'paymentMethodLabel' => 'Visa •••• 0042'],
        'upsells' => [['key' => 'own-song', 'label' => 'Own a song', 'href' => '/own']],
    ]);
});

it('carries a plan with no usage meter as nulls', function () {
    $plan = new PlanData(tier: 'songwriter', label: 'Songwriter');

    expect($plan->toArray())->toBe(['tier' => 'songwriter', 'label' => 'Songwriter', 'credits' => null, 'max' => null]);
});

it('projects a shell with no plan as a null plan', function () {
    $shell = new AccountShellData(
        plan: null,
        profile: new ProfileData(handle: '@drew', avatar: 'DM'),
        account: new AccountData(email: 'drew@example.test'),
    );

    expect($shell->toArray())->toBe([
        'plan' => null,
        'profile' => ['handle' => '@drew', 'avatar' => 'DM', 'metrics' => []],
        'account' => ['email' => 'drew@example.test', 'paymentMethodLabel' => null],
        'upsells' => [],
    ]);
});

it('binds the identity provider by default: the user record only, no plan, metrics or upsells', function () {
    $provider = app(AccountShellProvider::class);

    expect($provider)->toBeInstanceOf(IdentityAccountShellProvider::class);
    expect($provider->shellFor(null))->toBeNull();

    $shell = $provider->shellFor(new User(['email' => 'drew.m@example.test', 'name' => 'Drew  Morgan Lee']));

    expect($shell->toArray())->toBe([
        'plan' => null,
        'profile' => ['handle' => '@drew.m', 'avatar' => 'DM', 'metrics' => []],
        'account' => ['email' => 'drew.m@example.test', 'paymentMethodLabel' => null],
        'upsells' => [],
    ]);
});

it('falls back to the email for initials and to @you for a user with neither name nor email', function () {
    $provider = app(AccountShellProvider::class);

    expect($provider->shellFor(new User(['email' => 'x@example.test']))->profile->avatar)->toBe('X');

    $blank = $provider->shellFor(new User([]));
    expect($blank->profile->handle)->toBe('@you')
        ->and($blank->profile->avatar)->toBe('·')
        ->and($blank->account->email)->toBe('');
});

it('keeps the null provider available for a host that wants the shell empty', function () {
    app()->bind(AccountShellProvider::class, NullAccountShellProvider::class);

    expect(app(AccountShellProvider::class)->shellFor(new User(['email' => 'x@example.test'])))->toBeNull();
});

it('lets a host override the provider to populate the shape', function () {
    app()->bind(AccountShellProvider::class, fn () => new class implements AccountShellProvider
    {
        public function shellFor(?Authenticatable $user): ?AccountShellData
        {
            if ($user === null) {
                return null;
            }

            return new AccountShellData(
                plan: new PlanData(tier: 'free', label: 'Free', credits: 3, max: 8),
                profile: new ProfileData(handle: '@'.($user->email ?? 'anon')),
                account: new AccountData(email: $user->email ?? 'anon@example.test'),
                upsells: [new UpsellData(key: 'go-pro', label: 'Go Songwriter')],
            );
        }
    });

    $shell = app(AccountShellProvider::class)->shellFor(new User(['email' => 'host@example.test']));

    expect($shell)->toBeInstanceOf(AccountShellData::class);
    expect($shell->plan->credits)->toBe(3);
    expect($shell->profile->handle)->toBe('@host@example.test');
    expect($shell->account->email)->toBe('host@example.test');
    expect($shell->account->paymentMethodLabel)->toBeNull();
    expect($shell->upsells[0]->key)->toBe('go-pro');
});
