<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** An account refusal: its original message plus an additive, language-independent code. */
#[TypeScript]
class AccountErrorData extends BeamData
{
    public function __construct(
        #[Description('The original human-readable refusal, in the request language.')]
        public string $message,
        #[Description('The stable, language-independent account refusal code.')]
        public string $code,
    ) {}
}
