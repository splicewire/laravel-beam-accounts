<?php

use Splicewire\Beam\Accounts\Data\Pages\AuthEntryPageData;
use Splicewire\Beam\Accounts\Data\Pages\DemoAccountLinkData;
use Splicewire\Beam\Accounts\Data\Pages\PageEntryArtifactData;
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

it('carries the entry format and artifact address a hand-written page needs to be READ and edited safely', function () {
    // G2-BEAM-AUTHOR-ENTRY, measured on beam.test 2026-09-11. `{id, slug}` alone addresses a SAVE and
    // nothing else: the owner authored `/`, Save reported "Saved" truthfully, and no reader ever saw the
    // change — the page rendered its packaged default tree because the props named no compiled body.
    // The severe half of the same row was the missing `format`: the dock opened the JsonDoc canvas on
    // an mdx entry and one Save blanked the public page.
    $page = AuthEntryPageData::from([
        'slug' => 'login',
        'entry' => [
            'id' => 'entry-1',
            'slug' => 'login',
            'format' => 'tsx',
            'artifact' => ['url' => '/beam/ux/artifacts/entry-1/abc123', 'version' => 'abc123'],
        ],
        'body' => [],
    ]);

    expect($page->entry->format)->toBe('tsx')
        ->and($page->entry->artifact)->toBeInstanceOf(PageEntryArtifactData::class)
        ->and($page->entry->artifact->url)->toBe('/beam/ux/artifacts/entry-1/abc123')
        ->and($page->entry->artifact->version)->toBe('abc123');
});

it('leaves format and artifact NULL for a host that says neither, rather than inventing a default', function () {
    // Null is "the host did not say". A client reads it as unknown — never as "any format, go ahead" —
    // because a page that does not declare its body language must not be offered an editor that could
    // destroy it, and a page with no artifact has never been authored rather than failed to compile.
    $entry = PageEntryData::from(['id' => 'entry-1', 'slug' => 'home']);

    expect($entry->format)->toBeNull()->and($entry->artifact)->toBeNull();
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
