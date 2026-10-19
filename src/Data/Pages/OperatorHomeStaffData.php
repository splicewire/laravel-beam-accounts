<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Who is signed in to the operator home ({@see OperatorHomePageData::$staff}). */
#[TypeScript]
final class OperatorHomeStaffData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
