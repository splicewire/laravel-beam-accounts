<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SecurityPasskeyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $authenticator,
        #[MapName('createdAtDiff')]
        public string $createdAtDiff,
        #[MapName('lastUsedAtDiff')]
        public ?string $lastUsedAtDiff,
    ) {}
}
