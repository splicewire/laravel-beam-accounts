<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\User;
use Splicewire\Beam\Accounts\QueryBuilders\SignedLoginAsSubject;
use Splicewire\Beam\Accounts\QueryBuilders\UsersQuery;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The users-admin LIST + DETAIL resource — the identity roster of a beam host.
 *
 * The domain-neutral answer to "who has an account here". Distinct from the `members` resource,
 * which is the seat list of ONE team (the team-membership pivot, role + joinedAt): this is the
 * principal list, spanning every team the actor can see, and it reads the user table itself.
 *
 * WRITE SURFACE: **update only**, via ADR-0156 §83's edit-independent widening — `readOnly: true`
 * (which is what projects `creatable: false`) PLUS an explicit `editable: true`. Read the pair
 * together: `readOnly` here means "not created or deleted through the generic pipeline", not "not
 * written at all".
 *
 * Not creatable — but NOT because a create is inexpressible here. It plainly is: {@see InvitationData}
 * in this same package mints a token and authorizes inside `prepare()`, and an `afterWrite()` hook
 * exists for exactly the seed-the-personal-team half. The real reason is that REGISTRATION is a
 * PUBLIC, UNAUTHENTICATED flow owned by Fortify, while a particle create is authenticated and
 * policy-gated — two surfaces with different gates, not one surface written twice.
 *
 * That argument does NOT cover "an operator provisions a user", which is a genuinely different act
 * from self-registration and would be a legitimate `creatable` with a `prepare()`/`afterWrite()` pair.
 * It is left closed here because nothing has asked for it, not because the pipeline can't carry it.
 *
 * Not deletable, because deleting a principal is destructive and cascade-bearing and wants its own
 * password-confirmed flow. That one stays a host REST survivor, as {@see TeamData} argues for teams.
 *
 * `editable` IS open, and that is the change: this resource used to be flatly read-only, which meant
 * the self-service profile edit had to live as an entirely parallel non-particle surface
 * (`routes/account.php` → `ProfileController@update`) writing the very model this resource declares.
 * Editing a user is now the declared thing it always was, with {@see ProfileUpdateInputData} as the
 * `input:` — the same DTO the Inertia and API transports already validate against, so one shape
 * feeds all three and the codegen chain finally sees the write.
 *
 * The gate is {@see UserPolicy}: **self, or central Root** — deliberately NARROWER than the read
 * boundary, because a principal may legitimately see every peer on their teams and must not be able
 * to edit them. The per-record DETAIL read stays open (`showable` at its default) — it is what an
 * operator opens to diagnose an access problem.
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
    backing: User::class,
    label: 'Users',
    group: 'Settings',
    icon: 'users',
    form: 'bare',
    input: ProfileUpdateInputData::class,
    editData: ProfileUpdateInputData::class,
    policy: UserPolicy::class,
    filterable: false,
    readOnly: true,
    editable: true,
    deletable: false,
)]
#[TypeScript]
class UserData extends BeamData
{
    public function __construct(
        #[NotInList]
        #[Description('The user id.')]
        public string $id,
        #[Column(label: 'Name', sort: 0)]
        #[Description('Display name; null until the user sets one.')]
        public ?string $name,
        #[Column(label: 'Email', sort: 1)]
        #[Description('Email address, unique across the identity table.')]
        public string $email,
        /** @var string[] */
        #[Column(label: 'Roles', sort: 2)]
        #[Description('Role names held on the current permissions team, not the resolved permission set.')]
        public array $roles,
        #[Column(label: 'Joined', sort: 3)]
        #[Sortable(default: true, direction: 'desc')]
        #[Description('When the account was created, ISO-8601. Newest first is the default order.')]
        public ?string $createdAt,
        // Detail-only: the resolved permission set is what an operator opens a record to read, and
        // it is too wide (and too many queries) to project across a list.
        /** @var string[] */
        #[NotInList]
        #[Description('The resolved permission set. Detail-only — too wide to project across a list.')]
        public array $permissions,
        #[NotInList]
        #[Description('Whether the user holds the CENTRAL Root role, checked on the null team.')]
        public bool $isRoot,
    ) {}

    /**
     * The load-bearing isolation boundary, applied on BOTH the list and the per-record path.
     * Defaults to the shared-team roster; a host overrides with a config seam.
     *
     * The one branch ahead of both is {@see SignedLoginAsSubject}: a request carrying the server's
     * own signature over `users/{id}/op/login-as` resolves exactly that `{id}` and nothing else.
     * Read that class before touching this — it is a signed-credential question, not a visibility
     * one, and it is what makes a signed login link able to find the subject it exists to become
     * (beam-facade 172). Every other request, guest included, falls through unchanged.
     */
    public static function scope(Builder $query): Builder
    {
        if (($signedSubject = SignedLoginAsSubject::id()) !== null) {
            return $query->whereKey($signedSubject);
        }

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
