<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;

/**
 * The public/social profile card: a `handle`, optional `avatar` (URL or initials — the shell renders
 * whatever the host supplies), and a generic list of headline `metrics`. The metric SEMANTICS are the
 * host's product content — beam-accounts never names "songs"/"plays"/"followers"; it just carries the
 * label/value pairs the host feeds.
 */
class ProfileData extends Data
{
    public function __construct(
        public string $handle,
        public ?string $avatar = null,
        /** @var MetricData[] */
        public array $metrics = [],
    ) {}
}
