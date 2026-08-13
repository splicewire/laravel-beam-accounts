<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * The role vocabulary payload the team UI's selects render from — both context subsets of the
 * {@see Role} enum in one read: `assignable` (every role a member can be set to, ownership
 * transfer included) and `invitable` (the invite-context subset, owner excluded). Served by the
 * tenancy member controller's `roles` verb inside the standard `{data: …}` envelope; typed here
 * so the wire derives from the one enum, with no hand-authored TS list to drift (FC-11/FC-12).
 */
#[TypeScript]
class RoleOptionsData extends Data
{
    /**
     * @param  RoleOptionData[]  $assignable
     * @param  RoleOptionData[]  $invitable
     */
    public function __construct(
        /** @var RoleOptionData[] */
        public array $assignable,
        /** @var RoleOptionData[] */
        public array $invitable,
    ) {}

    /** The live vocabulary, derived from the enum's own context subsets. */
    public static function current(): self
    {
        return new self(
            assignable: array_map(fn (array $option) => RoleOptionData::from($option), Role::options(Role::assignable())),
            invitable: array_map(fn (array $option) => RoleOptionData::from($option), Role::options(Role::invitable())),
        );
    }
}
