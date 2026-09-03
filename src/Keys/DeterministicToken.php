<?php

namespace Splicewire\Beam\Accounts\Keys;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A deterministic, reset-surviving personal-access-token minter — the per-host key
 * primitive graduated from splicewire-app's SecondPartyTenantSeeder::ensureStableToken().
 *
 * beam operates separately from splicewire, so a beam site manages keys only for ITSELF:
 * this mints the host's OWN keys and never reaches another host's — there is no central
 * token store. The point is reproducibility WITHOUT a central authority: given the same
 * (id, plaintext) a satellite and the engine independently mint the SAME Sanctum bearer,
 * so the satellite's seeded key is one the engine already knows. Two systems share a
 * credential with no handshake because both mint deterministically, not because anyone
 * hands one out.
 *
 * Writes the row through the query builder (never a Sanctum class) so beam-accounts keeps
 * Sanctum a SUGGESTED, opt-in dependency: the table shape is Sanctum's, the coupling isn't.
 */
class DeterministicToken
{
    /**
     * @param  string  $id  The token primary key — a string (UUID) primary key. Every
     *                      splicewire-operated host keys `personal_access_tokens` by uuid, so the
     *                      `int|string` widening that carried Sanctum's default bigint column is
     *                      gone (seed-provisioning-cleanup 01). Folded verbatim into the stored row
     *                      and the `{id}|{plaintext}` bearer.
     *
     *                      ⚠️ This is a NARROWING, not a break, and the reason is worth knowing:
     *                      nothing in this package or its callers declares `strict_types`, so an
     *                      int a caller still passes (`~/Herd/numero`'s `SplicewireEngineKeySeeder`
     *                      const, `laravel-satellite`'s `MintEngineKeyCommand` default) coerces to
     *                      the identical numeric string and mints the identical bearer. Adding
     *                      `declare(strict_types=1)` to any of those files would turn that into a
     *                      TypeError — which is one more reason this estate's house style omits it.
     * @param  list<string>  $abilities
     */
    public function __construct(
        public string $id,
        public string $plaintext,
        public string $tokenableType,
        public int|string $tokenableId,
        public string $name,
        public array $abilities = ['*'],
        public string $table = 'personal_access_tokens',
    ) {}

    /**
     * The Sanctum bearer string, "{id}|{plaintext}". A pure function of (id, plaintext) —
     * same inputs yield the same bearer on every host, forever. This IS the shared credential.
     */
    public function bearer(): string
    {
        return $this->id.'|'.$this->plaintext;
    }

    /** The stored column value Sanctum compares a presented plaintext against. */
    public function hash(): string
    {
        return hash('sha256', $this->plaintext);
    }

    /**
     * Upsert the reset-surviving row keyed by the fixed id and return the bearer. Idempotent:
     * re-running never mints a second row and never changes the credential, so the value a
     * host stored (e.g. in SPLICEWIRE_TOKEN) keeps authenticating across a migrate:fresh.
     */
    public function mint(): string
    {
        $now = Carbon::now();

        DB::table($this->table)->updateOrInsert(
            ['id' => $this->id],
            [
                'tokenable_type' => $this->tokenableType,
                'tokenable_id' => $this->tokenableId,
                'name' => $this->name,
                'token' => $this->hash(),
                'abilities' => json_encode($this->abilities),
                'expires_at' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return $this->bearer();
    }
}
