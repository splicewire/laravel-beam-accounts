<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PageEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $slug,
    ) {}
}
