<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Account;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Accounts\Data\CreateInvitationData;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Models\Invitation;

/**
 * The ACCOUNT-TIER team-invitations surface — list, send, resend, revoke.
 *
 * ## Every rule below is {@see InvitationData}'s, called rather than restated
 *
 * The `invitations` particle resource already carries the whole semantics, on two convention hooks:
 *
 *  - {@see InvitationData::prepare()} — the owner/admin authorization (403), the
 *    `updateOrCreate([team_id, email])` that makes a re-invite an UPDATE rather than a unique-key
 *    collision, the fresh 64-character token mint, and the `invited_by` / `accepted_at` stamps.
 *  - {@see InvitationData::scope()} — the current team + `whereNull(accepted_at)` filter, so an
 *    accepted or foreign-team invitation never resolves and therefore can never be revoked.
 *
 * This controller calls both. It deliberately owns NO rule of its own: a REST surface that restated
 * the gate would be a second place for it to be written, and the estate has measured what that costs
 * (`particle-manifest-repatriation` 06 — three keys whose behaviour depended on provider order).
 * `POST /frame/resources/invitations` and `POST {api_root}/invitations` are therefore the same act
 * through two transports, with one authority.
 *
 * ## Why it exists at all, when Frame already serves a create
 *
 * Because the Frame generic create cannot be REACHED from the console at a beam host. Measured
 * 2026-09-11 at `~/Workspaces/laravel/starters/laravel-beam-starter`: `invitations` declares
 * `showable: false, editable: false`, so its route context carries no `/:id` record twin; the tenant
 * console's list override passes `onOpen` only when a twin exists, and `ListShell` derives the
 * toolbar's `onNew` from that same `onOpen` — so the "New invitation" button rendered with
 * `onClick={undefined}`. Clicking it fired no request, opened no dialog and changed no route. The
 * declaration was correct and the generic UI had no way to serve it.
 *
 * `@splicewire/beam-accounts`' `TeamPage` is the surface that does serve it (its own invite dialog,
 * pending/accepted roster, resend and revoke), and this is the transport it is written against.
 */
class TeamInvitationController extends Controller
{
    /**
     * List pending invitations
     *
     * The current team's UNACCEPTED invitations. The scope is {@see InvitationData::scope()}, the same
     * one the Frame list and the Frame revoke-by-id ride.
     */
    #[ResponseFromData(InvitationData::class)]
    public function index(Request $request): JsonResponse
    {
        $invitations = $this->scoped()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $invitation): InvitationData => InvitationData::project($invitation))
            ->values();

        return response()->json(['data' => $invitations]);
    }

    /**
     * Send an invitation
     *
     * Owner/admin only (403 otherwise). Re-inviting an address that already has a pending invitation
     * updates that row and mints a fresh token rather than colliding on `unique(team_id, email)`.
     */
    #[RequestFromData(CreateInvitationData::class)]
    #[ResponseFromData(InvitationData::class, status: 201)]
    public function send(Request $request): JsonResponse
    {
        // Resolved here rather than injected as a typed parameter: container injection validates
        // during resolution, which would put 422 ahead of `prepare()`'s own 403.
        $input = CreateInvitationData::validateAndCreate($request);

        $invitation = $this->persist($input, $request);

        return response()->json(['data' => InvitationData::project($invitation)], 201);
    }

    /**
     * Resend an invitation
     *
     * Re-issues a PENDING invitation: a fresh token, a fresh `invited_by` stamp, the same address and
     * role. Gated exactly as `send` is, because it is the same act — `prepare()` finds the existing
     * row by `[team, email]` and updates it.
     */
    #[ResponseFromData(InvitationData::class)]
    public function resend(Request $request, string $id): JsonResponse
    {
        $existing = $this->scoped()->whereKey($id)->first();

        if ($existing === null) {
            return response()->json(['message' => 'Invitation not found'], 404);
        }

        $invitation = $this->persist(
            new CreateInvitationData(email: $existing->email, role: (string) $existing->role),
            $request,
        );

        return response()->json(['data' => InvitationData::project($invitation)]);
    }

    /**
     * Revoke an invitation
     *
     * Deletes a still-pending invitation. An accepted or foreign-team row never resolves through
     * {@see InvitationData::scope()}, so it answers 404 rather than deleting something it should not
     * be able to see.
     */
    #[ResponseFromData(InvitationData::class)]
    public function revoke(Request $request, string $id): JsonResponse
    {
        $invitation = $this->scoped()->whereKey($id)->first();

        if ($invitation === null) {
            return response()->json(['message' => 'Invitation not found'], 404);
        }

        // The owner/admin gate is the resource's own, asked directly: `prepare()` runs it for a WRITE,
        // revoke has no write to prepare, and both now call the one extracted
        // {@see InvitationData::assertManages()} rather than each carrying a copy.
        InvitationData::assertManages($request->user());

        $snapshot = InvitationData::project($invitation);

        $invitation->delete();

        return response()->json(['data' => $snapshot]);
    }

    /**
     * Run the resource's own `prepare()` hook over a fresh model and persist it — the identical path
     * beam-core's generic Frame writer takes, so the two transports produce identical rows.
     */
    protected function persist(CreateInvitationData $input, Request $request): Invitation
    {
        $invitation = new Invitation;

        InvitationData::prepare($invitation, $input, $request->user());

        // The generic writer fills the DTO's own fields after `prepare()`; `role` is the only one
        // `prepare()` deliberately leaves to it (it stamps identity, team and token, never the role).
        $invitation->role = $input->role;
        $invitation->save();

        return $invitation;
    }

    /**
     * The pending-invitations query for the current team — {@see InvitationData::scope()} over the
     * package's own model, never a hand-written `where`.
     */
    protected function scoped(): \Illuminate\Database\Eloquent\Builder
    {
        return InvitationData::scope(Invitation::query());
    }
}
