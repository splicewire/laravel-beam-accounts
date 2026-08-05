<?php

namespace Splicewire\Beam\Accounts\Sharing;

use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Models\ViewRequest;

/**
 * The polymorphic view-request approve flow (ADR-0009, tracer 04, generalized): a requester asks
 * to view any HasVisibility resource; the owner approves — minting an allow-`view` grant via
 * {@see AccessGrants} — or declines. One PENDING request per (requester, requestable); a
 * re-request while one is open returns the existing row. Owner-vs-requester gating is enforced by
 * the caller (the request operation's ability gate), so this service stays model-agnostic.
 */
class ViewRequests
{
    public function __construct(private AccessGrants $grants) {}

    public function request(Model $requestable, Model $requester): ViewRequest
    {
        return ViewRequest::query()->firstOrCreate([
            'requestable_type' => $requestable->getMorphClass(),
            'requestable_id' => (string) $requestable->getKey(),
            'requester_type' => $requester->getMorphClass(),
            'requester_id' => (string) $requester->getKey(),
            'status' => ViewRequest::STATUS_PENDING,
        ]);
    }

    /** Approve a pending request — mints an allow-`view` grant for the requester, then stamps the row. */
    public function approve(ViewRequest $request): ViewRequest
    {
        if ($request->status === ViewRequest::STATUS_PENDING) {
            // Mint from the stored morph strings — no morphTo reload (a string morph id vs a
            // bigint PK errors on a strict driver).
            $this->grants->shareRaw(
                $request->requestable_type, $request->requestable_id,
                $request->requester_type, $request->requester_id,
                AccessGrant::ABILITY_VIEW,
            );

            $request->forceFill([
                'status' => ViewRequest::STATUS_APPROVED,
                'decided_at' => now(),
            ])->save();
        }

        return $request;
    }

    /** Decline a pending request — stamps the row; no grant is minted. */
    public function decline(ViewRequest $request): ViewRequest
    {
        if ($request->status === ViewRequest::STATUS_PENDING) {
            $request->forceFill([
                'status' => ViewRequest::STATUS_DECLINED,
                'decided_at' => now(),
            ])->save();
        }

        return $request;
    }
}
