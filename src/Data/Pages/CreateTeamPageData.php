<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The props of the `account/create-team` Inertia page (`teams.create`).
 *
 * `action` is the create operation's URL, read off the router by name rather than written into the
 * page, so a host that mounts the team routes under a prefix does not have to re-spell it client-side.
 */
#[TypeScript]
final class CreateTeamPageData extends Data
{
    public function __construct(
        public string $action,
        public ?string $cancelUrl,
    ) {}
}
