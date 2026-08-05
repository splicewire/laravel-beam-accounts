<?php

namespace Splicewire\Beam\Accounts\Sharing;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Teams\TeamMembers;

/**
 * The share-link lifecycle (ADR-0009, tracer 05): mint → validate → redeem → revoke. A thin
 * action over {@see ShareLink}, paralleling {@see TeamMembers}.
 * Routes / controllers / UI live in the consuming satellite (tracer 06) — this package ships
 * the primitive and its rules, not the HTTP surface.
 */
class ShareLinks
{
    /** Mint a link granting scoped access, with optional expiry + use cap. */
    public function create(
        ?Authenticatable $creator,
        string $scope,
        ?int $maxUses = null,
        ?DateTimeInterface $expiresAt = null,
    ): ShareLink {
        $key = $creator?->getAuthIdentifier();

        return ShareLink::create([
            'token' => (string) Str::uuid(),
            'scope' => $scope,
            'created_by' => $key === null ? null : (string) $key,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
        ]);
    }

    /** Resolve a token to a *valid* link, or null when unknown/expired/revoked/exhausted. */
    public function validate(string $token): ?ShareLink
    {
        $link = ShareLink::query()->where('token', $token)->first();

        return $link !== null && $link->isValid() ? $link : null;
    }

    /** Count a use against a link (call once per successful resolution). */
    public function redeem(ShareLink $link): ShareLink
    {
        $link->increment('use_count');

        return $link->refresh();
    }

    /** Revoke a link immediately (idempotent). */
    public function revoke(ShareLink $link): ShareLink
    {
        if (! $link->isRevoked()) {
            $link->forceFill(['revoked_at' => now()])->save();
        }

        return $link;
    }
}
