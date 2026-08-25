<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Accounts\Keys\DeterministicToken;

/**
 * Host-facing affordance for the key-management module (gated on
 * `beam.accounts.keys.enabled`): mint a deterministic, reset-surviving key from the
 * CLI. Given the same (id, plaintext) it prints the SAME bearer every run — so a satellite
 * and the engine can each mint the credential they share without a central authority.
 *
 * The reproducible primitive it drives (Keys\DeterministicToken) is always available in
 * PHP; this command is only the console door onto it, which is why it is config-gated.
 */
class MintKeyCommand extends Command
{
    // Every {…} definition must fit on ONE line: Laravel's signature parser matches them with
    // `/\{\s*(.*?)\s*\}/`, a regex with no `s` modifier, so `.` never crosses a newline. A
    // definition wrapped across lines is not a parse error — it is silently skipped, and the
    // argument simply does not exist at runtime. That is exactly what happened to {id}: the
    // command shipped with no way to pass the value the whole primitive is pinned on.
    protected $signature = 'splicewire:beam:accounts:mint-key
        {id : The fixed token id — an int on a bigint-keyed host (pin one clear of createToken() auto-increment), or a uuid on a uuid-keyed one (pin it deterministically, e.g. uuid5 over the key name, so a re-mint reproduces it)}
        {plaintext : The fixed plaintext (the part after "id|")}
        {--tokenable-id= : The owning model id}
        {--tokenable-type=user : The owning model morph type/alias}
        {--name=host-key : A label for the token row}
        {--ability=* : Abilities to grant (repeatable); defaults to * }';

    protected $description = 'Mint a deterministic, reset-surviving key for this host (per-host, never cross-host).';

    public function handle(): int
    {
        $abilities = (array) $this->option('ability');

        $token = new DeterministicToken(
            // NOT cast to int (beam-docs-satellite ticket 25). `DeterministicToken` is typed
            // `int|string` and folds the value verbatim into the bearer string, so a uuid-keyed host —
            // which is every splicewire-operated host — needs its uuid to survive this line. The cast
            // silently turned any uuid into `0`, which on Postgres is then an invalid uuid literal.
            // Non-lossy for bigint hosts: a numeric-string argument stays numeric either way.
            id: $this->argument('id'),
            plaintext: (string) $this->argument('plaintext'),
            tokenableType: (string) $this->option('tokenable-type'),
            tokenableId: $this->option('tokenable-id') ?? '',
            name: (string) $this->option('name'),
            abilities: $abilities === [] ? ['*'] : $abilities,
            table: config('beam.accounts.keys.table', 'personal_access_tokens'),
        );

        $bearer = $token->mint();

        $this->info('Minted deterministic key (idempotent — re-running yields the same value):');
        $this->line($bearer);

        return self::SUCCESS;
    }
}
