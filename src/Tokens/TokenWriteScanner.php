<?php

namespace Splicewire\Beam\Accounts\Tokens;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The architecture check behind fleet ADR 0006 C7: token writes outside
 * {@see \Splicewire\Beam\Accounts\Auth\AuthTokenFactory} and {@see MachineTokenIssuer}, in any family package or
 * host it is pointed at.
 *
 * It reads PHP tokens (`token_get_all`), never runs the code, and reports EVERY write site it finds (not the first per
 * function), each as (file, rule, line, function). PHP method, function and class names are matched without regard to
 * case, and class names through the file's `use … [as …]` imports. Each function or method body is one scope, and the
 * code outside every function (a routes file, a script) is one more. Four shapes:
 *
 *  - `create-token`: a `->createToken(` / `::createToken(` call (Sanctum's `HasApiTokens`), except on a `broker()`
 *    receiver, which is a password-reset token;
 *  - `token-model-write`: a model write (`save`, `create`, `forceCreate`, `insert`, `upsert`, `updateOrCreate`,
 *    `firstOrCreate`, `update`, …) in a scope that references the token model: a class whose name ends in
 *    `PersonalAccessToken`, an import aliasing one, a configured model class, `tokenModel()`,
 *    `personalAccessTokenModel()`; or in a statement on a `->tokens()` relation;
 *  - `token-table-write`: a query-builder write in a statement that selects the token table (`table('…')` with its
 *    name, or with a non-literal in a file that names it);
 *  - `deterministic-key`: a direct use of `Splicewire\Beam\Accounts\Keys\DeterministicToken`, the query-builder
 *    key writer credential-scope 04 retired (a copy outside this package is still a writer).
 *
 * A variable assigned from a token-model, token-table or `->tokens()` expression (`$q = DB::table('personal_access_tokens')`,
 * `$m = BeamAccounts::tokenModel()`, `$t = new $m`) carries that through later statements in its scope, so
 * `$q->insert(…)` is reported too.
 *
 * NOT SEEN, stated: a method or class named through a variable or string (`$x->{$m}()`, `call_user_func`), raw SQL
 * (`DB::insert('insert into personal_access_tokens …')`), a value carried through a property, a return value or
 * another function, and writes in directories named `tests`, `vendor`, `node_modules`, `migrations` (test fixtures,
 * code scanned in its own repository, and schema changes to existing rows). A text search for the token table name and
 * `createToken` beside this scan is the cross-check for those.
 */
final class TokenWriteScanner
{
    /** The only files that may write a token row (real-path suffixes). */
    public const ALLOWED = [
        'laravel-beam-accounts/src/Auth/AuthTokenFactory.php',
        'laravel-beam-accounts/src/Tokens/MachineTokenIssuer.php',
    ];

    public const SKIPPED_DIRECTORIES = ['tests', 'vendor', 'node_modules', 'migrations', '.git'];

    private const MODEL_WRITES = ['save', 'savequietly', 'push', 'create', 'createquietly', 'forcecreate', 'forcecreatequietly', 'insert', 'insertgetid', 'insertorignore', 'upsert', 'updateorcreate', 'firstorcreate', 'createorfirst', 'createmany', 'savemany', 'update', 'updatequietly', 'increment', 'decrement'];

    private const TABLE_WRITES = ['insert', 'insertgetid', 'insertorignore', 'insertusing', 'upsert', 'update', 'updateorinsert', 'increment', 'decrement'];

    private const MODEL_REFERENCES = ['tokenmodel', 'personalaccesstokenmodel'];

    /** @var list<string> lower-cased */
    private array $tables;

    /** @var list<string> lower-cased fully qualified class names, no leading backslash */
    private array $modelClasses;

    /**
     * @param  list<string>  $tables  the token table names (Sanctum's default, plus any a host renamed it to)
     * @param  list<string>  $modelClasses  configured token model classes whose names need not end in PersonalAccessToken
     */
    public function __construct(array $tables = ['personal_access_tokens'], array $modelClasses = [])
    {
        $this->tables = array_map('strtolower', $tables);
        $this->modelClasses = array_map(fn (string $c) => strtolower(ltrim($c, '\\')), $modelClasses);
    }

    /**
     * @param  iterable<string>  $roots  files or directories
     * @return list<array{file: string, rule: string, line: int, function: string}>
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
     * @return list<array{rule: string, line: int, function: string}>
     */
    public function scanSource(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true),
        ));

        $imports = $this->imports($tokens);
        $namesTable = false;
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($t[1], '\'"')), $this->tables, true)) {
                $namesTable = true;
            }
        }

        $findings = [];
        foreach ($this->scopes($tokens) as [$name, $scope]) {
            foreach ($this->scanScope($scope, $imports, $namesTable) as $finding) {
                $findings[] = [...$finding, 'function' => $name];
            }
        }

        usort($findings, fn ($a, $b) => [$a['line'], $a['rule']] <=> [$b['line'], $b['rule']]);

        return $findings;
    }

    /**
     * @param  list<mixed>  $scope
     * @param  array<string, string>  $imports
     * @return list<array{rule: string, line: int}>
     */
    private function scanScope(array $scope, array $imports, bool $namesTable): array
    {
        /** @var array<int, array{rule: string, line: int}> $found keyed by token position in the scope */
        $found = [];
        $mark = function (int $pos, string $rule, int $line) use (&$found): void {
            $found[$pos] ??= ['rule' => $rule, 'line' => $line];
        };

        // Single-token shapes.
        foreach ($scope as $i => $t) {
            if ($this->isCallAt($scope, $i, ['createtoken']) && ! $this->onPasswordBroker($scope, $i)) {
                $mark($i, 'create-token', $t[2]);
            }

            if ($this->isClassName($t) && $this->shortName($this->resolve($t[1], $imports)) === 'deterministictoken'
                && $this->isInstantiationOrStaticCall($scope, $i)) {
                $mark($i, 'deterministic-key', $t[2]);
            }
        }

        // The scope-wide rule: a scope that references the token model writes it wherever it writes a model.
        $scopeReferencesModel = $this->referencesTokenModel($scope, $imports);

        // Statement shapes, with taint carried through assignments in this scope.
        /** @var array<string, string> $tainted variable name => 'table' | 'model' */
        $tainted = [];
        foreach ($this->statements($scope) as $statement) {
            $table = $this->selectsTokenTable($statement, $namesTable);
            $model = $this->referencesTokenModel(array_map(fn ($e) => $e[1], $statement), $imports)
                || $this->callsAny($statement, ['tokens']);
            $carried = $this->taintIn($statement, $tainted);

            if ($carried === 'table') {
                $table = true;
            } elseif ($carried === 'model') {
                $model = true;
            }

            // `$x = <token expression>` taints $x for the rest of the scope.
            [$p0, $p1] = [$statement[0][1] ?? null, $statement[1][1] ?? null];
            if (is_array($p0) && $p0[0] === T_VARIABLE && $p1 === '=') {
                if ($table) {
                    $tainted[$p0[1]] = 'table';
                } elseif ($model) {
                    $tainted[$p0[1]] = 'model';
                }
            }

            $plain = array_map(fn ($e) => $e[1], $statement);
            foreach ($statement as $k => [$pos, $t]) {
                if (! (is_array($t) && $this->isCallAt($plain, $k, [strtolower($t[1])]))) {
                    continue;
                }

                $method = strtolower($t[1]);

                if ($table && in_array($method, self::TABLE_WRITES, true)) {
                    $mark($pos, 'token-table-write', $t[2]);
                } elseif (($model || $scopeReferencesModel) && in_array($method, self::MODEL_WRITES, true)) {
                    $mark($pos, 'token-model-write', $t[2]);
                }
            }
        }

        ksort($found);

        return array_values($found);
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

        $directories = new RecursiveCallbackFilterIterator(
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

    /**
     * `use A\B\C;` and `use A\B\C as D;` (and grouped `use A\{B, C as D};`) at any depth: lower-cased alias => FQCN.
     *
     * @param  list<mixed>  $tokens
     * @return array<string, string>
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_USE)) {
                continue;
            }

            // A closure's `use (` binds variables, not names.
            if (($tokens[$i + 1] ?? null) === '(') {
                continue;
            }

            $prefix = '';
            $name = null;
            $alias = null;
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                $t = $tokens[$j];

                if (is_array($t) && in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    if (($tokens[$j - 1][0] ?? null) === T_AS) {
                        $alias = $t[1];
                    } elseif (! (is_array($t) && in_array(strtolower($t[1]), ['function', 'const'], true))) {
                        $name = $t[1];
                    }
                } elseif ($t === '{') {
                    $prefix = rtrim((string) $name, '\\').'\\';
                    $name = null;
                } elseif ($t === ',' || $t === '}') {
                    $this->addImport($imports, $prefix, $name, $alias);
                    [$name, $alias] = [null, null];
                }
            }

            $this->addImport($imports, $prefix, $name, $alias);
            $i = $j;
        }

        return $imports;
    }

    /** @param  array<string, string>  $imports */
    private function addImport(array &$imports, string $prefix, ?string $name, ?string $alias): void
    {
        if ($name === null) {
            return;
        }

        $fqcn = ltrim($prefix.ltrim($name, '\\'), '\\');
        $imports[strtolower($alias ?? $this->shortName($fqcn))] = $fqcn;
    }

    /** @param  array<string, string>  $imports */
    private function resolve(string $name, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = strtolower(explode('\\', $name)[0]);

        if (isset($imports[$first])) {
            $rest = substr($name, strlen($first));

            return $imports[$first].$rest;
        }

        return $name;
    }

    private function isClassName(mixed $t): bool
    {
        return is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    /**
     * Whether `$tokens` reference the token model: a class (through imports) whose name ends in PersonalAccessToken or
     * is a configured model, or a `tokenModel()` / `personalAccessTokenModel()` call.
     *
     * @param  list<mixed>  $tokens  plain tokens
     * @param  array<string, string>  $imports
     */
    private function referencesTokenModel(array $tokens, array $imports): bool
    {
        $plain = array_values($tokens);

        foreach ($plain as $i => $t) {
            if ($this->isClassName($t) && ! $this->isCallAt($plain, $i, [strtolower($t[1])])) {
                $fqcn = strtolower($this->resolve($t[1], $imports));

                if (str_ends_with($fqcn, 'personalaccesstoken') || in_array($fqcn, $this->modelClasses, true)) {
                    return true;
                }
            }

            if ($this->isCallAt($plain, $i, self::MODEL_REFERENCES, requireOperator: false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a statement selects the token table: `table('<token table>')`, or `table(<non-literal>)` in a file that
     * names the token table.
     *
     * @param  list<array{0: int, 1: mixed}>  $statement
     */
    private function selectsTokenTable(array $statement, bool $namesTable): bool
    {
        $plain = array_map(fn ($e) => $e[1], $statement);

        foreach ($plain as $i => $t) {
            if (! $this->isCallAt($plain, $i, ['table'])) {
                continue;
            }

            $argument = $plain[$i + 2] ?? null;
            $closes = in_array($plain[$i + 3] ?? null, [')', ','], true);

            if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING && $closes) {
                if (in_array(strtolower(trim($argument[1], '\'"')), $this->tables, true)) {
                    return true;
                }

                continue;
            }

            if ($namesTable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: mixed}>  $statement
     * @param  list<string>  $names  lower-cased
     */
    private function callsAny(array $statement, array $names): bool
    {
        $plain = array_map(fn ($e) => $e[1], $statement);

        foreach (array_keys($plain) as $i) {
            if ($this->isCallAt($plain, $i, $names)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The taint a statement reads from an earlier assignment in its scope: 'table' wins over 'model'.
     *
     * @param  list<array{0: int, 1: mixed}>  $statement
     * @param  array<string, string>  $tainted
     */
    private function taintIn(array $statement, array $tainted): ?string
    {
        $seen = null;

        foreach ($statement as $k => [, $t]) {
            // The assigned variable itself (`$q = …`) is not a read.
            if ($k === 0 && ($statement[1][1] ?? null) === '=') {
                continue;
            }

            if (is_array($t) && $t[0] === T_VARIABLE && isset($tainted[$t[1]])) {
                if ($tainted[$t[1]] === 'table') {
                    return 'table';
                }
                $seen = 'model';
            }
        }

        return $seen;
    }

    /**
     * Whether the token at `$i` is a call `->name(`, `?->name(`, `::name(` (or a plain `name(` when
     * `$requireOperator` is false) for any of `$names`, case-insensitively.
     *
     * @param  list<mixed>  $tokens
     * @param  list<string>  $names  lower-cased
     */
    private function isCallAt(array $tokens, int $i, array $names, bool $requireOperator = true): bool
    {
        $t = $tokens[$i] ?? null;

        if (! (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), $names, true) && ($tokens[$i + 1] ?? null) === '(')) {
            return false;
        }

        if (! $requireOperator) {
            return true;
        }

        $p = $tokens[$i - 1] ?? null;

        return is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    /**
     * `Password::broker()->createToken($user)` mints a password-reset token, not an API token.
     *
     * @param  list<mixed>  $tokens
     */
    private function onPasswordBroker(array $tokens, int $i): bool
    {
        $receiver = $tokens[$i - 4] ?? null;

        return ($tokens[$i - 2] ?? null) === ')' && ($tokens[$i - 3] ?? null) === '('
            && is_array($receiver) && $receiver[0] === T_STRING && strtolower($receiver[1]) === 'broker';
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
     * Statements of a scope as lists of [position, token], split at `;`, `{` and `}`.
     *
     * @param  list<mixed>  $scope
     * @return list<list<array{0: int, 1: mixed}>>
     */
    private function statements(array $scope): array
    {
        $statements = [];
        $current = [];

        foreach ($scope as $pos => $t) {
            if ($t === ';' || $t === '{' || $t === '}') {
                if ($current !== []) {
                    $statements[] = $current;
                }
                $current = [];

                continue;
            }

            $current[] = [$pos, $t];
        }

        if ($current !== []) {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * Each top-level function or method body (closures stay inside the body that holds them; a closure outside every
     * function is its own scope), plus everything outside them as one more scope.
     *
     * @param  list<mixed>  $tokens
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function scopes(array $tokens): array
    {
        $scopes = [];
        $outside = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION)) {
                $outside[] = $tokens[$i];

                continue;
            }

            $name = '{closure}';
            $j = $i + 1;
            if (($tokens[$j] ?? null) === '&') {
                $j++;
            }
            // A reserved word is a legal method name (`function for(`) and is not always lexed as T_STRING.
            if (is_array($tokens[$j] ?? null) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tokens[$j][1]) === 1) {
                $name = $tokens[$j][1];
            }

            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }

            // An abstract or interface signature has no body.
            if ($j >= $count || $tokens[$j] === ';') {
                $i = $j;

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

            $scopes[] = [$name, $body];
            $i = $k;
        }

        $scopes[] = ['{file}', $outside];

        return $scopes;
    }

    private function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return strtolower((string) end($parts));
    }
}
