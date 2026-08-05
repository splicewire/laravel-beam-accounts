<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;

/**
 * One upsell CTA: a stable `key` the host keys behavior off, a human `label`, and an optional `href`.
 * The COPY ("Own a song", "Go Songwriter", the price) is host product content — the package carries
 * the slot, never the words.
 */
class UpsellData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $href = null,
    ) {}
}
