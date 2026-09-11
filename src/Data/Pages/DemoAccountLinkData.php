<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DemoAccountLinkData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
    ) {}
}
