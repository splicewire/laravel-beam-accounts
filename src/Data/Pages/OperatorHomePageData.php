<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Support\OperatorHome;

/**
 * The props of the `/operator` home (`operator.home`): the package's server-rendered operator landing, and the
 * `operator/dashboard` Inertia page a host ejects into. Built by {@see OperatorHome::pageFor()}.
 *
 * `staff` and `stats.users` keep the shape the published `operator/dashboard.tsx` stub has always read, so a host
 * that already ejected keeps rendering. `areas` are the operator pages this host mounts that the viewer may open.
 */
#[TypeScript]
final class OperatorHomePageData extends Data
{
    /** @param list<OperatorHomeAreaData> $areas */
    public function __construct(
        public OperatorHomeStaffData $staff,
        public OperatorHomeStatsData $stats,
        #[DataCollectionOf(OperatorHomeAreaData::class)]
        public array $areas,
    ) {}
}
