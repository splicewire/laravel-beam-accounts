<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Accounts\Data\InvitationAcceptedData;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\ColumnSubject;

/**
 * `POST invitations/{id}/redeem` (`invitations.redeem`, the segment being the invitation's token) — the invitee takes the seat the emailed
 * link offered. The `invitations.accept` page shows the invitation and posts here. Minted with
 * `splicewire:beam:make:particle-op RedeemInvitation --resource=invitations --op=redeem`.
 *
 * ## Why `redeem` and not `accept`
 *
 * `splicewire/tower` already declares `invitations.accept` (`AcceptTenantInvitation`, seating a TENANT
 * member over `tenant_users`), and the operation registry is keyed by resource + name. A tower host
 * installs both packages, so a second `accept` would displace one of them by provider order. The web
 * page the mail links to is the route NAMED `invitations.accept` — that is the name `DashboardWelcome`
 * asks the router for — and it is a page, not this operation.
 *
 * ## `subject: new ColumnSubject('token', throughResource: false)`
 *
 * Addressed by the bearer token, like tower's sibling, and for the same reason off the resource scope:
 * `InvitationData::scope()` pins `team_id` to the AMBIENT team, and the invitee's ambient team is by
 * construction not the inviting one. An unknown token is a 404 from the resolver; the rest of the state
 * machine (used, expired, wrong address) is {@see InvitationRedemption::verdict()}'s, here and on the page.
 *
 * ## `ability: false`
 *
 * The actor is by construction not yet a member, so every membership ability would deny the one caller
 * this exists for. The controls are the mount's `auth` middleware (you must be signed in), the token
 * (you must hold the link) and the verdict (you must BE the invited address). `input: false`: the token
 * in the path is the whole input.
 *
 * A refusal answers a JSON caller with a status (410 used/expired, 403 wrong address) and the Inertia
 * form with a redirect back to the accept page carrying an `invitation` error, so the page can say why.
 */
#[ParticleOp(
    resource: 'invitations',
    name: 'redeem',
    kind: OperationKind::Write,
    ability: false,
    input: false,
    output: InvitationAcceptedData::class,
    subject: new ColumnSubject('token', throughResource: false),
)]
class RedeemInvitation
{
    public static function handle(Invitation $invitation, Request $request, mixed $actor): mixed
    {
        $redemption = app(InvitationRedemption::class);

        $verdict = $redemption->verdict($invitation, $actor);

        if ($verdict !== InvitationRedemption::READY) {
            self::refuse($verdict, $request);
        }

        $team = $redemption->redeem($invitation, $actor);

        if ($request->expectsJson()) {
            return new InvitationAcceptedData(
                teamId: (string) $team->getKey(),
                teamName: (string) $team->name,
                role: (string) ($team->memberRole($actor)?->value ?? $invitation->role),
            );
        }

        return redirect()->to(self::landing())->with('status', 'invitation-accepted');
    }

    /** Where the new member lands: the dashboard of the team they just joined. */
    public static function landing(): string
    {
        $target = config('beam.accounts.invitations.accepted_redirect', 'dashboard');

        return is_string($target) && Route::has($target) ? route($target) : '/';
    }

    private static function refuse(string $verdict, Request $request): never
    {
        [$status, $message] = match ($verdict) {
            InvitationRedemption::USED => [410, __('This invitation has already been used.')],
            InvitationRedemption::EXPIRED => [410, __('This invitation has expired. Ask the team for a new one.')],
            InvitationRedemption::WRONG_ACCOUNT => [403, __('This invitation was sent to a different email address.')],
            InvitationRedemption::GUEST => [401, __('Sign in to accept this invitation.')],
            default => [404, __('This invitation link is not valid.')],
        };

        if ($request->expectsJson()) {
            abort($status, $message);
        }

        throw ValidationException::withMessages(['invitation' => $message]);
    }
}
