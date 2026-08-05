<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;

/**
 * The current-plan chip: a machine `tier` (e.g. `free`), a human `label` (e.g. "Free"), and optional
 * usage meter (`credits` of `max`). The package fixes no tier vocabulary and no credit semantics — the
 * host decides what a "credit" counts (renders, songs, seats) and feeds the numbers.
 */
class PlanData extends Data
{
    public function __construct(
        public string $tier,
        public string $label,
        public ?int $credits = null,
        public ?int $max = null,
    ) {}
}
