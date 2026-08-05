<?php

use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Accounts\Sharing\AccessGrants;
use Splicewire\Beam\Accounts\Sharing\ViewRequests;
use Splicewire\Beam\Accounts\Tests\Fixtures\Shareable;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * Tracer 04 generalized — the model-agnostic sharing services (AccessGrants + polymorphic
 * ViewRequests) that back Sharing::attachTo. Any HasVisibility grantable, any User grantee.
 */
beforeEach(function () {
    $this->grants = app(AccessGrants::class);
    $this->requests = app(ViewRequests::class);
    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
    $this->other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);
    $this->thing = Shareable::create(['user_id' => $this->owner->id, 'visibility' => 'private']);
});

function grantCount($grantable, $grantee): int
{
    $model = config('permission-cascade.grant_model');

    return $model::query()
        ->where('grantable_id', (string) $grantable->getKey())
        ->where('grantee_id', (string) $grantee->getKey())
        ->count();
}

it('shares then revokes an access grant for any grantable (idempotent)', function () {
    $this->grants->share($this->thing, $this->other, AccessGrant::ABILITY_VIEW);
    $this->grants->share($this->thing, $this->other, AccessGrant::ABILITY_VIEW); // idempotent
    expect(grantCount($this->thing, $this->other))->toBe(1);

    $this->grants->revoke($this->thing, $this->other);
    expect(grantCount($this->thing, $this->other))->toBe(0);
});

it('opens a polymorphic pending request and no-ops on re-request', function () {
    $a = $this->requests->request($this->thing, $this->other);
    $b = $this->requests->request($this->thing, $this->other);

    expect($a->status)->toBe(ViewRequest::STATUS_PENDING)
        ->and($b->id)->toBe($a->id)
        ->and(ViewRequest::query()->count())->toBe(1);
});

it('mints an allow-view grant when a request is approved', function () {
    $request = $this->requests->request($this->thing, $this->other);
    expect(grantCount($this->thing, $this->other))->toBe(0);

    $this->requests->approve($request);

    expect($request->fresh()->status)->toBe(ViewRequest::STATUS_APPROVED)
        ->and($request->fresh()->decided_at)->not->toBeNull()
        ->and(grantCount($this->thing, $this->other))->toBe(1);
});

it('mints no grant when a request is declined', function () {
    $request = $this->requests->request($this->thing, $this->other);

    $this->requests->decline($request);

    expect($request->fresh()->status)->toBe(ViewRequest::STATUS_DECLINED)
        ->and(grantCount($this->thing, $this->other))->toBe(0);
});
