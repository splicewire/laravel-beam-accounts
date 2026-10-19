<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One operator page the home links to ({@see OperatorHomePageData::$areas}): its label and its path at this host. */
#[TypeScript]
final class OperatorHomeAreaData extends Data
{
    public function __construct(
        public string $label,
        public string $href,
    ) {}
}
