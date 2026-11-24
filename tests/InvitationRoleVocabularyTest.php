<?php

use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Splicewire\Beam\Accounts\Data\CreateInvitationData;
use Splicewire\Beam\Accounts\Enums\Role;

// attention-surfaces 36: the invite role validates against Role's invitable cases, not a literal list.

it('still accepts every role the literal list accepted', function (array $input, string $role) {
    expect(CreateInvitationData::validateAndCreate(['email' => 'new@example.test', ...$input])->role)->toBe($role);
})->with([
    'admin' => [['role' => 'admin'], 'admin'],
    'member' => [['role' => 'member'], 'member'],
    'omitted (default)' => [[], 'member'],
]);

it('refuses the excluded owner case and a value outside the enum', function (string $role) {
    expect(fn () => CreateInvitationData::validateAndCreate(['email' => 'new@example.test', 'role' => $role]))
        ->toThrow(ValidationException::class);
})->with(['owner (excluded case)' => 'owner', 'outside the enum' => 'service']);

it('reads the invitable set from the enum, so the validation set and Role::invitable() are one declaration', function () {
    expect(Role::invitable())->toBe(Role::INVITABLE)
        ->and(Role::invitableValues())->toBe(['admin', 'member']);
});

it('offers the same options in the schema-form', function () {
    // The schema-form's In projection (data-schemas ValidationAttributeStrategy::applyInConstraint) parses
    // this rule string; the literal list produced exactly `in:"admin","member"`.
    $in = (new ReflectionProperty(CreateInvitationData::class, 'role'))->getAttributes(In::class)[0]->newInstance();

    expect((string) $in->getRule(ValidationPath::create()))->toBe('in:"admin","member"');
});
