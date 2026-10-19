<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator home's figures ({@see OperatorHomePageData::$stats}): only what this package owns.
 *
 * `teams` is null where this host's team notion is not beam's `Team` (a tenanted host reaches its teams through the
 * resolver), and `pendingInvitations` is null where the invitations table is not installed. Null means "not a figure
 * this host has", so a page leaves it out rather than printing a zero it never counted.
 */
#[TypeScript]
final class OperatorHomeStatsData extends Data
{
    public function __construct(
        public int $users,
        public ?int $teams,
        public ?int $pendingInvitations,
    ) {}
}
