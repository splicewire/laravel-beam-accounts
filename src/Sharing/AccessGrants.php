<?php

namespace Splicewire\Beam\Accounts\Sharing;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Rushing\PermissionCascade\Contracts\AccessGrant;

/**
 * Model-agnostic sharing over the permission-cascade AccessGrant ledger (ADR-0009): mint/deny/
 * revoke deny-capable grants for ANY grantable (a HasVisibility model) × grantee (a User/Role).
 * The cascade policy resolves the grants; this only writes them. The concrete grant model is the
 * host's `config('permission-cascade.grant_model')` (the OOTB beam-accounts AccessGrant by default).
 */
class AccessGrants
{
    public function share(Model $grantable, Model $grantee, string $ability = AccessGrant::ABILITY_VIEW): Model
    {
        return $this->mint($grantable->getMorphClass(), $grantable->getKey(), $grantee->getMorphClass(), $grantee->getKey(), $ability, AccessGrant::EFFECT_ALLOW);
    }

    public function deny(Model $grantable, Model $grantee, string $ability = AccessGrant::ABILITY_VIEW): Model
    {
        return $this->mint($grantable->getMorphClass(), $grantable->getKey(), $grantee->getMorphClass(), $grantee->getKey(), $ability, AccessGrant::EFFECT_DENY);
    }

    /**
     * Mint an allow-grant from raw morph components — used where only the stored
     * type/id strings are on hand (e.g. approving a ViewRequest), avoiding a morphTo
     * reload that would bind a string morph id against a bigint PK on a strict driver.
     */
    public function shareRaw(string $grantableType, string|int $grantableId, string $granteeType, string|int $granteeId, string $ability = AccessGrant::ABILITY_VIEW): Model
    {
        return $this->mint($grantableType, $grantableId, $granteeType, $granteeId, $ability, AccessGrant::EFFECT_ALLOW);
    }

    public function revoke(Model $grantable, Model $grantee, ?string $ability = null): int
    {
        $query = $this->query($grantable, $grantee);

        if ($ability !== null) {
            $query->where('ability', $ability);
        }

        return $query->delete();
    }

    private function mint(string $grantableType, string|int $grantableId, string $granteeType, string|int $granteeId, string $ability, string $effect): Model
    {
        return $this->model()::query()->firstOrCreate([
            'grantable_type' => $grantableType,
            'grantable_id' => (string) $grantableId,
            'grantee_type' => $granteeType,
            'grantee_id' => (string) $granteeId,
            'ability' => $ability,
            'effect' => $effect,
        ]);
    }

    private function query(Model $grantable, Model $grantee)
    {
        return $this->model()::query()
            ->where('grantable_type', $grantable->getMorphClass())
            ->where('grantable_id', (string) $grantable->getKey())
            ->where('grantee_type', $grantee->getMorphClass())
            ->where('grantee_id', (string) $grantee->getKey());
    }

    /** @return class-string<Model> */
    private function model(): string
    {
        $model = config('permission-cascade.grant_model');

        if ($model === null) {
            throw new LogicException('permission-cascade.grant_model is not configured; cannot write access grants.');
        }

        return $model;
    }
}
