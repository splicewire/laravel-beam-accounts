<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Account;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Accounts\Data\ApiTokenCreateInputData;
use Splicewire\Beam\Accounts\Data\ApiTokenData;
use Splicewire\Beam\Accounts\Data\ApiTokenExpiryInputData;
use Splicewire\Beam\Accounts\Data\CreatedTokenData;
use Splicewire\Beam\Accounts\Data\HeldPermissionsData;
use Splicewire\Beam\Accounts\Data\SessionsRevokedData;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\QueryBuilders\TokensQuery;

/**
 * The ACCOUNT-TIER personal-access-token lifecycle — the "host REST survivor" half of the tokens
 * surface, shipped by the package that owns the semantics instead of re-authored per host.
 *
 * ## Why this is a controller and not more of the `tokens` particle resource
 *
 * {@see TokenData} is the particle resource and it is deliberately `readOnly: true, deletable: true`
 * — list + revoke, no create. Its docblock states the reason and this class is its other half:
 *
 * > CREATE stays a HOST escape hatch — Frame's generic create has no notion of a create-response
 * > carrying a display-once secret, and that reveal-once dialog is genuine security UX Frame should
 * > not generate. […] The reveal-once create + rotate/renew lifecycle stay a host REST survivor.
 *
 * Measured 2026-09-11 at `~/Workspaces/laravel/starters/laravel-beam-starter`: no host in the beam
 * starter family ever wired that escape hatch, so `ApiTokenData` and `CreatedTokenData` — both
 * declared in this package since the Frame OS lift — were response shapes served by nothing, and the
 * `/tokens` console leaf rendered a list with no way to put a row in it. Shipping the survivor here
 * is what makes those two declarations true.
 *
 * ## The isolation boundary is the resource's, not a second one
 *
 * Personal access tokens live in ONE table shared by every principal. Every read and every mutation
 * below resolves its subject through {@see self::scoped()}, which applies
 * {@see TokenData::scope()} — the SAME closure the Frame list and the Frame revoke-by-id ride, so a
 * host that has bound `beam.accounts.tokens.scope` gets one boundary across all three transports
 * rather than a REST surface that quietly disagrees with its own console. A row outside the scope is
 * a 404, never another principal's token and never a 403 that confirms the id exists.
 *
 * The MODEL follows `beam.accounts.tokens.model` through {@see BeamAccounts::tokenModel()}, the same
 * two-step fallback {@see \Splicewire\Beam\Accounts\Particle\Backing\ConfiguredTokenBacking} reads,
 * so a host with a bespoke PAT (its own connection, a uuid key) is served without subclassing this.
 *
 * ## Not a promotion of tower's controller
 *
 * `Splicewire\Tower\Api\V1\ApiTokenController` is the ancestor in shape and is untouched: it resolves
 * a cross-guard CENTRAL user (`config('auth.providers.users.model')::findOrFail`), writes every verb
 * to a `CentralActivityLog`, and reads its list through tower's `DataFilter::query('tokens')` saved-
 * filter surface. All three are tower facts — a beam host has no central guard, no activity log and
 * no saved filters on this key — so this is the domain-neutral sibling, not a move. The WIRE is
 * deliberately identical (`{api_root}/tokens`, `beam.accounts.tokens.*`, the `{data: …}` envelope, the
 * `expires_in_days` spelling), because `@splicewire/beam-accounts`' `TokensClient` is written against
 * it and must keep working at either host.
 */
class ApiTokenController extends Controller
{
    /**
     * List API tokens
     *
     * The acting principal's own personal access tokens, newest first.
     */
    #[ResponseFromData(ApiTokenData::class)]
    public function index(Request $request): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        $tokens = $this->scoped()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Model $token): ApiTokenData => $this->present($token, $currentId))
            ->values();

        return response()->json(['data' => $tokens]);
    }

    /**
     * The permission names you hold
     *
     * The ceiling a scoped token may draw from — what the scoped-create picker offers, and what
     * {@see self::clampAbilities()} validates a requested scope against, so the picker and the mint
     * can never disagree about what is offerable.
     */
    #[ResponseFromData(HeldPermissionsData::class)]
    public function permissions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new HeldPermissionsData(
                permissions: array_values($this->heldPermissionNames($this->tokenOwner($request))),
            ),
        ]);
    }

    /**
     * Create an API token
     *
     * Mint a token and return its plaintext secret ONCE. The secret is never retrievable again — the
     * stored column is a hash — which is the whole reason this verb cannot be Frame's generic create.
     */
    #[RequestFromData(ApiTokenCreateInputData::class)]
    #[ResponseFromData(CreatedTokenData::class, status: 201)]
    public function store(Request $request): JsonResponse
    {
        // Resolved HERE rather than injected as a typed parameter, deliberately: container injection
        // validates during resolution, i.e. BEFORE the method body, which would put 422 ahead of the
        // route's own auth gate.
        $input = ApiTokenCreateInputData::validateAndCreate($request);

        $user = $this->tokenOwner($request);

        [$token, $plainText] = $this->mint(
            $user,
            $input->name,
            $this->clampAbilities($user, $this->optional($input->abilities)),
            $this->expiresAtFor($this->optional($input->expiresInDays)),
        );

        return response()->json([
            'data' => new CreatedTokenData(
                id: (string) $token->getKey(),
                name: (string) $token->name,
                token: $plainText,
            ),
        ], 201);
    }

    /**
     * Renew a token
     *
     * Extend a token's expiry in place. The secret does not change, so every client already holding
     * it keeps working — a convenience, not a security measure. To change the secret, rotate.
     */
    #[RequestFromData(ApiTokenExpiryInputData::class)]
    #[ResponseFromData(ApiTokenData::class)]
    public function renew(Request $request, string $id): JsonResponse
    {
        $input = ApiTokenExpiryInputData::validateAndCreate($request);

        $token = $this->findOwnToken($id);

        if ($token === null) {
            return response()->json(['message' => 'Token not found'], 404);
        }
        if ($token->archived_at) {
            return response()->json(['message' => 'Cannot renew an archived token.'], 422);
        }

        $token->forceFill(['expires_at' => $this->expiresAtFor($this->optional($input->expiresInDays))])->save();

        return response()->json(['data' => $this->present($token->fresh(), $this->currentTokenId($request))]);
    }

    /**
     * Rotate a token
     *
     * Mint a replacement secret carrying the same name and scope, then archive the original. The new
     * secret is shown once, in this response, and cannot be retrieved again.
     */
    #[RequestFromData(ApiTokenExpiryInputData::class)]
    #[ResponseFromData(CreatedTokenData::class, status: 201)]
    public function rotate(Request $request, string $id): JsonResponse
    {
        $input = ApiTokenExpiryInputData::validateAndCreate($request);

        $old = $this->findOwnToken($id);

        if ($old === null) {
            return response()->json(['message' => 'Token not found'], 404);
        }
        if ((string) $old->getKey() === (string) $this->currentTokenId($request)) {
            return response()->json(['message' => 'You cannot rotate the token you are using.'], 422);
        }
        if ($old->archived_at) {
            return response()->json(['message' => 'Cannot rotate an archived token.'], 422);
        }

        // Same name + scope, carried verbatim: the scope was clamped when first minted.
        [$new, $plainText] = $this->mint(
            $this->tokenOwner($request),
            (string) $old->name,
            $old->abilities ?: ['*'],
            $this->expiresAtFor($this->optional($input->expiresInDays)),
        );

        $this->neutralize($old);

        return response()->json([
            'data' => new CreatedTokenData(
                id: (string) $new->getKey(),
                name: (string) $new->name,
                token: $plainText,
            ),
        ], 201);
    }

    /**
     * Archive a token
     *
     * Soft-revoke. The token stops working IMMEDIATELY — the stored hash is overwritten, so the dead
     * secret can never authenticate again — while the row is kept for audit with its name, scope and
     * timestamps intact.
     *
     * You cannot archive the token you are currently authenticating with (422); archiving an already
     * archived token is a 409.
     */
    #[ResponseFromData(ApiTokenData::class)]
    public function archive(Request $request, string $id): JsonResponse
    {
        $token = $this->findOwnToken($id);

        if ($token === null) {
            return response()->json(['message' => 'Token not found'], 404);
        }
        if ((string) $token->getKey() === (string) $this->currentTokenId($request)) {
            return response()->json(['message' => 'You cannot archive the token you are using.'], 422);
        }
        if ($token->archived_at) {
            return response()->json(['message' => 'Token already archived'], 409);
        }

        $this->neutralize($token);

        return response()->json([
            'message' => 'Token archived',
            'data' => $this->present($token->fresh(), $this->currentTokenId($request)),
        ]);
    }

    /**
     * Delete a token permanently
     *
     * Remove the row outright. Only an already-archived token can be deleted, so the archive step is
     * never skipped by accident. The response carries the deleted token's final state.
     */
    #[ResponseFromData(ApiTokenData::class)]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $token = $this->findOwnToken($id);

        if ($token === null) {
            return response()->json(['message' => 'Token not found'], 404);
        }
        if ((string) $token->getKey() === (string) $this->currentTokenId($request)) {
            return response()->json(['message' => 'You cannot delete the token you are using.'], 422);
        }
        if (! $token->archived_at) {
            return response()->json(['message' => 'Archive the token before deleting it.'], 422);
        }

        // Snapshot before the row goes, so the data slot carries the deleted token's final state.
        $snapshot = $this->present($token, $this->currentTokenId($request));

        $token->delete();

        return response()->json(['message' => 'Token deleted', 'data' => $snapshot]);
    }

    /**
     * Log out other sessions
     *
     * Revoke every SESSION token for this principal except the one making the request. Deliberately
     * minted API tokens are left working, so this cannot accidentally break an integration.
     */
    #[ResponseFromData(SessionsRevokedData::class)]
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        $revoked = $this->scoped()
            ->where('provenance', TokenProvenance::Session->value)
            ->when($currentId !== null, fn (Builder $query) => $query->where($this->keyName(), '!=', $currentId))
            ->delete();

        return response()->json([
            'message' => "Revoked {$revoked} other session(s).",
            'data' => new SessionsRevokedData(revoked: (int) $revoked),
        ]);
    }

    /**
     * Mint a personal access token on {@see BeamAccounts::tokenModel()} and return
     * `[$model, $plainTextSecret]`.
     *
     * ⚠️ **Deliberately NOT `$user->createToken()`.** Sanctum's trait method writes through
     * `Sanctum::personalAccessTokenModel()` — a GLOBAL, set by `Sanctum::usePersonalAccessTokenModel()`,
     * which this package does not call and has no business calling (it would decide the model for
     * every other Sanctum consumer at the host). The result would be a mint into Sanctum's default
     * model while every read below queries the configured one: at a host that has bound
     * `beam.accounts.tokens.model` to a bespoke PAT (its own connection, a uuid key) the token would
     * land in the wrong table and the list would never show it. Reading and writing through the one
     * seam is what makes that impossible.
     *
     * It also means the acting principal needs no `HasApiTokens` trait — this surface's contract is
     * `beam.accounts.tokens.{model,scope}`, not Sanctum's trait.
     *
     * The secret and the hash are generated exactly as `HasApiTokens::createToken()` does, prefix
     * included, so a token minted here authenticates through Sanctum's ordinary guard unchanged.
     *
     * @param  array<int, string>  $abilities
     * @return array{0: Model, 1: string}
     */
    protected function mint(Authenticatable $user, string $name, array $abilities, ?Carbon $expiresAt): array
    {
        $model = BeamAccounts::tokenModel();

        $entropy = Str::random(40);
        $plainText = sprintf('%s%s%s', config('sanctum.token_prefix', ''), $entropy, hash('crc32b', $entropy));

        $token = new $model;
        $token->forceFill([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getAuthIdentifier(),
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            // Deliberately-created token — the provenance this surface exists to keep a stable list of.
            'provenance' => TokenProvenance::Api->value,
        ])->save();

        return [$token, $token->getKey().'|'.$plainText];
    }

    /**
     * The scoped base query — {@see TokenData::scope()} over {@see BeamAccounts::tokenModel()}.
     *
     * Asking the RESOURCE for its scope rather than calling {@see TokensQuery::scopeToOwner()}
     * directly is the load-bearing part: a host that has bound `beam.accounts.tokens.scope` gets the
     * same boundary here that its console already rides, and the two cannot drift.
     */
    protected function scoped(): Builder
    {
        $model = BeamAccounts::tokenModel();

        return TokenData::scope($model::query());
    }

    /** One of the acting principal's own tokens by id, or null — never another principal's row. */
    protected function findOwnToken(string $id): ?Model
    {
        return $this->scoped()->where($this->keyName(), $id)->first();
    }

    /** The PAT model's key column, read off the configured model rather than assumed to be `id`. */
    protected function keyName(): string
    {
        $model = BeamAccounts::tokenModel();

        return (new $model)->getKeyName();
    }

    /**
     * The tokenable the mint hangs off — the authenticated principal.
     *
     * A host whose token owner is a DIFFERENT principal from the authenticated one (tower's
     * cross-guard central User) mounts its own controller; this package's contract is that the
     * minting principal and the scope's principal are the same, which is exactly what makes
     * {@see TokensQuery::scopeToOwner()} the correct default boundary.
     */
    protected function tokenOwner(Request $request): Authenticatable
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Not authenticated.');

        return $user;
    }

    /**
     * The id of the PAT authenticating THIS request, resolved from the bearer so it works whether or
     * not the acting model exposes `currentAccessToken()`. Null for cookie/session auth.
     */
    protected function currentTokenId(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return null;
        }

        $model = BeamAccounts::tokenModel();
        $token = $model::findToken($bearer);

        return $token === null ? null : (string) $token->getKey();
    }

    /** The list read-model for one token. */
    protected function present(Model $token, ?string $currentId): ApiTokenData
    {
        $provenance = $token->provenance instanceof TokenProvenance
            ? $token->provenance
            : TokenProvenance::infer((string) $token->name, $token->abilities ?? []);

        return new ApiTokenData(
            id: (string) $token->getKey(),
            name: (string) $token->name,
            provenance: $provenance,
            abilities: $this->scopeFor($token->abilities),
            created_at: $token->created_at?->toIso8601String(),
            last_used_at: $token->last_used_at?->toIso8601String(),
            expires_at: $token->expires_at?->toIso8601String(),
            archived_at: $token->archived_at?->toIso8601String(),
            is_current: $currentId !== null && (string) $token->getKey() === $currentId,
        );
    }

    /**
     * A token's granted scope for display: null for an unscoped (`*`) token, otherwise the list of
     * permission names it may exercise.
     *
     * @param  array<int, string>|null  $abilities
     * @return array<int, string>|null
     */
    protected function scopeFor(?array $abilities): ?array
    {
        $abilities ??= ['*'];

        return in_array('*', $abilities, true) ? null : array_values($abilities);
    }

    /**
     * Clamp a requested scope to permissions the minting principal actually holds — you can only
     * scope DOWN from yourself, so a stored scope can never over-state what the token may do.
     *
     * Exactly `['*']`, an empty list, or a list containing `'*'` is unscoped. Any other list is a
     * real permission-name scope and every entry must be held.
     *
     * @param  array<int, string>|null  $requested
     * @return array<int, string>
     */
    protected function clampAbilities(Authenticatable $user, ?array $requested): array
    {
        $requested = array_values(array_unique($requested ?? ['*']));

        if ($requested === [] || in_array('*', $requested, true)) {
            return ['*'];
        }

        $overreach = array_values(array_diff($requested, $this->heldPermissionNames($user)));

        if ($overreach !== []) {
            throw ValidationException::withMessages([
                'abilities' => [
                    'A token can only be scoped to permissions you hold. Not held: '
                        .implode(', ', $overreach).'.',
                ],
            ]);
        }

        return $requested;
    }

    /**
     * The permission names the acting principal holds — the ceiling a scoped token draws from.
     *
     * A principal whose model carries no spatie permission trait holds none as far as this surface is
     * concerned, which makes every non-`*` scope a 422 rather than a 500. "Does this host's user model
     * have permissions" is a host fact and must not throw.
     *
     * @return array<int, string>
     */
    protected function heldPermissionNames(Authenticatable $user): array
    {
        if (! method_exists($user, 'getAllPermissions')) {
            return [];
        }

        return $user->getAllPermissions()->pluck('name')->all();
    }

    /** Turn the optional `expires_in_days` input into an absolute expiry (null = never). */
    protected function expiresAtFor(?int $days): ?Carbon
    {
        return $days ? now()->addDays($days) : null;
    }

    /**
     * Kill a token's secret while retaining its row for audit: stamp `archived_at` and overwrite the
     * stored hash with random bytes, so no plaintext can ever match it again.
     */
    protected function neutralize(Model $token): void
    {
        $token->forceFill([
            'archived_at' => now(),
            'token' => hash('sha256', Str::random(80)),
        ])->save();
    }

    /**
     * Collapse spatie-data's `Optional` sentinel to null.
     *
     * The three input properties are `X|Optional|null` with `= null` defaults so an omitted key stays
     * out of the emitted schema's `required` list; a present-but-Optional value would still reach a
     * consumer here, and `null` is the documented meaning of "omitted" for all of them.
     */
    protected function optional(mixed $value): mixed
    {
        return $value instanceof \Spatie\LaravelData\Optional ? null : $value;
    }
}
