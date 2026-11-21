<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Authorization\DecisionNames;

/**
 * The decisions of the token-management surface (the Tokens page REST verbs and "log out everywhere else"), each with a
 * registered `personal-access-token.*` name (fleet ADR 0006 rule 1; credential-scope 08, l145 review F3).
 *
 * These routes answer for the principal through the owner scope alone, so before 08 a scoped token passed them with no
 * decision at all: a paired connected-site token renewed a separate `*` token from a 1-day expiry to never-expiring.
 * Each verb now asks its name of the acting credential through the cascade's {@see CredentialScopeGate::authorizeCarried()}
 * (a check outside the Gate, narrowed in place); a session, a `*` login token and a guest are unchanged. The owner scope
 * stays the principal half.
 *
 * A carried name only admits the verb. What a renew, rotate or revocation may reach is the acting token's own authority
 * ({@see MachineTokenIssuer::renew()}, {@see MachineTokenIssuer::replace()}, {@see MachineTokenIssuer::authorizeRevocation()}).
 *
 * The names are registered in the cascade's {@see DecisionNames} at boot, so the widest set a token can be scoped to
 * offers them and a host may rename one (`app(DecisionNames::class)->name(TokenManagement::RENEW, …)`). A verb that
 * acts on one token also admits that token's per-record name (`personal-access-token.{id}.update`), as the cascade
 * derives per-record names for a policy verb.
 */
final class TokenManagement
{
    public const INDEX = 'tokens.index';

    public const PERMISSIONS = 'tokens.permissions';

    public const STORE = 'tokens.store';

    public const RENEW = 'tokens.renew';

    public const ROTATE = 'tokens.rotate';

    public const ARCHIVE = 'tokens.archive';

    public const DESTROY = 'tokens.destroy';

    public const SIGN_OUT_OTHERS = 'tokens.sessions.others';

    /** The name segment of the token model, as `TokenPolicy`'s verbs are named (`personal-access-token.*`). */
    public const SUBJECT = 'personal-access-token';

    /**
     * Decision => the verb whose `personal-access-token.{verb}` name admits it. Listing and the scoped-create picker are
     * reads (`view`, `create`); renew and rotate change a token (`update`); archive, permanent delete and signing out the
     * other sessions revoke tokens (`delete`). Signing out everywhere else needs no name beyond `delete`: a token carrying
     * it can already archive each of those session tokens one by one, and the revocation rule refuses both alike.
     */
    public const VERBS = [
        self::INDEX => 'view',
        self::PERMISSIONS => 'create',
        self::STORE => 'create',
        self::RENEW => 'update',
        self::ROTATE => 'update',
        self::ARCHIVE => 'delete',
        self::DESTROY => 'delete',
        self::SIGN_OUT_OTHERS => 'delete',
    ];

    /** Register every decision's class-level name, so the widest token scope offers it. Called from the provider's boot. */
    public static function register(DecisionNames $names): void
    {
        foreach (self::VERBS as $decision => $verb) {
            $names->name($decision, self::SUBJECT.'.'.$verb);
        }
    }

    /**
     * Refuse a scoped acting credential that carries none of `$decision`'s names, with the cascade's coded 403
     * (`credential_scope_name_not_carried`; `credential_scope_unnamed_decision` if a host registered it to nothing).
     * `$token` adds that token's per-record name. No-op for a session, a `*` token, a guest or another principal.
     */
    public static function authorize(mixed $user, string $decision, ?Model $token = null): void
    {
        $names = app(DecisionNames::class)->for($decision, [])
            ?? (isset(self::VERBS[$decision]) ? [self::SUBJECT.'.'.self::VERBS[$decision]] : []);

        if ($token !== null && $token->exists && isset(self::VERBS[$decision])) {
            $names[] = self::SUBJECT.'.'.$token->getKey().'.'.self::VERBS[$decision];
        }

        app(CredentialScopeGate::class)->authorizeCarried($user, $decision, $names);
    }
}
