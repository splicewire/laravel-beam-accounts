<?php

use Splicewire\Beam\Accounts\Data\Pages\AuthEntryPageData;
use Splicewire\Beam\Accounts\Data\Pages\DemoAccountLinkData;
use Splicewire\Beam\Accounts\Data\Pages\PageEntryData;
use Splicewire\Beam\Accounts\Data\Pages\ResetPasswordPageData;
use Splicewire\Beam\Accounts\Data\Pages\SecurityPageData;
use Splicewire\Beam\Accounts\Data\Pages\SecurityPasskeyData;

it('hydrates authorable auth pages without a host Data namespace', function () {
    $page = AuthEntryPageData::from([
        'slug' => 'login',
        'entry' => ['id' => 'entry-1', 'slug' => 'login'],
        'body' => [['type' => 'LoginForm']],
        'demoAccounts' => [['key' => 'member', 'label' => 'Member', 'url' => '/demo/member']],
    ]);

    expect($page->entry)->toBeInstanceOf(PageEntryData::class)
        ->and($page->demoAccounts[0])->toBeInstanceOf(DemoAccountLinkData::class)
        ->and($page->toArray())->not->toHaveKey('canResetPassword')
        ->and($page->toArray()['body'])->toBe([['type' => 'LoginForm']]);
});

it('preserves feature-cut security payloads without requiring deleted capabilities', function () {
    $props = ['canManageTwoFactor' => false, 'canManagePasskeys' => false, 'passkeys' => [], 'passwordRules' => 'minlength: 12;'];
    $page = SecurityPageData::from($props);

    expect($page->toArray())->toBe($props);
});

it('hydrates enabled passkeys and keeps credential material out of the page shape', function () {
    $page = SecurityPageData::from([
        'canManageTwoFactor' => true,
        'canManagePasskeys' => true,
        'passwordRules' => 'minlength: 12;',
        'twoFactorEnabled' => false,
        'passkeys' => [[
            'id' => 3, 'name' => 'Laptop', 'authenticator' => null,
            'created_at_diff' => 'a minute ago', 'last_used_at_diff' => null,
            'credential' => 'must-not-leak',
        ]],
    ]);

    expect($page->passkeys[0])->toBeInstanceOf(SecurityPasskeyData::class)
        ->and($page->toArray()['passkeys'][0])->not->toHaveKey('credential')
        ->and($page->toArray()['twoFactorEnabled'])->toBeFalse();
});

it('preserves non-scalar reset input until the submitted form validates it', function () {
    $page = ResetPasswordPageData::from([
        'slug' => 'reset-password', 'entry' => null, 'body' => null,
        'email' => ['unexpected' => 'value'], 'token' => 'token', 'passwordRules' => '',
    ]);

    expect($page->toArray()['email'])->toBe(['unexpected' => 'value']);
});
