<?php

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Agreement read coordinates and, when blocking, the exact member the current Requirement expects. */
#[TypeScript]
final class AgreementPromptData extends Data
{
    public function __construct(
        public string $key,
        public string $readUrl,
        public bool $required,
        public int|Optional $version,
        public string|Optional $digest,
    ) {}
}
