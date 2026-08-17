<?php

use Rushing\PermissionCascade\Contracts\VisibilityRecord;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Splicewire\Beam\Accounts\Models\Visibility;
use Splicewire\Beam\Accounts\Tests\Fixtures\Shareable;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The off-table visibility seam: beam-accounts ships {@see Visibility} (permission-cascade's
 * {@see VisibilityRecord}) + the `beam_visibilities`
 * migration, but — UNLIKE grant_model/entitlement_resolver — never defaults
 * `permission-cascade.visibility_model` on. Shelf/Silo/RunnerTransform/ConversationParticle and
 * audiostud's own Composition/AudioSample/LyricPiece already read a real `visibility` column;
 * defaulting this on fleet-wide would silently redirect all of them to an empty morph table.
 */
it('does not default the visibility model — a host must opt in', function () {
    expect(config('permission-cascade.visibility_model'))->toBeNull();
});

it('lets a host that opts in resolve tiers off the shipped Visibility model', function () {
    config(['permission-cascade.visibility_model' => Visibility::class]);

    $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test']);
    $actor = User::create(['name' => 'Actor', 'email' => 'actor@example.test']);
    $thing = Shareable::create(['user_id' => $owner->id]); // no column value — morph-only

    Visibility::create([
        'reachable_type' => $thing->getMorphClass(),
        'reachable_id' => $thing->getKey(),
        'tier' => 'tenant',
    ]);

    $policy = new class extends BaseModelPolicy
    {
        public static $defaultModelClass = Shareable::class;
    };

    expect($policy->update($owner, $thing->fresh()))->toBeTrue();
    expect($policy->view($actor, $thing->fresh()))->toBeTrue();
    expect($policy->update($actor, $thing->fresh()))->toBeFalse();
});
