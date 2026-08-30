<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\BeamAccountsManager;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;

/**
 * beam-facade 167 — the `beam.accounts.teams.resolver` BOUND branch, at package tier.
 *
 * `BeamAccountsManager::currentTeam()` (`src/BeamAccountsManager.php:87-99`) has three outcomes: a
 * bound callable wins, else `$user->currentTeamOrPersonal()`, else null. Until this file the
 * package proved only the last two — `AccountResourcesTest.php:202` asserts the null branch, every
 * team test exercises the default — while the branch the seam SHIPS FOR was proven at exactly one
 * host, `~/Herd/splicewire-app/tests/Feature/AccountsTeamResolverTest.php`, under one binding
 * (`fn () => tenant()`) and one framework version.
 *
 * What is new here rather than mirrored from the host test:
 *
 * - the team is NOT a tenant. `HostTeam` is a foreign-pivot fixture, so this is the first exercise
 *   anywhere of a resolver returning something other than `Splicewire\Beam\Tenancy\Tenant` — the
 *   case the seam's own docblock invites and nothing in the estate had.
 * - the `is_callable()` REJECTION, which no test at either tier asserted. A bare invokable
 *   class-string is false for `is_callable()`, so the seam silently falls through to the default:
 *   the estate's "an instrument that reports success by not running" shape, in the seam itself.
 * - every case asserts the branch ACTUALLY EXECUTED (a resolver call counter), never merely that
 *   nothing threw — the requirement 167's own probe note names after its first drive returned a
 *   clean, entirely false result.
 * - the two resolver consumers the host test does not drive: `MembershipSource` (whose
 *   Relation-vs-Collection branch exists precisely for `HasMembers` hosts) and
 *   `InvitationData::scope()`, where a wrong resolver degrades to an empty list rather than an
 *   error.
 */
beforeEach(function () {
    Schema::create('host_teams', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('host_team_users', function (Blueprint $table): void {
        $table->id();
        $table->string('host_team_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role')->default('member');
        $table->timestamp('removed_at')->nullable();
        $table->timestamps();
        $table->unique(['host_team_id', 'user_id']);
    });

    $this->hostTeam = HostTeam::create(['id' => 'host-team-1', 'name' => 'Host Team']);

    // The resolver call counter. Every case asserts on it: a passing assertion about
    // `currentTeam()` proves nothing unless the bound callable is what produced the answer.
    $this->calls = 0;

    $this->bindResolver = function (?object $team) {
        config(['beam.accounts.teams.resolver' => function () use ($team): ?object {
            $this->calls++;

            return $team;
        }]);
    };
});

afterEach(function () {
    config(['beam.accounts.teams.resolver' => null]);
});

it('lets a bound resolver win over the default branch, and proves the callable ran', function () {
    $owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
    Auth::login($owner);

    // The user HAS a personal team, so the default branch has a non-null answer available. The
    // bound resolver must still win: this is precedence, not a null-fallback.
    expect($owner->currentTeamOrPersonal())->not->toBeNull();

    ($this->bindResolver)($this->hostTeam);

    $team = BeamAccounts::currentTeam();

    expect($this->calls)->toBe(1)
        ->and($team)->toBeInstanceOf(HostTeam::class)
        ->and($team)->toBeInstanceOf(TeamContract::class)
        ->and($team->getKey())->toBe('host-team-1')
        ->and($team->teamKey())->toBe('host-team-1');
});

it('honours a resolver that returns null instead of falling through to the personal team', function () {
    $owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner-null@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
    Auth::login($owner);

    ($this->bindResolver)(null);

    // A host in central context answers null. Falling back to the personal team here would hand a
    // tenanted host a team it does not have — the failure 155 found in the OTHER direction.
    expect(BeamAccounts::currentTeam())->toBeNull()
        ->and($this->calls)->toBe(1);
});

it('REJECTS a bare invokable class-string and silently falls through to the default', function () {
    $owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner-invokable@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
    Auth::login($owner);

    InvokableResolverStub::$calls = 0;
    config(['beam.accounts.teams.resolver' => InvokableResolverStub::class]);

    // `is_callable('Class\With\__invoke')` is FALSE — the class-string is not a callable until it
    // is instantiated. So the seam does not reject loudly; it takes the default branch, and a host
    // that declared its resolver this way gets beam's own team notion with no error anywhere.
    expect(is_callable(config('beam.accounts.teams.resolver')))->toBeFalse();

    $team = BeamAccounts::currentTeam();

    expect(InvokableResolverStub::$calls)->toBe(0)
        ->and($team)->not->toBeNull()
        ->and($team->getKey())->toBe($owner->personalTeam()->getKey());
});

it('answers the team contract off the foreign pivot, honouring the removed column', function () {
    ($this->bindResolver)($this->hostTeam);

    $member = User::create(['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'x']);
    $outsider = User::create(['name' => 'Zed', 'email' => 'zed@example.test', 'password' => 'x']);

    $team = BeamAccounts::currentTeam();

    expect($team->hasMember($member))->toBeFalse();

    $team->assignMember($member, Role::Member);

    expect($team->hasMember($member))->toBeTrue()
        ->and($team->memberRole($member))->toBe(Role::Member)
        ->and($team->hasMember($outsider))->toBeFalse()
        ->and($team->memberRole($outsider))->toBeNull()
        ->and($team->members()->pluck('id')->all())->toBe([$member->getKey()]);

    // Idempotent — a second assign updates the seat rather than adding one.
    $team->assignMember($member, Role::Admin);

    expect($team->memberRole($member))->toBe(Role::Admin)
        ->and(DB::table('host_team_users')->count())->toBe(1);

    // `removeMember()` soft-marks, because the host declares a removal column: the row survives and
    // the seat stops counting. A hard detach here would destroy the host's own audit trail.
    $team->removeMember($member);

    expect($team->fresh()->hasMember($member))->toBeFalse()
        ->and($team->fresh()->members())->toHaveCount(0)
        ->and(DB::table('host_team_users')->whereNotNull('removed_at')->count())->toBe(1);
});

it('streams a foreign-pivot team through MembershipSource', function () {
    ($this->bindResolver)($this->hostTeam);

    $member = User::create(['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'x']);
    $removed = User::create(['name' => 'Gone', 'email' => 'gone@example.test', 'password' => 'x']);

    $team = BeamAccounts::currentTeam();
    $team->assignMember($member, Role::Member);
    $team->assignMember($removed, Role::Member);
    $team->removeMember($removed);

    $page = app(MembershipSource::class)->records([], null, 20);

    // The regression gate for `MembershipSource::stream()`'s Relation-vs-Collection branch:
    // `HasMembers::members()` returns an already-`get()`-ed Collection, and the unconditional
    // `->get()` this branch replaced threw `ArgumentCountError` for every foreign-pivot host.
    expect(collect($page->items())->map(fn (MembershipData $m) => $m->email)->all())
        ->toBe(['ann@example.test'])
        ->and(collect($page->items())->first()->role)->toBe(Role::Member->value);
});

it('scopes the invitation list to the resolved team key', function () {
    ($this->bindResolver)($this->hostTeam);

    Invitation::create(['team_id' => 'host-team-1', 'email' => 'mine@example.test', 'role' => 'member', 'token' => 'm']);
    Invitation::create(['team_id' => 'other-team', 'email' => 'theirs@example.test', 'role' => 'member', 'token' => 't']);

    $rows = InvitationData::scope(Invitation::query())->get();

    // The silent-degradation case: `InvitationData::scope()` uses the resolved key as a WHERE
    // value, so a resolver that answers wrongly returns an EMPTY list rather than an error.
    expect($rows->pluck('email')->all())->toBe(['mine@example.test']);
});

it('resolves the manager as a shared singleton, so a bound resolver is not read off a throwaway', function () {
    // The estate's one-line testbench tripwire: an auto-resolvable class that is NOT bound returns
    // a fresh instance per call and every config-driven seam on it silently answers for nobody.
    expect(app(BeamAccountsManager::class))->toBe(app(BeamAccountsManager::class));
});

class InvokableResolverStub
{
    public static int $calls = 0;

    public function __invoke(): ?object
    {
        static::$calls++;

        return null;
    }
}
