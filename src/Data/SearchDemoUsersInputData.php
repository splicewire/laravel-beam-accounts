<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `input:` of `users.demo-search` ({@see \Splicewire\Beam\Accounts\Ops\SearchDemoUsers}): the
 * typeahead's text. Absent or empty is the roster (the first users by name).
 */
#[TypeScript]
final class SearchDemoUsersInputData extends BeamData
{
    public function __construct(
        #[Description('Text matched against each user\'s name or email, case-insensitively. Omit it for the first users by name.'), Max(100)]
        public string|Optional|null $q = new Optional,
    ) {}
}
