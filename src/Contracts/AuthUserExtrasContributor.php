<?php

namespace Splicewire\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Support\NullAuthUserExtras;

/**
 * The VALUE seam for {@see AuthUserData} (extension-seam
 * asset 07, idiom #1 — a bound port with a Null default). A host that adds fields to the
 * auth projection binds its own contributor; the base spreads whatever it returns, blind,
 * into `AuthUserData::from()`.
 *
 * The base names NO host field. It calls `contribute()` unconditionally and merges the
 * result by property NAME — so the central-vs-tenant branch for any host field (e.g.
 * commerce entitlements, an embed key) lives in the host's contributor, never here. The
 * standalone default ({@see NullAuthUserExtras}) returns
 * `[]`, so a beam-accounts site with no host binds a coherent identity-core payload with
 * the host fields simply ABSENT (not empty).
 */
interface AuthUserExtrasContributor
{
    /**
     * Extra auth-projection fields, keyed by the resolved DTO's property names. `[]` when the
     * host contributes nothing (the Null default).
     *
     * @return array<string, mixed>
     */
    public function contribute(Authenticatable $user): array;
}
