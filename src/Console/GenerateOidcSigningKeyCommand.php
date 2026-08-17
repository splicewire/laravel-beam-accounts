<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Accounts\Oidc\SigningKey;

/**
 * Host-facing affordance for the OIDC-issuer module (gated on `beam.accounts.oidc.enabled`):
 * generate this host's signing keypair from the CLI. Idempotent — re-running without
 * `--force` leaves an existing key untouched, since regenerating silently would invalidate
 * every federation trust already configured against the old JWKS.
 */
class GenerateOidcSigningKeyCommand extends Command
{
    protected $signature = 'splicewire:beam:accounts:oidc:generate-signing-key
        {--force : Replace an existing key (invalidates every federation trust configured against the old JWKS)}
        {--bits=2048 : RSA key size}';

    protected $description = "Generate this host's OIDC-issuer signing key (idempotent unless --force).";

    public function handle(SigningKey $key): int
    {
        if ($key->exists() && ! $this->option('force')) {
            $this->info("An OIDC signing key already exists (kid: {$key->kid()}). Pass --force to replace it.");

            return self::SUCCESS;
        }

        $key->generate((int) $this->option('bits'), force: (bool) $this->option('force'));

        $this->info("Generated a new OIDC signing key (kid: {$key->kid()}).");
        $this->warn('Any existing federation trust (a GCP Workload Identity Federation provider, etc.) configured against the old JWKS is now invalid.');

        return self::SUCCESS;
    }
}
