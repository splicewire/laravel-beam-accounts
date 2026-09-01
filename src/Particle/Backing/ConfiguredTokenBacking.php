<?php

namespace Splicewire\Beam\Accounts\Particle\Backing;

use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Particle\Backing\EloquentBacking;

/**
 * The `tokens` resource's backing: an ordinary Eloquent backing whose MODEL CLASS is the host's, read
 * from `beam.accounts.tokens.model` at RESOLVE time.
 *
 * The exact twin of {@see ConfiguredUserBacking} — read that class for the three-freeze-points table
 * and why a `ResourceBacking` class-string is the only form late enough to follow a host's config. The
 * short version: `#[ParticleResource]` arguments must be constant expressions, so
 * `backing: BeamAccounts::tokenModel()` will not compile, and freezing
 * {@see \Splicewire\Beam\Accounts\Models\PersonalAccessToken} into the attribute is what forced every
 * host with a bespoke PAT model to restate the whole `tokens` manifest imperatively.
 *
 * ## Why the token resource in particular needed it
 *
 * `TokenData`'s docblock used to instruct a host with a bespoke PAT model to **subclass this DTO and
 * re-declare the attribute**. Measured 2026-09-01 at `~/Herd/splicewire-app`, nobody did: the host
 * restated the entire declaration inline instead, naming
 * `Splicewire\Tower\Models\PersonalAccessToken` (uuid key, `central` connection) — and that inline
 * restatement is precisely the shadowing the particle-manifest-repatriation map exists to retire. With
 * the model following config, the host sets one config key and deletes 30 lines of manifest.
 *
 * ## This does not move the security boundary
 *
 * The row-level scope is {@see \Splicewire\Beam\Accounts\Data\TokenData::scope()}, config-seamed
 * separately at `beam.accounts.tokens.scope`, and is applied to the list AND the revoke-by-id subject
 * resolution alike. This class decides only WHICH TABLE/CLASS is queried, never which rows of it.
 *
 * @see \Splicewire\Beam\Accounts\BeamAccountsManager::tokenModel()  the two-step fallback this reads
 */
class ConfiguredTokenBacking extends EloquentBacking
{
    public function __construct()
    {
        parent::__construct(BeamAccounts::tokenModel());
    }
}
