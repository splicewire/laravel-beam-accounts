<?php

namespace Schemastud\Beam\Accounts\Support;

/**
 * The team roles the account runtime understands. Owner is provisioned on
 * registration (team-of-one); admin/member come into play with multi-member
 * teams (issue 04).
 */
class Roles
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MEMBER = 'member';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::OWNER, self::ADMIN, self::MEMBER];
    }
}
