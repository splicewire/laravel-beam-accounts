<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\ProfileUpdateInputData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;

/**
 * Self-service edit of the authenticated user's own profile (admin-redesign ticket 03), the API/JSON
 * variant — relocated down from Tower (HTTP-07) on the canonical Data shape. It survives ALONGSIDE
 * beam-accounts' existing web/Inertia `ProfileController` (asset 08 Part 1): same edit, two transports.
 *
 * Mints no token — a profile save mirrors the incoming bearer rather than issuing a fresh session token
 * (asset 11 §3.5) — so the projection stays a pure {@see AuthUserData::fromUser}, no mint inside it. The
 * `ProfileUpdateInputData` sources its name/email rules (unique-ignore-self email) from beam-accounts'
 * `ProfileValidationRules`, killing the request's former inline dup.
 *
 * The principal in tenant context is a TenantUser whose name/email sync back to the central User (via
 * ResourceSyncing on the config-bound user model), so a single save keeps the login identity of record and
 * the tenant projection in step. The former `ProfileUpdateRequest::authorize()` (`user() !== null`) is gone
 * — the route's `auth:sanctum` tier already guarantees a user (asset 11 §5).
 *
 * The decision is self-only by construction (it writes the request's own user), and under a scoped token it is named
 * like `UserPolicy::update`'s self arm (fleet ADR 0006 C2, credential-scope 05 review): the token must carry
 * `user.update` or `user.{id}.update` for the configured user model, or the save is refused with the coded scope
 * refusal before anything is written. A session or a `*` token is unchanged.
 */
class ProfileController extends Controller
{
    /**
     * Update your profile
     *
     * Change your own name and email. Returns the same shape as reading your profile, so a client can
     * swap the result straight into place.
     */
    #[ResponseFromData(AuthUserData::class)]
    public function update(ProfileUpdateInputData $input, Request $request): ResponseBody
    {
        $user = $request->user();

        app(CredentialScopeGate::class)->authorizeCarried($user, 'me.update', self::decisionNames($user));

        $user->name = $input->name;
        $user->email = $input->email;
        $user->save();

        // Mirror the bearer through rather than minting a fresh session token on a profile save.
        return ResponseBody::from([
            'data' => AuthUserData::fromUser($user->fresh(), $request->bearerToken()),
        ]);
    }

    /**
     * The names that admit the self-service write: the configured user model's `update`, class-level or for this
     * user's own id, as `UserPolicy::update` derives them for the central user of record (a tenant mirror shares its key).
     *
     * @return list<string>
     */
    public static function decisionNames(mixed $user): array
    {
        $model = BeamAccounts::userModel();

        return [
            PermissionNamer::assemble($model, 'update'),
            PermissionNamer::assemble($model, (string) $user->getAuthIdentifier(), 'update'),
        ];
    }
}
