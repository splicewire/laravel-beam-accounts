<?php

namespace Splicewire\Beam\Accounts\Tokens;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The architecture check behind fleet ADR 0006 C7: every token write outside
 * {@see \Splicewire\Beam\Accounts\Auth\AuthTokenFactory} and {@see MachineTokenIssuer}, in any family package or
 * host it is pointed at.
 *
 * It reads PHP tokens (`token_get_all`), never runs the code, and reports four shapes of write:
 *
 *  - `create-token`: a `->createToken(` call (Sanctum's `HasApiTokens`), except on a `broker()` receiver, which is
 *    a password-reset token;
 *  - `token-model-write`: in one function body, a reference to the token model (a class whose name ends in
 *    `PersonalAccessToken`, `tokenModel()`, `personalAccessTokenModel()`) together with a model write (`save`,
 *    `create`, `forceCreate`, `insert`, `upsert`, `updateOrCreate`, `firstOrCreate`, …), or a `->tokens()`
 *    relation write in one statement;
 *  - `token-table-write`: a query-builder write (`insert`, `update`, `upsert`, `updateOrInsert`, …) in a statement
 *    that calls `table(` with the token table's name, or with a non-literal in a file that names the token table;
 *  - `deterministic-key`: a direct use of {@see \Splicewire\Beam\Accounts\Keys\DeterministicToken}, the
 *    query-builder key writer, which is itself a `token-table-write`.
 *
 * A finding is (file, rule, line). The allowed writers are matched by path suffix, so a vendored or worktree copy
 * of this package is recognised as the same file.
 *
 * Scope, stated: production code only. Directories named `tests`, `vendor`, `node_modules` and `migrations` are
 * skipped: test code builds fixture rows, vendored code is scanned in its own repository, and a migration changes
 * the schema of rows that already exist. A token write spelled through a variable method name or a raw SQL string
 * is not seen.
 */
final class TokenWriteScanner
{
    /** The only files that may write a token row (path suffixes). */
    public const ALLOWED = [
        'laravel-beam-accounts/src/Auth/AuthTokenFactory.php',
        'laravel-beam-accounts/src/Tokens/MachineTokenIssuer.php',
    ];

    public const SKIPPED_DIRECTORIES = ['tests', 'vendor', 'node_modules', 'migrations', '.git'];

    private const MODEL_WRITES = ['save', 'saveQuietly', 'push', 'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'updateOrCreate', 'firstOrCreate', 'createOrFirst', 'createMany', 'saveMany'];

    private const TABLE_WRITES = ['insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'update', 'updateOrInsert'];

    private const MODEL_REFERENCES = ['tokenModel', 'personalAccessTokenModel'];

    /** @param  list<string>  $tables  the token table names (Sanctum's default, plus any a host renamed it to) */
    public function __construct(private array $tables = ['personal_access_tokens']) {}

    /**
     * @param  iterable<string>  $roots  files or directories
     * @return list<array{file: string, rule: string, line: int}>
     */
    public function scan(iterable $roots): array
    {
        $findings = [];

        foreach ($roots as $root) {
            foreach ($this->phpFiles($root) as $file) {
                if ($this->allowed($file)) {
                    continue;
                }

                foreach ($this->scanSource((string) file_get_contents($file)) as $finding) {
                    $findings[] = ['file' => $file, ...$finding];
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{rule: string, line: int}>
     */
    public function scanSource(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $findings = [];
        $namesTable = false;

        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($t[1], '\'"'), $this->tables, true)) {
                $namesTable = true;
            }
        }

        // create-token and deterministic-key: single-token shapes.
        foreach ($tokens as $i => $t) {
            if ($this->isMethodCall($tokens, $i, ['createToken']) && $this->previousIsObjectOperator($tokens, $i)
                && ! $this->onPasswordBroker($tokens, $i)) {
                $findings[] = ['rule' => 'create-token', 'line' => $t[2]];
            }

            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && $this->shortName($t[1]) === 'DeterministicToken'
                && $this->isInstantiationOrStaticCall($tokens, $i)) {
                $findings[] = ['rule' => 'deterministic-key', 'line' => $t[2]];
            }
        }

        // token-table-write and the ->tokens() relation write: statement shapes.
        foreach ($this->statements($tokens) as $statement) {
            $write = $this->firstMethodCall($statement, self::TABLE_WRITES);
            $table = $this->tableCall($statement);

            if ($write !== null && ($table === true || ($table === null && $namesTable))) {
                $findings[] = ['rule' => 'token-table-write', 'line' => $write];
            }

            $relation = $this->firstMethodCall($statement, ['tokens']);
            $relationWrite = $this->firstMethodCall($statement, self::MODEL_WRITES);

            if ($relation !== null && $relationWrite !== null) {
                $findings[] = ['rule' => 'token-model-write', 'line' => $relationWrite];
            }
        }

        // token-model-write: a function body that references the token model and writes a model.
        foreach ($this->functionBodies($tokens) as $body) {
            if (! $this->referencesTokenModel($body)) {
                continue;
            }

            $write = $this->firstMethodCall($body, self::MODEL_WRITES);

            if ($write !== null) {
                $findings[] = ['rule' => 'token-model-write', 'line' => $write];
            }
        }

        $unique = [];
        foreach ($findings as $finding) {
            $unique[$finding['rule'].':'.$finding['line']] = $finding;
        }

        usort($unique, fn ($a, $b) => [$a['line'], $a['rule']] <=> [$b['line'], $b['rule']]);

        return array_values($unique);
    }

    private function allowed(string $file): bool
    {
        $normalized = str_replace('\\', '/', realpath($file) ?: $file);

        foreach (self::ALLOWED as $suffix) {
            if (str_ends_with($normalized, '/'.$suffix) || $normalized === $suffix) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string> */
    private function phpFiles(string $root): iterable
    {
        if (is_file($root)) {
            if (str_ends_with($root, '.php')) {
                yield $root;
            }

            return;
        }

        if (! is_dir($root)) {
            return;
        }

        $directories = new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            fn (SplFileInfo $file) => ! ($file->isDir() && in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true)),
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($directories) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        yield from $files;
    }

    /** @param  list<mixed>  $tokens */
    private function isMethodCall(array $tokens, int $i, array $names): bool
    {
        $t = $tokens[$i];

        return is_array($t) && $t[0] === T_STRING && in_array($t[1], $names, true)
            && ($tokens[$i + 1] ?? null) === '(';
    }

    /** @param  list<mixed>  $tokens */
    private function previousIsObjectOperator(array $tokens, int $i): bool
    {
        $p = $tokens[$i - 1] ?? null;

        return is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    /**
     * `Password::broker()->createToken($user)` mints a password-reset token, not an API token: the receiver is a
     * `broker()` call.
     *
     * @param  list<mixed>  $tokens
     */
    private function onPasswordBroker(array $tokens, int $i): bool
    {
        $receiver = $tokens[$i - 4] ?? null;

        return ($tokens[$i - 2] ?? null) === ')' && ($tokens[$i - 3] ?? null) === '('
            && is_array($receiver) && $receiver[0] === T_STRING && $receiver[1] === 'broker';
    }

    /** @param  list<mixed>  $tokens */
    private function isInstantiationOrStaticCall(array $tokens, int $i): bool
    {
        $p = $tokens[$i - 1] ?? null;
        $n = $tokens[$i + 1] ?? null;

        return (is_array($p) && $p[0] === T_NEW)
            || (is_array($n) && $n[0] === T_DOUBLE_COLON && ! (is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CLASS));
    }

    /**
     * The line of the first `->name(` / `::name(` call in `$tokens` for any of `$names`, or null.
     *
     * @param  list<mixed>  $tokens
     */
    private function firstMethodCall(array $tokens, array $names): ?int
    {
        foreach ($tokens as $i => $t) {
            if ($this->isMethodCall($tokens, $i, $names) && $this->previousIsObjectOperator($tokens, $i)) {
                return $t[2];
            }
        }

        return null;
    }

    /**
     * Whether a statement calls `table(`: true when its first argument is a token-table literal, null when it is
     * not a literal (a variable or a config read), and false when it is no table call or names another table.
     *
     * @param  list<mixed>  $statement
     */
    private function tableCall(array $statement): ?bool
    {
        foreach ($statement as $i => $t) {
            if (! ($this->isMethodCall($statement, $i, ['table']) && $this->previousIsObjectOperator($statement, $i))) {
                continue;
            }

            $argument = $statement[$i + 2] ?? null;
            $closes = ($statement[$i + 3] ?? null) === ')' || ($statement[$i + 3] ?? null) === ',';

            if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING && $closes) {
                if (in_array(trim($argument[1], '\'"'), $this->tables, true)) {
                    return true;
                }

                continue;
            }

            return null;
        }

        return false;
    }

    /** @param  list<mixed>  $body */
    private function referencesTokenModel(array $body): bool
    {
        foreach ($body as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && str_ends_with($this->shortName($t[1]), 'PersonalAccessToken')) {
                return true;
            }

            if ($this->isMethodCall($body, $i, self::MODEL_REFERENCES)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $tokens
     * @return list<list<mixed>>
     */
    private function statements(array $tokens): array
    {
        $statements = [];
        $current = [];

        foreach ($tokens as $t) {
            if ($t === ';' || $t === '{' || $t === '}') {
                if ($current !== []) {
                    $statements[] = $current;
                }
                $current = [];

                continue;
            }

            $current[] = $t;
        }

        if ($current !== []) {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * Each top-level function or method body (closures stay inside the body that holds them).
     *
     * @param  list<mixed>  $tokens
     * @return list<list<mixed>>
     */
    private function functionBodies(array $tokens): array
    {
        $bodies = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION)) {
                continue;
            }

            // Find the opening brace of this function (skip an abstract or interface signature ending in `;`).
            $j = $i + 1;
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }

            if ($j >= $count || $tokens[$j] === ';') {
                continue;
            }

            $depth = 0;
            $body = [];
            for ($k = $j; $k < $count; $k++) {
                $t = $tokens[$k];
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $body[] = $t;
            }

            $bodies[] = $body;
            $i = $k;
        }

        return $bodies;
    }

    private function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return (string) end($parts);
    }
}
