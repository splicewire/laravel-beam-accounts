<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Accounts\Tokens\MachineTokenIssuer;
use Splicewire\Beam\Accounts\Tokens\TokenIssuance;
use Splicewire\Beam\Localization\CodedValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Host-facing affordance for the key-management module (gated on `beam.accounts.keys.enabled`): mint a
 * deterministic, reset-surviving key of a DECLARED kind from the CLI. Given the same (id, plaintext) it prints the
 * SAME bearer every run, so a satellite and the engine can each mint the credential they share without a central
 * authority.
 *
 * Through the {@see MachineTokenIssuer} (fleet ADR 0006 C7, credential-scope 04): the KIND resolves the principal
 * the key hangs off and the abilities it carries, so the console names neither. A key is a token for another
 * principal than the operator (who has none in a console), so the kind is a delegated one that admits a console
 * issuance; the issuer refuses `*`, an undeclared kind, and every issuance while `scope_enforcement` is off. Before
 * 04 this command took `--tokenable-*` and `--ability` (default `*`) and wrote the row with the query builder.
 */
class MintKeyCommand extends Command
{
    // Every {…} definition must fit on ONE line: Laravel's signature parser matches them with
    // `/\{\s*(.*?)\s*\}/`, a regex with no `s` modifier, so `.` never crosses a newline. A
    // definition wrapped across lines is not a parse error — it is silently skipped, and the
    // argument simply does not exist at runtime. That is exactly what happened to {id}: the
    // command shipped with no way to pass the value the whole primitive is pinned on.
    protected $signature = 'splicewire:beam:accounts:mint-key
        {kind : A declared token kind (beam.accounts.tokens.kinds); it resolves the principal and the abilities}
        {id : The fixed token id — an int on a bigint-keyed host (pin one clear of auto-increment), or a uuid on a uuid-keyed one (pin it deterministically, e.g. uuid5 over the key name, so a re-mint reproduces it)}
        {plaintext : The fixed plaintext (the part after "id|")}
        {--name=host-key : A label for the token row}';

    protected $description = 'Mint a deterministic, reset-surviving key of a declared kind for this host (per-host, never cross-host).';

    public function handle(MachineTokenIssuer $issuer): int
    {
        try {
            $issued = $issuer->issue((string) $this->argument('kind'), new TokenIssuance(
                actor: null,
                name: (string) $this->option('name'),
                // NOT cast to int (beam-docs-satellite ticket 25): a uuid-keyed host — every splicewire-operated
                // host — needs its uuid to survive this line. Non-lossy for bigint hosts.
                deterministicId: (string) $this->argument('id'),
                deterministicPlaintext: (string) $this->argument('plaintext'),
            ));
        } catch (HttpExceptionInterface $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (CodedValidationException $e) {
            $this->error(implode(' ', array_merge(...array_values($e->errors()))));

            return self::FAILURE;
        }

        $this->info('Minted deterministic key (idempotent — re-running yields the same value):');
        $this->line($issued->plainTextToken);

        return self::SUCCESS;
    }
}
