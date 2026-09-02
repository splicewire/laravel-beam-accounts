<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Facades\Beam;

/**
 * The reference implementation of {@see TeamContract} — a single-DB team over beam's
 * own `beam_memberships` table. Behavior is unchanged from before the contract was
 * introduced; the interface just names the surface the account runtime already used.
 */
class Team extends Model implements TeamContract
{
    use HasSlug;

    protected $guarded = [];

    protected $casts = [
        'personal_team' => 'boolean',
    ];

    /**
     * `teams` → `beam_teams`, routed through the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename ticket 04). A property default cannot call config(), so the prefix is
     * applied here.
     */
    public function getTable(): string
    {
        return Beam::table('teams');
    }

    /**
     * The team's stable, globally-unique public name — what a `to_teams:` selector in an
     * `x-beam-notify` keyword actually names (beam-facade 100 D5, built by 159).
     *
     * A slug rather than the key, because that selector is authored by hand into a JSON Schema that
     * travels between hosts, where an auto-increment id means nothing. Globally unique rather than
     * unique-per-owner for the same reason: the selector carries no owner, so the name has to be
     * sufficient on its own. spatie's uniqueness suffix (`-1`, `-2`) resolves collisions.
     *
     * **Not regenerated on update.** Renaming a team must not silently repoint every schema that
     * notifies it — the slug is an address, and an address that follows a display name is not one.
     * A deliberate re-address is an explicit write to the column.
     *
     * Personal teams derive from the OWNER, not from the name: `personalTeamName()` renders
     * "{name}'s Team" for everybody, so a name-derived slug would collide for every user sharing a
     * first name and degrade into `adas-team-7`. The owner handle is both unique-ish and meaningful.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(fn (self $team): string => $team->slugSource())
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    /**
     * The slug is written on INSERT, not on the `creating` event alone.
     *
     * `HasSlug`'s only writer is a `static::creating` listener, and Laravel's skeleton `DatabaseSeeder`
     * mutes every model event for the whole seed run (`WithoutModelEvents`) — so `migrate:fresh --seed`
     * at any host provisioning a team died on `beam_teams.slug` NOT NULL, through `TeamProvisioner` and
     * `DemoTeamSeeder` alike (theme-entries-and-authoring 02; measured at `~/Herd/numero` and
     * `laravel-beam-starter`). A row of this shape must be complete whether or not a dispatcher is
     * listening: the slug belongs to the declared shape, not to the event. Every writer passes through
     * here, which is why the guard lives on the model and not in each caller (the per-caller placement
     * is what produced the defect). The listener stays — with events on it runs first and this is a
     * no-op; with events off this is the only writer.
     */
    protected function performInsert(Builder $query): bool
    {
        if (blank($this->getAttribute('slug'))) {
            $this->generateSlugOnCreate();
        }

        return parent::performInsert($query);
    }

    /**
     * What the slug is derived from — the owner's handle for a personal team, the team's own name
     * otherwise.
     *
     * Reads the owner through the relation rather than off a loaded model, because the slug is
     * generated on the `creating` event, before anything has had a chance to load one. A team whose
     * owner cannot be read falls back to the name, which is always present.
     */
    protected function slugSource(): string
    {
        if (! $this->personal_team) {
            return (string) $this->name;
        }

        $owner = $this->owner()->first();

        $handle = $owner?->getAttribute('email') !== null
            ? strstr((string) $owner->getAttribute('email'), '@', true)
            : $owner?->getAttribute('name');

        return (string) ($handle ?: $this->name);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(BeamAccounts::userModel(), 'user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function members()
    {
        return $this->belongsToMany(BeamAccounts::userModel(), Beam::table('memberships'))
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    // --- TeamContract ------------------------------------------------------

    public function teamKey(): int|string
    {
        return $this->getKey();
    }

    public function hasMember(Authenticatable $user): bool
    {
        return $this->memberships()->where('user_id', $user->getKey())->exists();
    }

    public function memberRole(Authenticatable $user): ?Role
    {
        $value = $this->memberships()
            ->where('user_id', $user->getKey())
            ->value('role');

        // `tryFrom` for the same reason, and at the same cost, as
        // {@see \Splicewire\Beam\Accounts\Concerns\HasMembers::memberRole()} — read that note; it is
        // the long-form one. Beam's own `memberships.role` is a plain string column too, so this
        // reader is equally at the mercy of what a seeder wrote, and the signature was already
        // `?Role` so containing here costs no interface change. `Membership::memberRole()` was the
        // one sibling left unconverged — `MembershipContract` declared it `: Role`, so widening it
        // was a published-interface change and out of that change's scope. It is converged now; the
        // contract reads `?Role`, which implementers accept unchanged by return-type covariance.
        return $value !== null ? Role::tryFrom($value) : null;
    }

    public function assignMember(Authenticatable $user, Role $role): void
    {
        Membership::updateOrCreate(
            ['team_id' => $this->getKey(), 'user_id' => $user->getKey()],
            ['role' => $role->value],
        );
    }

    public function removeMember(Authenticatable $user): void
    {
        $this->memberships()->where('user_id', $user->getKey())->delete();
    }
}
