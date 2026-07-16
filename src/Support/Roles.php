<?php

namespace Schemastud\Beam\Accounts\Support;

use Schemastud\Beam\Accounts\Enums\Role;

/**
 * @deprecated Use the backed enum {@see Role} — the
 * single source of truth for team roles. This const class is a thin shim that
 * delegates to the enum so any consumer minted against the FC-09 extraction keeps
 * working; new code must reference the enum directly. It hand-authors nothing: the
 * consts and `all()` read the enum's cases.
 */
class Roles
{
    public const OWNER = Role::Owner->value;

    public const ADMIN = Role::Admin->value;

    public const MEMBER = Role::Member->value;

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return Role::values();
    }
}
