<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `output:` of `users.demo-search` ({@see \Splicewire\Beam\Accounts\Ops\SearchDemoUsers}).
 */
#[TypeScript]
final class SearchDemoUsersOutputData extends BeamData
{
    /**
     * @param  list<DemoUserLinkData>  $users
     */
    public function __construct(
        #[Description('The matching users, ordered by name, at most DemoLoginLinks::USER_SEARCH_LIMIT of them.'), DataCollectionOf(DemoUserLinkData::class)]
        public array $users,
    ) {}
}
