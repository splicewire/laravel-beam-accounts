<?php

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Agreement presentation and, when blocking, the exact member the current Requirement expects. */
#[TypeScript]
final class AgreementPromptData extends Data
{
    public function __construct(
        public string $key,
        public string|Optional $readUrl,
        public bool $required,
        public int|Optional $version,
        public string|Optional $digest,
        public string|Optional $kind = new Optional,
        public string|Optional $title = new Optional,
        public string|Optional $label = new Optional,
        public string|Optional $effectiveAt = new Optional,
        public string|null|Optional $summary = new Optional,
        public string|Optional $content = new Optional,
        public string|null|Optional $legalUrl = new Optional,
    ) {}
}
