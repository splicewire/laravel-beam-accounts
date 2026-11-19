<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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
 *  - for a same-principal kind under a scoped acting credential, any ability that credential lacks (C6);
 *  - for a delegated kind, a scoped acting credential without the kind's issuance ability, a request naming a
 *    principal or subject other than the one the kind resolves, and an actor without live authority over the
 *    resolved principal (C6).
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

        $bound = $declared->boundSubject($actor);

        AccountError::TokenTargetMismatch->abortIf(
            $bound !== null && $request->subject !== null && ! $this->same($bound, $request->subject),
            403,
            ['kind' => $kind],
        );

        $subject = $bound ?? $request->subject;
        $target = $declared->resolveTarget($actor, $subject);

        AccountError::TokenTargetUnresolved->abortIf($target === null, 422, ['kind' => $kind]);
        AccountError::TokenTargetMismatch->abortIf(
            $request->principal !== null && ! $request->principal->is($target),
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
