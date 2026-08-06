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
    protected $signature = 'splicewire:beam:accounts:mint-key
        {id : The fixed token id (pin a high, unique id clear of createToken() auto-increment)}
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
            id: (int) $this->argument('id'),
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
