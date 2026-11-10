<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * One concern of {@see BeamAccountsServiceProvider}: this package's contribution to the Analytics section, the
 * **Audience** area (analytics-area ticket 11).
 *
 * ## Why nothing here probes whether laravel-beam-analytics is installed
 *
 * This package does not require laravel-beam-analytics (it is a require-dev and a suggest only, charter ruling (A)).
 * The contribution therefore names the analytics registry, and the area class, as STRINGS: a container callback keyed by
 * a name nothing binds is never called, and a string is never autoloaded. On a host without the analytics package this
 * adds one inert callback and loads no class of either package; with it, the area joins the registry's other areas.
 *
 * ## …and why this runs in the register phase
 *
 * laravel-beam-analytics resolves its area registry while it BOOTS (to register each area's resource), so a callback
 * added from this package's boot could arrive after that, depending on provider order. Every provider registers before any
 * boots. `callAfterResolving` also covers a registry already resolved by then.
 */
trait WiresAnalyticsArea
{
    /** The analytics area registry's container name. */
    public const ANALYTICS_AREA_REGISTRY = 'Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry';

    /** The Audience area's class, named as a string so this file never autoloads it. */
    public const AUDIENCE_AREA = 'Splicewire\Beam\Accounts\Analytics\AudienceArea';

    protected function registerAnalyticsArea(): void
    {
        $this->callAfterResolving(self::ANALYTICS_AREA_REGISTRY, fn ($areas) => $areas
            ->add(self::AUDIENCE_AREA, 'splicewire/laravel-beam-accounts'));
    }
}
