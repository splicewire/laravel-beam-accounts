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
use Splicewire\Beam\Accounts\Particle\Backing\ConfiguredTokenBacking;
use Splicewire\Beam\Accounts\QueryBuilders\TokensQuery;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The API-tokens LIST + REVOKE resource (Frame OS ticket 20 — promoted from tower's
 * `Splicewire\Tower\Data\Frame\TokenResourceData`, now domain-neutral in beam-accounts).
 *
 * List (name / created / last-used) + a `revoke` row action. CREATE stays a HOST escape
 * hatch — Frame's generic create has no notion of a create-response carrying a display-once
 * secret, and that reveal-once dialog is genuine security UX Frame should not generate. So
 * this resource is list + delete only (`readOnly: true` ⇒ show/store/update 405, while
 * `deletable: true` keeps the Frame destroy → revoke via the delete-independent widening).
 * The reveal-once create + rotate/renew lifecycle stay a host REST survivor.
 *
 * SECURITY-CRITICAL isolation — the PAT table is shared across every user — rides two paired
 * boundaries that resolve the SAME scope: the list rides {@see self::scope()} and the Frame
 * revoke-by-id rides the same closure (Frame carries the resource `scope` onto its destroy
 * subject-resolution), so a destroy can never resolve another user's token. The default scope
 * is "the authenticated user's OWN tokens" ({@see TokensQuery::scopeToOwner()}); a host whose
 * token owner resolves differently binds `beam.accounts.tokens.scope` (an
 * `(Builder, ?Authenticatable): Builder` callable) and the model via `beam.accounts.tokens.model`.
 *
 * The MODEL follows configuration too, and it did not always: `backing:` names
 * {@see ConfiguredTokenBacking}, a `ResourceBacking` that resolves `beam.accounts.tokens.model` at
 * REQUEST time. This paragraph used to instruct a host with a bespoke PAT model to *subclass this DTO
 * and re-declare the attribute* — measured 2026-09-01, no host in the estate did that, and the one host
 * with a bespoke PAT (`~/Herd/splicewire-app`, tower's uuid-keyed central model) restated the entire
 * manifest imperatively instead. A resource that can only be host-fitted by being re-declared will be
 * re-declared, and a restated manifest is what drifts.
 *
 * ⚠️ A host that still wants a whole different DECLARATION (not just a different model or scope) must
 * register it from its OWN provider's `boot()`. Listing a replacement class in
 * `beam.core.resources.classes` does NOT work: that list is registered FIRST by
 * `Splicewire\Beam\BeamServiceProvider::discoverResources()`, and beam's own attributed classes are
 * registered after it and displace it under `OnDuplicate::Supersede`. Measured 2026-08-28 on the
 * `users` resource at `~/Herd/splicewire`, whose listed override is displaced;
 * {@see \Splicewire\Beam\Accounts\Data\UserData} carries that measurement.
 *
 * ✅ This DTO is now the sole `tokens` declaration in the estate (particle-manifest-repatriation 06).
 * `splicewire/tower`'s `Data\Frame\TokenResourceData` — the ancestor this class was promoted from — is
 * deleted, and `~/Herd/splicewire-app`'s inline re-registration is retired onto
 * `beam.accounts.tokens.{model,scope}` plus a `frame.realm_resource_overrides` presentation overlay.
 * `superseded('tokens')` is empty at both hosts. The tower fossil was worth deleting on its own
 * account: it declared no `scope()` convention method at all, so had tower ever scanned
 * `src/Data/Frame` the winning `tokens` resource would have carried **no row-level boundary** on a
 * table shared by every user.
 */
#[ParticleResource(
    key: 'tokens',
    backing: ConfiguredTokenBacking::class,
    label: 'API tokens',
    group: 'Settings',
    icon: 'key',
    form: 'bare',
    readOnly: true,
    deletable: true,
    // No per-record detail. Every field this DTO carries is already a list column except `id`, which is
    // the revoke handle rather than a fact worth reading, so `records/{id}` would answer a copy of the
    // row the caller already has — on a table shared by every user in the estate. Closing it is the
    // conservative direction: a host that wants the detail widens by re-declaring; a host that does not
    // cannot un-mount one. (Measured 2026-09-01: `~/Herd/splicewire-app` had already closed it, in the
    // inline manifest that particle-manifest-repatriation 06 retired, so this is the host fact
    // descending rather than a new opinion.)
    showable: false,
)]
#[TypeScript]
class TokenData extends BeamData
{
    public function __construct(
        #[NotInList]
        #[Description('Opaque token id. Use it to revoke; it is not the token secret.')]
        public string $id,
        #[Column(label: 'Name', sort: 0)]
        #[Description('The label you gave the token when you created it.')]
        public string $name,
        #[Column(label: 'Created', sort: 1)]
        #[Sortable(default: true, direction: 'desc')]
        #[Description('When the token was issued, ISO-8601. Newest first is the default order.')]
        public ?string $createdAt,
        #[Column(label: 'Last used', sort: 2)]
        #[Description('When the token last authenticated a request, ISO-8601; null if it never has.')]
        public ?string $lastUsedAt,
    ) {}

    /**
     * The load-bearing isolation boundary, applied on BOTH the list and the revoke path. Defaults
     * to the authenticated principal's own tokens; a host overrides with a config seam.
     */
    public static function scope(Builder $query): Builder
    {
        $seam = config('beam.accounts.tokens.scope');

        if (is_callable($seam)) {
            return $seam($query, Auth::user());
        }

        return TokensQuery::scopeToOwner($query, Auth::user());
    }

    /**
     * Project a Sanctum PAT model into the Frame list row (ISO-8601 timestamps, string id) — so a
     * plain `Data::from($token)` doesn't hand back raw Carbon.
     */
    public static function project(Model $token): self
    {
        return new self(
            id: (string) $token->getKey(),
            name: $token->name,
            createdAt: $token->created_at?->toIso8601String(),
            lastUsedAt: $token->last_used_at?->toIso8601String(),
        );
    }
}
