<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;

/**
 * One headline profile metric — a `label` and its already-formatted `value` (the host formats
 * "12.4k"; the shell only renders the string).
 */
class MetricData extends Data
{
    public function __construct(
        public string $label,
        public string $value,
    ) {}
}
