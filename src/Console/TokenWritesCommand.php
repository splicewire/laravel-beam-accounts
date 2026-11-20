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
 * `--exclude` leaves a path out and names it in the output, so an exclusion is never silent.
 * The scan itself is {@see TokenWriteScanner}; its docblock states what it reads and what it cannot see.
 */
class TokenWritesCommand extends Command
{
    protected $signature = 'splicewire:beam:accounts:token-writes
        {paths?* : Files or directories to scan (default: this host and its installed family packages)}
        {--exclude=* : A file or directory to leave out (repeatable); every exclusion is named in the output}
        {--json : Print the findings as JSON}';

    protected $description = 'List token writes outside AuthTokenFactory and the machine-token issuer (fleet ADR 0006 C7).';

    public function handle(): int
    {
        $paths = (array) $this->argument('paths');

        if ($paths === []) {
            $paths = $this->defaultPaths();
        }

        // An exclusion is stated, never silent: a run that leaves a repo out says so (credential-scope 04 left
        // `rushing/fable-legacy` out by ruling: a non-beam app whose sign-in tokens ADR 0006 does not bind).
        $excluded = array_values(array_filter(array_map(
            fn (string $path) => realpath($path) ?: null,
            (array) $this->option('exclude'),
        )));
        $paths = array_values(array_filter($paths, function (string $path) use ($excluded): bool {
            $real = realpath($path) ?: $path;

            foreach ($excluded as $out) {
                if ($real === $out || str_starts_with($real, rtrim($out, '/').'/')) {
                    return false;
                }
            }

            return true;
        }));

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
            $this->line((string) json_encode(['excluded' => $excluded, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($excluded as $out) {
                $this->line("excluded: {$out}");
            }

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
