<?php

use Schemastud\Beam\Accounts\Enums\Role;
use Schemastud\Beam\Accounts\Support\Roles;

it('is the single source of the role vocabulary', function () {
    expect(Role::values())->toBe(['owner', 'admin', 'member']);
});

it('excludes owner from the invitable context (invite-excludes-owner as a constraint over the one enum)', function () {
    expect(Role::invitable())->toBe([Role::Admin, Role::Member])
        ->and(Role::invitableValues())->toBe(['admin', 'member'])
        ->and(Role::invitable())->not->toContain(Role::Owner);
});

it('treats every case as assignable (ownership transfer included)', function () {
    expect(Role::assignable())->toBe(Role::cases());
});

it('derives its schema projection from the cases, hand-authoring nothing', function () {
    expect(Role::schema())->toBe([
        'enum' => ['owner', 'admin', 'member'],
        'options' => [
            ['value' => 'owner', 'label' => 'Owner'],
            ['value' => 'admin', 'label' => 'Admin'],
            ['value' => 'member', 'label' => 'Member'],
        ],
    ]);
});

it('scopes options to a context subset without a second list', function () {
    expect(Role::options(Role::invitable()))->toBe([
        ['value' => 'admin', 'label' => 'Admin'],
        ['value' => 'member', 'label' => 'Member'],
    ]);
});

it('keeps the deprecated Support\\Roles shim delegating to the enum', function () {
    expect(Roles::all())->toBe(Role::values())
        ->and(Roles::OWNER)->toBe(Role::Owner->value)
        ->and(Roles::ADMIN)->toBe(Role::Admin->value)
        ->and(Roles::MEMBER)->toBe(Role::Member->value);
});
