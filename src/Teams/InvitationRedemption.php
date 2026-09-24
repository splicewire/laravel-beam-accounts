<?php

namespace Splicewire\Beam\Accounts\Teams;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;

/**
 * The one place an invitation link is judged and redeemed — shared by the accept PAGE
 * ({@see \Splicewire\Beam\Accounts\Http\Controllers\InvitationAcceptController}), the redeem OPERATION
 * ({@see \Splicewire\Beam\Accounts\Ops\RedeemInvitation}) and the mail that carries the link
 * ({@see \Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification}), so the three cannot
 * disagree about what "expired" means or where the link points.
 *
 * ## The verdicts, in the order they are checked
 *
 *  - `invalid` — no invitation holds this token (never sent, revoked, or superseded by a resend, which
 *    mints a fresh token), the signature is missing or tampered, or the team it names is gone.
 *  - `used` — it was already accepted. A single-use token is a state machine, not an identifier.
 *  - `expired` — older than `beam.accounts.invitations.expires_after_days` (default 7) since it was last
 *    sent, or its signed link's own expiry has passed. The two agree by construction: the link is
 *    signed to expire at the invitation's own expiry ({@see self::expiresAt()}).
 *  - `guest` — nobody is signed in. Register or log in first; the caller preserves the intended URL.
 *  - `wrong-account` — someone is signed in as an address other than the one invited. An invitation
 *    is addressed to an EMAIL, and the link alone is not proof the holder owns it.
 *  - `ready` — the signed-in principal is the invitee.
 *
 * ## Scope: beam's own team
 *
 * Redemption seats the invitee through {@see TeamProvisioner::addMember()}, which writes beam's own
 * membership row and team-scoped role. The team is read through {@see BeamAccounts::teamModel()}, never
 * a hardcoded `Team`: a host whose team notion is something else (a tower tenant over `tenant_users`)
 * has no `beam_teams` table at all, and redeems through its own operation (tower's
 * `AcceptTenantInvitation`). There `teamModel()` is null, {@see self::team()} answers null without a
 * query, and this class answers `invalid` rather than seating someone on a team that does not exist
 * here — or, as it did on 2026-09-24, throwing on the missing table from inside every invitation write.
 */
class InvitationRedemption
{
    public const READY = 'ready';

    public const GUEST = 'guest';

    public const WRONG_ACCOUNT = 'wrong-account';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const INVALID = 'invalid';

    /** The route the emailed link points at — the page {@see \Splicewire\Beam\Ux\Particle\Backing\DashboardWelcome} asks for by name. */
    public const ACCEPT_ROUTE = 'invitations.accept';

    /** The redeem operation's route name. */
    public const REDEEM_ROUTE = 'invitations.redeem';

    public function __construct(private TeamProvisioner $teams) {}

    /** The invitation this token names, or null. Accepted rows included — `used` is a verdict, not a miss. */
    public function find(string $token): ?Invitation
    {
        return $token === '' ? null : Invitation::query()->where('token', $token)->first();
    }

    /**
     * The beam team the invitation seats its holder on, or null when it names none here — including
     * when this host has no beam team model at all ({@see BeamAccounts::teamModel()}), in which case
     * nothing is queried.
     */
    public function team(Invitation $invitation): ?Team
    {
        $model = BeamAccounts::teamModel();

        return $model === null ? null : $model::query()->find($invitation->team_id);
    }

    /**
     * Judge a link for a viewer. `$signed` is whether the request carried a correct signature at all;
     * `$signatureExpired` whether that signature's own expiry has passed. The redeem operation is not a
     * signed route (it authenticates instead), so it passes `true, false` and relies on the invitation's
     * own age — the same expiry the signature encodes.
     */
    public function verdict(?Invitation $invitation, ?Authenticatable $viewer, bool $signed = true, bool $signatureExpired = false): string
    {
        if ($invitation === null || ! $signed || $this->team($invitation) === null) {
            return self::INVALID;
        }

        if ($invitation->accepted_at !== null) {
            return self::USED;
        }

        if ($signatureExpired || $this->expiresAt($invitation)->isPast()) {
            return self::EXPIRED;
        }

        if ($viewer === null) {
            return self::GUEST;
        }

        return $this->addressedTo($invitation, $viewer) ? self::READY : self::WRONG_ACCOUNT;
    }

    /** Whether the viewer's address is the invited one — case-insensitively, since mail providers are. */
    public function addressedTo(Invitation $invitation, Authenticatable $viewer): bool
    {
        $email = data_get($viewer, 'email');

        return is_string($email) && strcasecmp(trim($email), trim((string) $invitation->email)) === 0;
    }

    /**
     * When the invitation stops being redeemable: its last send (`updated_at` — a resend refreshes it,
     * along with the token) plus the configured window.
     */
    public function expiresAt(Invitation $invitation): Carbon
    {
        $sent = $invitation->updated_at ?? $invitation->created_at ?? now();
        $days = (int) config('beam.accounts.invitations.expires_after_days', 7);

        return Carbon::parse($sent)->addDays(max(1, $days));
    }

    /**
     * The signed link to the accept page, expiring with the invitation. Null when the host mounts no
     * `invitations.accept` route — there is then no link that could work, and the mail is not sent.
     */
    public function acceptUrl(Invitation $invitation): ?string
    {
        if (! Route::has(self::ACCEPT_ROUTE)) {
            return null;
        }

        return URL::temporarySignedRoute(self::ACCEPT_ROUTE, $this->expiresAt($invitation), ['token' => $invitation->token]);
    }

    /**
     * The redeem operation's URL for this invitation, when the host mounts it. The operation route's
     * coordinate is named `{id}` (every op mounts at `{uri}/{id}/{op}`); its `ColumnSubject` reads the
     * segment as the TOKEN.
     */
    public function redeemUrl(Invitation $invitation): ?string
    {
        return Route::has(self::REDEEM_ROUTE)
            ? route(self::REDEEM_ROUTE, ['id' => $invitation->token], false)
            : null;
    }

    /**
     * Seat the invitee with the invited role, make the team their current team, and burn the token.
     *
     * The caller has already obtained a `ready` verdict. A principal who is ALREADY on the team keeps
     * the role they hold — accepting a stale "member" invitation must not demote an owner — and the
     * invitation is still marked accepted, so the link cannot be replayed.
     */
    public function redeem(Invitation $invitation, Authenticatable $viewer): Team
    {
        $team = $this->team($invitation);

        abort_if($team === null, 404);

        $current = $team->memberRole($viewer);
        $role = $current ?? (Role::tryFrom((string) $invitation->role) ?? Role::Member);

        // Owner is not invitable (Role::invitable()), so an invitation row carrying it was not written
        // by any path this package offers; seat it as a member rather than hand out ownership.
        if ($current === null && $role === Role::Owner) {
            $role = Role::Member;
        }

        $this->teams->addMember($viewer, $team, $role);

        if (method_exists($viewer, 'switchTeam')) {
            $viewer->switchTeam($team);
        } else {
            $viewer->forceFill(['current_team_id' => $team->getKey()])->save();
        }

        $invitation->forceFill(['accepted_at' => now()])->save();

        return $team;
    }

    /** The team name an invitation names, for display — null when it names no team here. */
    public function teamName(Invitation $invitation): ?string
    {
        return $this->team($invitation)?->name;
    }

    /** Who sent it, for the "invited by" line. */
    public function inviterName(Invitation $invitation): ?string
    {
        $inviter = $invitation->invited_by === null ? null : $invitation->inviter()->first();

        $name = $inviter?->getAttribute('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
