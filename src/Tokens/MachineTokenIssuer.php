<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Keys\TokenLastFour;
use Splicewire\Beam\Accounts\Localization\AccountError;
use Splicewire\Beam\Localization\CodedValidationException;

/**
 * The one machine-token issuer (fleet ADR 0006, credential-scope 03).
 *
 * Every token row other than a login token is written here, as a declared {@see TokenKind} with an explicit ability
 * set. {@see \Splicewire\Beam\Accounts\Auth\AuthTokenFactory} stays the only path to a `*` token, because only a
 * login token stands in for a signed-in session. The issuer refuses:
 *
 *  - any issuance while `beam.accounts.api.scope_enforcement` is off (C5): with enforcement off a scoped token is not
 *    enforced, so minting one would hand out a credential nothing narrows;
 *  - an undeclared kind, and `*` from any kind (C7);
 *  - for a same-principal kind under a scoped acting credential, any ability that credential lacks (C6), and an expiry
 *    later than that credential's own, or none when it has one (credential-scope 08: a mint never outlives its minter);
 *  - for a delegated kind, a scoped acting credential without the kind's issuance ability, a request naming a
 *    principal or subject other than the one the kind resolves (or any subject when the kind resolves none), a
 *    target that is the actor itself, and an actor without live authority over the resolved principal (C6).
 *
 * Under a scoped acting credential the token-management verbs that change or remove an existing row are held to the same
 * authority (credential-scope 08, l145 review F3): {@see renew()}, {@see replace()} (rotate) and
 * {@see authorizeRevocation()} (archive, delete, log out other sessions) refuse a row of another principal, a `*` row and
 * a row carrying anything the acting credential lacks (`token_beyond_acting_token`), and renew and rotate refuse an expiry
 * past the acting credential's own (`token_expiry_beyond_acting_token`). A session, a `*` login token and a console call
 * are unchanged: rule 2 binds a call authenticated with a scoped token.
 *
 * Every refusal is coded ({@see AccountError}); nothing is trimmed. The row is written through
 * {@see BeamAccounts::tokenModel()}, the model the Tokens page lists, with the kind recorded in its `kind` column.
 *
 * The acting credential's scope is the permission cascade's {@see CredentialScopeResolver}: beam-accounts binds the
 * bearer-token resolver there whenever enforcement is on, and the issuer only issues when it is.
 */
class MachineTokenIssuer
{
    public const KIND_COLUMN = 'kind';

    public function __construct(private TokenKindRegistry $kinds) {}

    public function issue(string $kind, TokenIssuance $request): IssuedToken
    {
        AccountError::TokenIssuerEnforcementOff->abortUnless((bool) config('beam.accounts.api.scope_enforcement', false), 403, ['kind' => $kind]);

        $declared = $this->kinds->kind($kind);

        AccountError::TokenKindUndeclared->abortIf($declared === null, 422, ['kind' => $kind]);

        return $this->issueDeclared($kind, $declared, $this->canonical($request));
    }

    /**
     * Refuse, before the caller changes anything, an issuance of `$kind` that would be refused for its credential alone:
     * enforcement off, an undeclared kind, or (for a delegated kind) a scoped acting credential without the kind's
     * issuance ability. A caller whose issuance follows writes of its own (a broker grant, an entitlement) calls this
     * first, so a refusal leaves its state intact (codex cs04 #4). The target and authority checks still run at issue.
     */
    public function authorizeIssuance(string $kind): void
    {
        AccountError::TokenIssuerEnforcementOff->abortUnless((bool) config('beam.accounts.api.scope_enforcement', false), 403, ['kind' => $kind]);

        $declared = $this->kinds->kind($kind);

        AccountError::TokenKindUndeclared->abortIf($declared === null, 422, ['kind' => $kind]);

        if ($declared instanceof DelegatedTokenKind) {
            $scope = app(CredentialScopeResolver::class)->resolve();

            AccountError::TokenIssuanceAbilityMissing->abortIf(
                $scope !== null && ! in_array($declared->issuanceAbility(), $scope, true),
                403,
                ['kind' => $kind, 'ability' => $declared->issuanceAbility()],
            );
        }
    }

    /**
     * Issue an {@see OperationTokenKind} bound to `$operation`, the object the calling code is performing (the grant,
     * the child tenant). A kind of any other shape is refused (`token_kind_not_operation_bound`): its subject comes
     * from its own context, and an operation must never stand in for it. Every rule {@see issue()} applies still does.
     */
    public function issueFor(string $kind, mixed $operation, TokenIssuance $request): IssuedToken
    {
        AccountError::TokenIssuerEnforcementOff->abortUnless((bool) config('beam.accounts.api.scope_enforcement', false), 403, ['kind' => $kind]);

        $declared = $this->kinds->kind($kind);

        AccountError::TokenKindUndeclared->abortIf($declared === null, 422, ['kind' => $kind]);
        AccountError::TokenKindNotOperationBound->abortUnless($declared instanceof OperationTokenKind, 422, ['kind' => $kind]);

        /** @var OperationTokenKind $declared */
        return $this->issueDeclared($kind, $declared->forOperation($operation), $this->canonical($request));
    }

    /**
     * The request with its actor as the CREDENTIAL principal (codex cs04 #1). A tenanted host authenticates a central
     * `User` and then swaps in its in-schema mirror (tower `ResolveTenantUser` → a `TenantUser` sharing the user's key),
     * so the same identity reaches the issuer in two model shapes. Every rule that compares the actor with a principal
     * — the same-principal cap's owner, the delegated kind's target-is-actor refusal, a kind's authority check — must see
     * one shape, so an actor that is not the host's user model (`auth.providers.users.model`) but whose key names a
     * user of it is replaced by that user. Anything else (a console's null actor, a non-user tokenable) is unchanged.
     */
    private function canonical(TokenIssuance $request): TokenIssuance
    {
        $actor = $request->actor;
        $model = config('auth.providers.users.model');

        if (! $actor instanceof Model || ! is_string($model) || ! is_subclass_of($model, Model::class) || $actor instanceof $model) {
            return $request;
        }

        $central = $model::query()->find($actor->getAuthIdentifier());

        if (! $central instanceof Authenticatable) {
            return $request;
        }

        return new TokenIssuance(
            actor: $central,
            name: $request->name,
            abilities: $request->abilities,
            expiresAt: $request->expiresAt,
            subject: $request->subject,
            principal: $request->principal,
            deterministicId: $request->deterministicId,
            deterministicPlaintext: $request->deterministicPlaintext,
        );
    }

    private function issueDeclared(string $kind, TokenKind $declared, TokenIssuance $request): IssuedToken
    {
        $scope = app(CredentialScopeResolver::class)->resolve();

        [$principal, $abilities] = $declared instanceof DelegatedTokenKind
            ? $this->delegated($kind, $declared, $request, $scope)
            : $this->samePrincipal($kind, $declared, $request, $scope);

        return $this->store($kind, $declared, $principal, $abilities, $request);
    }

    /**
     * Replace `$old` with a new token of the same kind, name and abilities, under the same rules as {@see issue()}.
     * The caller archives the old row. Only a same-principal kind rotates, and only by its own principal; a
     * delegated kind is issued again, since its set is the kind's and not the old row's.
     */
    public function rotate(Model $old, TokenIssuance $request): IssuedToken
    {
        $kind = (string) ($old->getAttribute(self::KIND_COLUMN) ?? '');
        $declared = $this->kinds->kind($kind);

        AccountError::TokenKindUndeclared->abortIf($declared === null, 422, ['kind' => $kind === '' ? '(none)' : $kind]);
        AccountError::TokenKindNotRotatable->abortIf($declared instanceof DelegatedTokenKind, 422, ['kind' => $kind]);

        $request = $this->canonical($request);
        $actor = $request->actor;
        $owned = $actor instanceof Model
            && (string) $old->getAttribute('tokenable_type') === $actor->getMorphClass()
            && (string) $old->getAttribute('tokenable_id') === (string) $actor->getKey();

        AccountError::TokenTargetMismatch->abortUnless($owned, 403, ['kind' => $kind]);

        $abilities = $old->getAttribute('abilities');

        return $this->issue($kind, new TokenIssuance(
            actor: $actor,
            name: $request->name !== '' ? $request->name : (string) $old->getAttribute('name'),
            abilities: is_array($abilities) ? array_values($abilities) : null,
            expiresAt: $request->expiresAt,
        ));
    }

    /**
     * Rotate `$old` for the token-management surface: a row of a declared kind through {@see rotate()}, a row minted
     * before kinds were recorded re-issued as a personal access token with its own abilities. Under a scoped acting
     * credential the row must be the actor's own and carry nothing the credential lacks, so a `*` row (which the
     * personal kind would turn into the widest set) is refused before anything is issued; the issuance itself then
     * caps the new row's abilities and expiry. The caller archives the old row only after this returns.
     */
    public function replace(Model $old, TokenIssuance $request): IssuedToken
    {
        $request = $this->canonical($request);
        $scope = app(CredentialScopeResolver::class)->resolve();

        if ($scope !== null) {
            $this->refuseBeyondActingToken($old, $request->actor, $scope);
        }

        if (filled($old->getAttribute(self::KIND_COLUMN))) {
            return $this->rotate($old, $request);
        }

        $abilities = $old->getAttribute('abilities');

        return $this->issue(Kinds\PersonalAccessTokenKind::NAME, new TokenIssuance(
            actor: $request->actor,
            name: $request->name !== '' ? $request->name : (string) $old->getAttribute('name'),
            abilities: is_array($abilities) ? array_values($abilities) : null,
            expiresAt: $request->expiresAt,
        ));
    }

    /**
     * Renew `$token` in place to `$request->expiresAt` (null: never). Not an issuance, so it runs with enforcement off as
     * before for a session or a `*` token. Under a scoped acting credential the token must be the actor's own and carry
     * nothing the credential lacks, and the new expiry may not be later than the credential's own: a scoped token can
     * never lengthen the life of a token wider than itself, or of any token past its own (l145 review P7b). A refusal
     * writes nothing.
     */
    public function renew(Model $token, TokenIssuance $request): Model
    {
        $request = $this->canonical($request);
        $scope = app(CredentialScopeResolver::class)->resolve();

        if ($scope !== null) {
            $this->refuseBeyondActingToken($token, $request->actor, $scope);
            $this->refuseExpiryBeyondActingToken($request->expiresAt);
        }

        $token->forceFill(['expires_at' => $request->expiresAt])->save();

        return $token;
    }

    /**
     * Refuse, before anything is revoked, a scoped acting credential revoking (archiving, deleting, signing out) any of
     * `$tokens` that is another principal's, a `*` row, or carries anything the credential lacks. A session or a `*`
     * token is unchanged. Signing out the other sessions under a scope is therefore refused whenever one exists: a
     * session token is a `*` login token.
     *
     * @param  iterable<Model>  $tokens
     */
    public function authorizeRevocation(iterable $tokens, ?Authenticatable $actor): void
    {
        $scope = app(CredentialScopeResolver::class)->resolve();

        if ($scope === null) {
            return;
        }

        $actor = $this->canonical(new TokenIssuance(actor: $actor, name: ''))->actor;

        foreach ($tokens as $token) {
            $this->refuseBeyondActingToken($token, $actor, $scope);
        }
    }

    /**
     * A scoped acting credential manages only a row of its own principal whose abilities it carries: never another
     * principal's, never a `*` or null (unscoped) row, never one carrying a name it lacks.
     *
     * @param  list<string>  $scope
     */
    private function refuseBeyondActingToken(Model $token, ?Authenticatable $actor, array $scope): void
    {
        $owned = $actor instanceof Model
            && (string) $token->getAttribute('tokenable_type') === $actor->getMorphClass()
            && (string) $token->getAttribute('tokenable_id') === (string) $actor->getKey();
        $abilities = $token->getAttribute('abilities');
        $beyond = is_array($abilities) && ! in_array('*', $abilities, true)
            ? array_values(array_diff($abilities, $scope))
            : ['*'];

        AccountError::TokenBeyondActingToken->abortIf(
            ! $owned || $beyond !== [],
            403,
            ['abilities' => $owned ? implode(', ', $beyond) : 'another principal\'s token'],
        );
    }

    /**
     * Refuse an expiry later than the acting credential's own (null = never is later than any expiry). An acting
     * credential that never expires caps nothing. Only called under a scoped acting credential.
     */
    private function refuseExpiryBeyondActingToken(?CarbonInterface $expiresAt): void
    {
        $ceiling = $this->actingTokenExpiry();

        if ($ceiling === null || ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($ceiling))) {
            return;
        }

        throw CodedValidationException::for('expiresInDays', AccountError::TokenExpiryBeyondActingToken, ['expires' => $ceiling->toIso8601String()]);
    }

    /**
     * When the request's bearer token stops authenticating: its `expires_at`, or Sanctum's global `sanctum.expiration`
     * minutes after it was created, whichever is sooner; null when neither applies or the request carries no known
     * token. Read from the same row the bearer scope resolver reads ({@see \Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver}).
     */
    private function actingTokenExpiry(): ?CarbonInterface
    {
        $bearer = request()?->bearerToken();

        if (! $bearer || ! class_exists(Sanctum::class)) {
            return null;
        }

        $model = Sanctum::personalAccessTokenModel();
        $token = $model::findToken($bearer);

        if ($token === null) {
            return null;
        }

        $expiries = [];

        if ($token->expires_at instanceof CarbonInterface) {
            $expiries[] = $token->expires_at;
        }

        $minutes = config('sanctum.expiration');

        if (is_numeric($minutes) && $token->created_at instanceof CarbonInterface) {
            $expiries[] = $token->created_at->copy()->addMinutes((int) $minutes);
        }

        if ($expiries === []) {
            return null;
        }

        usort($expiries, fn (CarbonInterface $a, CarbonInterface $b): int => $a <=> $b);

        return $expiries[0];
    }

    /**
     * @param  list<string>|null  $scope
     * @return array{0: Model, 1: list<string>}
     */
    private function samePrincipal(string $kind, TokenKind $declared, TokenIssuance $request, ?array $scope): array
    {
        /** @var SamePrincipalTokenKind $declared */
        $actor = $request->actor;

        AccountError::TokenActorRequired->abortUnless($actor instanceof Model, 403, ['kind' => $kind]);
        AccountError::TokenTargetMismatch->abortIf(
            $request->principal !== null && ! $request->principal->is($actor),
            403,
            ['kind' => $kind],
        );

        $abilities = $this->explicit($kind, $declared->abilities($actor, $request->abilities));

        if ($scope !== null) {
            $beyond = array_values(array_diff($abilities, $scope));

            if ($beyond !== []) {
                throw CodedValidationException::for('abilities', AccountError::TokenAbilitiesBeyondActingToken, ['abilities' => implode(', ', $beyond)]);
            }

            $this->refuseExpiryBeyondActingToken($request->expiresAt);
        }

        return [$actor, $abilities];
    }

    /**
     * @param  list<string>|null  $scope
     * @return array{0: Model, 1: list<string>}
     */
    private function delegated(string $kind, DelegatedTokenKind $declared, TokenIssuance $request, ?array $scope): array
    {
        $actor = $request->actor;

        if ($request->abilities !== null) {
            throw CodedValidationException::for('abilities', AccountError::TokenKindDeclaresAbilities, ['kind' => $kind]);
        }

        AccountError::TokenIssuanceAbilityMissing->abortIf(
            $scope !== null && ! in_array($declared->issuanceAbility(), $scope, true),
            403,
            ['kind' => $kind, 'ability' => $declared->issuanceAbility()],
        );

        // The subject is the kind's own context and nothing else. A request's subject is only a claim checked against
        // it: when the kind resolves none, a named subject is refused, never substituted (codex cs03 finding 2).
        $subject = $declared->boundSubject($actor);

        AccountError::TokenTargetMismatch->abortIf(
            $request->subject !== null && ($subject === null || ! $this->same($subject, $request->subject)),
            403,
            ['kind' => $kind],
        );

        $target = $declared->resolveTarget($actor, $subject);

        AccountError::TokenTargetUnresolved->abortIf($target === null, 422, ['kind' => $kind]);
        AccountError::TokenTargetMismatch->abortIf(
            $request->principal !== null && ! $request->principal->is($target),
            403,
            ['kind' => $kind],
        );
        // A delegated kind is for ANOTHER principal. One that resolves to the actor would mint a same-principal token
        // past the acting-token cap, so it is refused (codex cs03 finding 1); the actor's own token is a
        // same-principal kind.
        AccountError::TokenTargetIsActor->abortIf(
            $actor instanceof Model && $actor->getMorphClass() === $target->getMorphClass() && (string) $actor->getKey() === (string) $target->getKey(),
            403,
            ['kind' => $kind],
        );
        AccountError::TokenTargetNoAuthority->abortUnless($declared->authorizes($actor, $target, $subject), 403, ['kind' => $kind]);

        return [$target, $this->explicit($kind, $declared->abilities($target, $subject))];
    }

    /**
     * A kind's set, refused when it holds `*` or anything that is not a non-empty string. An empty list stays an
     * empty list: it authenticates and authorizes nothing, and is never read as unscoped.
     *
     * @param  array<int, mixed>  $abilities
     * @return list<string>
     */
    private function explicit(string $kind, array $abilities): array
    {
        foreach ($abilities as $ability) {
            if (! is_string($ability) || $ability === '' || $ability === '*') {
                throw CodedValidationException::for('abilities', AccountError::TokenWildcardRefused, ['kind' => $kind]);
            }
        }

        return array_values(array_unique($abilities));
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof Model && $b instanceof Model) {
            return $a->is($b);
        }

        if ($a instanceof Model || $b instanceof Model) {
            $key = fn (mixed $v): string => (string) ($v instanceof Model ? $v->getKey() : $v);

            return $key($a) === $key($b);
        }

        return is_scalar($a) && is_scalar($b) && (string) $a === (string) $b;
    }

    /**
     * Write the row on {@see BeamAccounts::tokenModel()}. The secret and hash are generated exactly as Sanctum's
     * `createToken()` does, prefix included, so the bearer authenticates through Sanctum's guard unchanged. A
     * deterministic request upserts the row under its fixed id instead, so the same (id, plaintext) is the same
     * bearer after a reset.
     *
     * @param  list<string>  $abilities
     */
    private function store(string $kind, TokenKind $declared, Model $principal, array $abilities, TokenIssuance $request): IssuedToken
    {
        $model = BeamAccounts::tokenModel();
        $deterministic = $request->deterministicId !== null && $request->deterministicPlaintext !== null;

        if ($deterministic) {
            $plainText = $request->deterministicPlaintext;
            $token = $model::query()->find($request->deterministicId) ?? (new $model)->forceFill([
                (new $model)->getKeyName() => $request->deterministicId,
            ]);
        } else {
            $entropy = Str::random(40);
            $plainText = sprintf('%s%s%s', config('sanctum.token_prefix', ''), $entropy, hash('crc32b', $entropy));
            $token = new $model;
        }

        $token->forceFill([
            'tokenable_type' => $principal->getMorphClass(),
            'tokenable_id' => $principal->getKey(),
            'name' => substr($request->name, 0, 255),
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $request->expiresAt,
            'provenance' => $declared->provenance()->value,
            self::KIND_COLUMN => $kind,
        ] + TokenLastFour::attributesFor($token, $plainText))->save();

        return new IssuedToken($token, $token->getKey().'|'.$plainText);
    }
}
