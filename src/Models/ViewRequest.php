<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Splicewire\Beam\Beam;

/**
 * A polymorphic request to VIEW someone else's resource (ADR-0009, tracer 04, generalized). The
 * owner approves — minting an allow-`view` AccessGrant through the cascade — or declines. One
 * PENDING request per (requester, requestable); a re-request is a gentle no-op. `requestable` is
 * any HasVisibility model; `requester` is a User. Both morph keys are strings (cross-host).
 *
 * @property string $id
 * @property string $status pending | approved | declined
 * @property Carbon|null $decided_at
 */
class ViewRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** `view_requests` → `beam_view_requests`, via the single table-prefix seam {@see Beam::table()}. */
    public function getTable(): string
    {
        return Beam::table('view_requests');
    }

    public function requestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): MorphTo
    {
        return $this->morphTo();
    }
}
