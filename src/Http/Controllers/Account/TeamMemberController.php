<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Account;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Data\RoleOptionsData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Accounts\Localization\AccountError;

/**
 * The ACCOUNT-TIER team-members surface — list, role vocabulary, role change, remove.
 *
 * ## Why a controller beside the `members` particle resource
 *
 * {@see MembershipData}'s docblock states the split in terms: *"Members are LIST-ONLY through Frame —
 * you can't create a member directly (they arrive by accepting an invite), and the inline Role cell +
 * owner-only remove are host-supplied escape hatches against a REST survivor."* The `members`
 * resource is {@see MembershipSource}-backed and therefore `creatable: false` + `deletable: false`;
 * every write below is one of the escape hatches that docblock names, and until now no beam host
 * shipped them, so the packaged `TeamPage`'s role-change and remove controls had no endpoint at any
 * beam-only host.
 *
 * ## Domain-neutral through {@see TeamContract}, never through beam's own `Team`
 *
 * Every read and write asks the CONTRACT — `members()`, `memberRole()`, `assignMember()`,
 * `removeMember()` — which beam's `Team` and a foreign-pivot host's team object both satisfy. That is
 * deliberate and it is the same correction {@see \Splicewire\Beam\Accounts\Data\InvitationData} took:
 * a guard that reaches for `$actor->teamRole($team)` or `$team->memberships()` names beam's own schema
 * while pretending to be neutral, and is a TypeError at any host whose team is not beam's.
 *
 * A resolver that returns a non-`TeamContract` is a 500 naming the misconfiguration, never a 403 — a
 * check whose answer depends on the host must not be able to read as a legitimate denial.
 *
 * ## The two gates, and why they differ
 *
 * Reading the roster and the role vocabulary is open to any MEMBER of the team; changing a role and
 * removing a seat are OWNER-only. That is the same lens `@splicewire/beam-accounts`' `TeamPage`
 * renders (`manageMembers = owner-only`, `canInvite = owner|admin`), enforced here so the client-side
 * lens is a convenience rather than the control.
 *
 * The LAST-OWNER rule is a server rule, not a UI one: demoting or removing the only owner would leave
 * a team nobody can administer, and a 422 is the honest answer — the request is well-formed and its
 * outcome is not permitted.
 */
class TeamMemberController extends Controller
{
    /**
     * List team members
     */
    #[ResponseFromData(MembershipData::class)]
    public function index(Request $request): JsonResponse
    {
        $this->team($request);

        return response()->json(['data' => $this->roster()]);
    }

    /**
     * The role vocabulary
     *
     * Both context subsets of the {@see Role} enum in one read — `assignable` (every role a member can
     * be set to, ownership transfer included) and `invitable` (owner excluded). Server-derived, so no
     * hand-authored client list can drift from the enum.
     */
    #[ResponseFromData(RoleOptionsData::class)]
    public function roles(Request $request): JsonResponse
    {
        $this->team($request);

        return response()->json(['data' => RoleOptionsData::current()]);
    }

    /**
     * Change a member's role
     */
    #[ResponseFromData(MembershipData::class)]
    #[ResponseFromData(\Splicewire\Beam\Accounts\Data\AccountErrorData::class, status: 404)]
    #[ResponseFromData(\Splicewire\Beam\Accounts\Data\AccountErrorData::class, status: 422)]
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', Role::values())],
        ]);

        $team = $this->team($request);
        $this->assertOwner($team, $request->user());

        $member = $this->member($team, $id);

        if ($member === null) {
            return response()->json((new \Splicewire\Beam\Accounts\Data\AccountErrorData(AccountError::MemberNotFound->message(), AccountError::MemberNotFound->code()))->toArray(), 404);
        }

        $role = Role::from($validated['role']);

        if ($team->memberRole($member) === Role::Owner && $role !== Role::Owner && $this->ownerCount($team) < 2) {
            return response()->json((new \Splicewire\Beam\Accounts\Data\AccountErrorData(AccountError::LastOwner->message(), AccountError::LastOwner->code()))->toArray(), 422);
        }

        $team->assignMember($member, $role);

        return response()->json(['data' => $this->row($team, $id)]);
    }

    /**
     * Remove a member
     */
    #[ResponseFromData(MembershipData::class)]
    #[ResponseFromData(\Splicewire\Beam\Accounts\Data\AccountErrorData::class, status: 404)]
    #[ResponseFromData(\Splicewire\Beam\Accounts\Data\AccountErrorData::class, status: 422)]
    public function remove(Request $request, string $id): JsonResponse
    {
        $team = $this->team($request);
        $this->assertOwner($team, $request->user());

        $member = $this->member($team, $id);

        if ($member === null) {
            return response()->json((new \Splicewire\Beam\Accounts\Data\AccountErrorData(AccountError::MemberNotFound->message(), AccountError::MemberNotFound->code()))->toArray(), 404);
        }

        if ($team->memberRole($member) === Role::Owner && $this->ownerCount($team) < 2) {
            return response()->json((new \Splicewire\Beam\Accounts\Data\AccountErrorData(AccountError::LastOwner->message(), AccountError::LastOwner->code()))->toArray(), 422);
        }

        // Snapshot before the seat goes, so the data slot carries the removed member's final state —
        // the destroy-returns-the-resource envelope rule, same read-model as index.
        $snapshot = $this->row($team, $id);

        $team->removeMember($member);

        return response()->json(['data' => $snapshot]);
    }

    /**
     * The acting request's team, asserted to satisfy the contract and to admit this principal.
     *
     * 403 for a principal who is not a member (never 404 — the team exists and refusing to say so
     * would be a different claim), 500 for a `beam.accounts.teams.resolver` bound to the wrong shape.
     */
    protected function team(Request $request): TeamContract
    {
        $team = \Splicewire\Beam\Accounts\Facades\BeamAccounts::currentTeam();

        AccountError::NoActiveTeam->abortIf($team === null, 403);

        AccountError::TeamResolverInvalid->abortUnless($team instanceof TeamContract, 500, ['type' => get_debug_type($team)]);

        $user = $request->user();

        AccountError::NotAuthenticated->abortIf($user === null, 401);
        AccountError::NotTeamMember->abortUnless($team->memberRole($user) !== null, 403);

        return $team;
    }

    protected function assertOwner(TeamContract $team, ?Authenticatable $user): void
    {
        AccountError::TeamOwnerRequired->abortUnless($user !== null && $team->memberRole($user) === Role::Owner, 403);
    }

    /** One member of this team by their opaque id, or null. */
    protected function member(TeamContract $team, string $id): ?Authenticatable
    {
        return $this->members($team)->first(
            fn (Authenticatable $user): bool => (string) $user->getAuthIdentifier() === $id
        );
    }

    /**
     * The team's active members. `members()` has two legal return shapes across the hosts this serves
     * — beam's own `Team::members()` is a `BelongsToMany` while
     * {@see \Splicewire\Beam\Accounts\Concerns\HasMembers::members()} already returns a Collection —
     * and calling `->get()` unconditionally is an `ArgumentCountError` on the second. Same
     * normalisation, same reason, as {@see MembershipSource::stream()}.
     *
     * @return \Illuminate\Support\Collection<int, Authenticatable>
     */
    protected function members(TeamContract $team): \Illuminate\Support\Collection
    {
        $members = $team->members();

        return $members instanceof \Illuminate\Database\Eloquent\Relations\Relation
            ? $members->get()
            : collect($members);
    }

    protected function ownerCount(TeamContract $team): int
    {
        return $this->members($team)
            ->filter(fn (Authenticatable $user): bool => $team->memberRole($user) === Role::Owner)
            ->count();
    }

    /**
     * The roster rows, projected by the SAME source the `members` particle resource reads, so the
     * REST list and the Frame list can never disagree about what a member row is.
     *
     * @return \Illuminate\Support\Collection<int, MembershipData>
     */
    protected function roster(): \Illuminate\Support\Collection
    {
        return collect(app(MembershipSource::class)->records([], null, 1000)->items())->values();
    }

    /** One roster row by id — read back through the source after a write, never re-derived here. */
    protected function row(TeamContract $team, string $id): ?MembershipData
    {
        $resolved = app(MembershipSource::class)->resolve($id, []);

        return $resolved?->record instanceof MembershipData ? $resolved->record : null;
    }
}
