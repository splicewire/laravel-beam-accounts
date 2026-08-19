<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\User;
use Splicewire\Beam\Accounts\QueryBuilders\UsersQuery;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The users-admin LIST + DETAIL resource — the identity roster of a beam host.
 *
 * The domain-neutral answer to "who has an account here". Distinct from the `members` resource,
 * which is the seat list of ONE team (the team-membership pivot, role + joinedAt): this is the
 * principal list, spanning every team the actor can see, and it reads the user table itself.
 *
 * READ-ONLY through Frame (`readOnly: true` ⇒ store/update 405, neither `deletable` nor `editable`
 * widened): users arrive by REGISTRATION and by accepting an invitation, never from an admin create
 * form — the same argument {@see TeamData} makes for teams. Frame's generic create has no notion of
 * minting credentials, dispatching a verification mail, or the personal-team seed that registration
 * owns; deletion of a principal is a destructive, cascade-bearing act that wants its own confirmed
 * flow, not a generic row delete. Both stay host REST survivors. The per-record DETAIL read stays
 * open (`showable` at its default) — it is what an operator opens to diagnose an access problem.
 *
 * SECURITY-CRITICAL isolation — the user table is the widest shared table in the package — rides the
 * {@see TokenData} discipline: list and per-record resolution share ONE scope closure
 * ({@see self::scope()}), so a detail request can never resolve a principal the list would have
 * hidden. Default: yourself plus everyone you share a team with, with a CENTRAL Root principal
 * seeing all and a guest seeing nothing ({@see UsersQuery::scopeToSharedTeams()}). A host whose
 * visibility rule differs binds `beam.accounts.users.scope`, an `(Builder, ?Authenticatable):
 * Builder` callable.
 *
 * NOTE the `model:` attribute is the package default {@see User}. Unlike every other resource here,
 * hosts ROUTINELY subclass this model as their own `App\Models\User`, so the seam is load-bearing
 * rather than theoretical — but PHP attributes cannot read config, so a host running a bespoke user
 * model subclasses this DTO and re-declares the attribute with its own class (the escape hatch
 * {@see TokenData} documents). The runtime model seam `BeamAccounts::userModel()` already resolves the host
 * class everywhere else in the package; only the attribute's literal needs the subclass.
 */
#[ParticleResource(
    key: 'users',
    model: User::class,
    label: 'Users',
    group: 'Settings',
    icon: 'users',
    form: 'bare',
    filterable: false,
    readOnly: true,
)]
#[TypeScript]
class UserData extends Data
{
    public function __construct(
        #[NotInList]
        public string $id,
        #[Column(label: 'Name', sort: 0)]
        public ?string $name,
        #[Column(label: 'Email', sort: 1)]
        public string $email,
        /** @var string[] */
        #[Column(label: 'Roles', sort: 2)]
        public array $roles,
        #[Column(label: 'Joined', sort: 3)]
        public ?string $createdAt,
        // Detail-only: the resolved permission set is what an operator opens a record to read, and
        // it is too wide (and too many queries) to project across a list.
        /** @var string[] */
        #[NotInList]
        public array $permissions,
        #[NotInList]
        public bool $isRoot,
    ) {}

    /**
     * The load-bearing isolation boundary, applied on BOTH the list and the per-record path.
     * Defaults to the shared-team roster; a host overrides with a config seam.
     */
    public static function scope(Builder $query): Builder
    {
        $seam = config('beam.accounts.users.scope');

        if (is_callable($seam)) {
            return $seam($query, Auth::user());
        }

        return UsersQuery::scopeToSharedTeams($query, Auth::user());
    }

    /**
     * Project a user model into the Frame row — ISO-8601 timestamps and a string id, so a plain
     * `Data::from($user)` doesn't hand back raw Carbon or a uuid object. Roles and permissions come
     * from spatie when the host model composes it, and degrade to empty arrays when it doesn't —
     * beam-accounts must stay usable on a principal without the roles trait.
     */
    public static function project(Model $user): self
    {
        return new self(
            id: (string) $user->getKey(),
            name: $user->name,
            email: $user->email,
            roles: method_exists($user, 'roles')
                ? $user->roles->pluck('name')->all()
                : [],
            createdAt: $user->created_at?->toIso8601String(),
            permissions: method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')->all()
                : [],
            isRoot: BeamAccounts::isRoot($user),
        );
    }
}
