<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Accounts\Tokens\TokenWriteScanner;

/**
 * Fleet ADR 0006 C7 as a command: list every token write outside `AuthTokenFactory` and the machine-token issuer,
 * and exit 1 when there is one.
 *
 * With no paths it scans the host (`app`, `src`, `database`, `routes`, `config`) and every installed family package
 * (`vendor/{rushing,schemastud,splicewire}/*`). With paths it scans those, so one run can cover a whole estate.
 * The scan itself is {@see TokenWriteScanner}; its docblock states what it reads and what it cannot see.
 */
class TokenWritesCommand extends Command
{
    protected $signature = 'splicewire:beam:accounts:token-writes
        {paths?* : Files or directories to scan (default: this host and its installed family packages)}
        {--json : Print the findings as JSON}';

    protected $description = 'List token writes outside AuthTokenFactory and the machine-token issuer (fleet ADR 0006 C7).';

    public function handle(): int
    {
        $paths = (array) $this->argument('paths');

        if ($paths === []) {
            $paths = $this->defaultPaths();
        }

        $tables = array_values(array_unique(array_filter([
            'personal_access_tokens',
            config('beam.accounts.keys.table'),
        ], 'is_string')));

        // The configured token models, whose class names need not end in PersonalAccessToken.
        $models = array_values(array_unique(array_filter([
            config('beam.accounts.tokens.model'),
            class_exists(\Laravel\Sanctum\Sanctum::class) ? \Laravel\Sanctum\Sanctum::personalAccessTokenModel() : null,
        ], 'is_string')));

        $findings = (new TokenWriteScanner($tables, $models))->scan($paths);

        if ($this->option('json')) {
            $this->line((string) json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($findings as $finding) {
                $this->line(sprintf('%s:%d  %s  %s', $finding['file'], $finding['line'], $finding['rule'], $finding['function']));
            }

            $this->line(sprintf('%d token write(s) outside AuthTokenFactory and MachineTokenIssuer.', count($findings)));
        }

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function defaultPaths(): array
    {
        $paths = array_map(fn (string $dir) => base_path($dir), ['app', 'src', 'database', 'routes', 'config']);

        foreach (['rushing', 'schemastud', 'splicewire'] as $vendor) {
            foreach (glob(base_path("vendor/{$vendor}/*"), GLOB_ONLYDIR) ?: [] as $package) {
                foreach (['src', 'app', 'database', 'routes', 'config'] as $dir) {
                    $paths[] = $package.'/'.$dir;
                }
            }
        }

        return array_values(array_filter($paths, 'file_exists'));
    }
}
