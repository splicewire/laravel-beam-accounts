<?php

namespace Splicewire\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where a user with a tenant SEAT lands (ux-walkthrough IA-5, rule 2), or null when they hold none. A host whose tenant
 * is named by the URL (one host per tenant) answers with the place that picks among them; a host with one tenant
 * answers with its tenant home. The answer only picks the redirect target: the URL still identifies the tenant (OQ-4).
 */
interface LandingSeats
{
    public function homeFor(Authenticatable $user): ?string;
}
