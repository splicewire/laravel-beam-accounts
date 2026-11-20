<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;

/**
 * The names a scoped credential carries for the team-roster decisions made OUTSIDE the Gate (fleet ADR 0006 C2;
 * credential-scope 07 R2): {@see \Splicewire\Beam\Accounts\Http\Controllers\Account\TeamMemberController} and
 * {@see \Splicewire\Beam\Accounts\Teams\TeamMembers} check membership and ownership inline, so each asks the scope:
 *
 *  - reading the roster or its role options: `viewMembers` on the team, `{team}.view-members` /
 *    `{team}.{id}.view-members`;
 *  - changing a role or removing a member (owner-only): `manageMembers` on the team, `{team}.manage-members` /
 *    `{team}.{id}.manage-members`, the names the `manageMembers` Gate ability derives on the same subject.
 *
 * The inline membership and owner checks stay the principal half. A team that is no model derives no name, so a scoped
 * token is refused there (fail closed). The class-level names are offered to the widest token set ({@see offered()}).
 */
class TeamMemberDecisions
{
    public const VIEW = 'viewMembers';

    public const MANAGE = 'manageMembers';

    /** @return list<string> */
    public static function names(string $decision, mixed $team): array
    {
        return app(CredentialScopeGate::class)->derivedNames($decision, $team);
    }

    public static function authorize(mixed $user, string $decision, mixed $team): void
    {
        app(CredentialScopeGate::class)->authorizeCarried($user, $decision, self::names($decision, $team));
    }

    /** @return list<string> the class-level names on the configured team model */
    public static function offered(): array
    {
        $team = BeamAccounts::teamModel();

        if (! is_string($team) || ! is_subclass_of($team, Model::class)) {
            return [];
        }

        return [PermissionNamer::assemble($team, 'view-members'), PermissionNamer::assemble($team, 'manage-members')];
    }
}
