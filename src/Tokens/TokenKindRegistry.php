<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;

/**
 * The declared machine-token kinds (ADR 0006 rule 3, credential-scope 03): `name => class-string<TokenKind>`.
 *
 * {@see MachineTokenIssuer} mints only a kind named here and records the name on the token. The package declares
 * `personal-access-token`; a host or package that mints for another principal (a satellite tenant, a broker, the
 * engine loopback, a federation grant) declares its {@see DelegatedTokenKind} under its own name.
 *
 * A {@see ConfigRegistry}: the config array is the storage and every read goes back to it, so a kind a provider
 * declares after this singleton resolved is still found.
 */
#[IsRegistry(
    root: 'beam.accounts.tokens.kinds',
    entryType: 'class-string<Splicewire\Beam\Accounts\Tokens\TokenKind>',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'Declared machine-token kinds. MachineTokenIssuer mints only these, and records the kind on the token.',
)]
class TokenKindRegistry extends ConfigRegistry
{
    /**
     * The package's own kinds, declared even where a host's published `config/beam/accounts.php` predates the
     * `tokens.kinds` key: `mergeConfigFrom` merges only the top level, so a host's `tokens` array replaces the
     * package's whole and would otherwise leave the personal-access-token kind undeclared. A host entry under the
     * same name supersedes it.
     */
    public const BUILT_IN = [
        'personal-access-token' => Kinds\PersonalAccessTokenKind::class,
    ];

    protected function configKey(): string
    {
        return 'beam.accounts.tokens.kinds';
    }

    /** The declared kind under `$name`, or null when none is (an undeclared kind is refused). */
    public function kind(string $name): ?TokenKind
    {
        if (Key::tryParse($name) === null) {
            return null;
        }

        $class = $this->tryResolve($name) ?? self::BUILT_IN[$name] ?? null;

        // Only the two shapes the issuer knows how to cap: a bare TokenKind subclass is neither, so it is not a kind.
        if (! is_string($class) || ! (is_subclass_of($class, SamePrincipalTokenKind::class) || is_subclass_of($class, DelegatedTokenKind::class))) {
            return null;
        }

        return app($class);
    }

    /** @return list<string> every declared kind name, the package's own included */
    public function names(): array
    {
        return array_values(array_unique([...array_keys(self::BUILT_IN), ...$this->store()->relativeKeys()]));
    }
}
