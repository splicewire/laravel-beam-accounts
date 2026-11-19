<?php

use Splicewire\Beam\Accounts\Tokens\TokenWriteScanner;

/**
 * credential-scope 03, fleet ADR 0006 C7: no token row is written outside AuthTokenFactory and MachineTokenIssuer.
 *
 * ## Why the package scan pins a list instead of asserting empty
 *
 * The check is RED today, by design: ticket 03 builds the issuer, and ticket 04 moves every existing writer onto it.
 * Asserting `[]` here would fail this package's own suite until 04 lands; skipping it would make the check
 * invisible. So the package scan pins every write SITE it finds, `file::function rule => count`, and asserts the
 * findings equal that list exactly:
 *
 *  - a new token writer anywhere fails: a new file, a new method in a listed file, or one more write in a listed
 *    method (the count) — codex cs03 finding 4;
 *  - a writer 04 moves fails until its entry is lowered or deleted here, so 04's acceptance is the list at `[]`;
 *  - the list may only shrink: every entry must sit inside the reviewed ceiling below (same key, count no higher).
 *    The ceiling is the list as reviewed at credential-scope 03; 04 deletes from both, never adds to either.
 *
 * The fleet half (tower, the flagship, satellites, seeders) is the `splicewire:beam:accounts:token-writes` command,
 * which exits 1 on any finding; its current fleet offenders are recorded in the credential-scope 03 proof.
 */
const CS03_CURRENT_OFFENDERS = [
    // The Tokens page's own minter: writes the configured model directly and turns an empty request into `*`.
    'src/Http/Controllers/Account/ApiTokenController.php::mint token-model-write' => 1,
    // The deterministic key writer: a query-builder update and insert whose abilities default to `*`.
    'src/Keys/DeterministicToken.php::mint token-table-write' => 2,
    // The console door onto it, whose `--ability` defaults to `*`.
    'src/Console/MintKeyCommand.php::handle deterministic-key' => 1,
];

/** The reviewed ceiling (credential-scope 03): CS03_CURRENT_OFFENDERS may only fall within it. */
const CS03_REVIEWED_CEILING = [
    'src/Http/Controllers/Account/ApiTokenController.php::mint token-model-write' => 1,
    'src/Keys/DeterministicToken.php::mint token-table-write' => 2,
    'src/Console/MintKeyCommand.php::handle deterministic-key' => 1,
];

function cs03PackageRoot(): string
{
    return dirname(__DIR__);
}

/**
 * @param  list<array{file?: string, rule: string, line: int, function: string}>  $findings
 * @return array<string, int> `file::function rule` => count
 */
function cs03Sites(array $findings, string $file = ''): array
{
    $root = cs03PackageRoot();
    $sites = [];
    foreach ($findings as $finding) {
        $relative = ltrim(str_replace($root, '', $finding['file'] ?? $file), '/');
        $key = "{$relative}::{$finding['function']} {$finding['rule']}";
        $sites[$key] = ($sites[$key] ?? 0) + 1;
    }
    ksort($sites);

    return $sites;
}

/** @return array<string, int> */
function cs03PackageFindings(): array
{
    $root = cs03PackageRoot();

    return cs03Sites((new TokenWriteScanner)->scan(array_map(fn ($d) => "{$root}/{$d}", ['src', 'database', 'routes', 'config'])));
}

it('finds exactly the pinned token write sites in this package, with their multiplicity', function () {
    $expected = CS03_CURRENT_OFFENDERS;
    ksort($expected);

    expect(cs03PackageFindings())->toBe($expected);
});

it('lets the pinned list only shrink within the reviewed ceiling', function () {
    foreach (CS03_CURRENT_OFFENDERS as $site => $count) {
        expect(CS03_REVIEWED_CEILING)->toHaveKey($site)
            ->and($count)->toBeLessThanOrEqual(CS03_REVIEWED_CEILING[$site]);
    }
});

it('fails the pin for a new writer in a listed file and for one more write in a listed method (codex cs03 #4)', function () {
    $file = 'src/Http/Controllers/Account/ApiTokenController.php';
    $source = (string) file_get_contents(cs03PackageRoot().'/'.$file);
    $pinned = ["{$file}::mint token-model-write" => 1];

    expect(cs03Sites((new TokenWriteScanner)->scanSource($source), $file))->toBe($pinned);

    $newMethod = substr_replace($source, "\n    protected function extra(): void { \$m = BeamAccounts::tokenModel(); \$m::create([]); }\n}\n", strrpos($source, '}'));
    expect(cs03Sites((new TokenWriteScanner)->scanSource($newMethod), $file))->not->toBe($pinned);

    $secondWrite = str_replace('] + TokenLastFour::attributesFor($token, $plainText))->save();', '] + TokenLastFour::attributesFor($token, $plainText))->save(); $token->save();', $source);
    expect($secondWrite)->not->toBe($source)
        ->and(cs03Sites((new TokenWriteScanner)->scanSource($secondWrite), $file))->toBe(["{$file}::mint token-model-write" => 2]);
});

it('allows AuthTokenFactory and the issuer, which both write tokens', function () {
    $root = cs03PackageRoot();

    expect((new TokenWriteScanner)->scanSource((string) file_get_contents("{$root}/src/Auth/AuthTokenFactory.php")))->not->toBe([])
        ->and((new TokenWriteScanner)->scanSource((string) file_get_contents("{$root}/src/Tokens/MachineTokenIssuer.php")))->not->toBe([])
        ->and((new TokenWriteScanner)->scan(["{$root}/src/Auth", "{$root}/src/Tokens"]))->toBe([]);
});

it('reports each write shape', function (string $source, string $rule) {
    expect(array_column((new TokenWriteScanner)->scanSource("<?php\n".$source), 'rule'))->toContain($rule);
})->with([
    'createToken' => ['function f($u) { return $u->createToken("x", ["*"]); }', 'create-token'],
    'nullsafe createToken' => ['function f($u) { return $u?->createToken("x"); }', 'create-token'],
    'token model create' => ['use Laravel\Sanctum\PersonalAccessToken; function f() { PersonalAccessToken::create(["name" => "x"]); }', 'token-model-write'],
    'configured model save' => ['function f() { $m = BeamAccounts::tokenModel(); $t = new $m; $t->forceFill([])->save(); }', 'token-model-write'],
    'sanctum model forceCreate' => ['function f() { Sanctum::personalAccessTokenModel()::forceCreate([]); }', 'token-model-write'],
    'tokens relation create' => ['function f($u) { $u->tokens()->create(["name" => "x"]); }', 'token-model-write'],
    'table literal insert' => ['function f() { DB::table("personal_access_tokens")->insert([]); }', 'token-table-write'],
    'table literal update' => ["function f() { DB::table('personal_access_tokens')->where('id', 1)->update(['abilities' => '[\"*\"]']); }", 'token-table-write'],
    'table variable insert' => ['class K { public $table = "personal_access_tokens"; function f() { DB::table($this->table)->insert([]); } }', 'token-table-write'],
    'deterministic key' => ['function f() { (new DeterministicToken(id: "1", plaintext: "p", tokenableType: "u", tokenableId: "1", name: "k"))->mint(); }', 'deterministic-key'],
    // codex cs03 finding 3: each bypass it demonstrated.
    'upper-case createToken' => ['function f($u) { return $u->CREATETOKEN("x", ["*"]); }', 'create-token'],
    'aliased model import' => ['use Laravel\Sanctum\PersonalAccessToken as PAT; function f() { PAT::create(["name" => "x"]); }', 'token-model-write'],
    'grouped aliased import' => ['use Laravel\Sanctum\{Sanctum, PersonalAccessToken as Pat}; function f() { Pat::forceCreate([]); }', 'token-model-write'],
    'top-level model create' => ['Laravel\Sanctum\PersonalAccessToken::create(["name" => "x"]);', 'token-model-write'],
    'top-level createToken' => ['$user->createToken("x");', 'create-token'],
    'table then write, two statements' => ['$q = DB::table("personal_access_tokens"); $q->insert([]);', 'token-table-write'],
    'table then write in a function, upper case' => ['function f() { $q = DB::TABLE("personal_access_tokens")->where("a", 1); $q->UPDATE([]); }', 'token-table-write'],
    'relation then write' => ['function f($u) { $r = $u->tokens(); $r->create([]); }', 'token-model-write'],
    'configured model, new then save' => ['function f() { $m = BeamAccounts::tokenModel(); $t = new $m; $t->forceFill([]); $t->save(); }', 'token-model-write'],
    'reserved-word method name' => ['class C { public function for($u) { return $u->createToken("x"); } }', 'create-token'],
]);

it('reads a configured token model whose class name does not end in PersonalAccessToken', function () {
    $source = "<?php\nuse App\\Models\\ApiKey; function f() { ApiKey::create([]); }";

    expect((new TokenWriteScanner)->scanSource($source))->toBe([])
        ->and(array_column((new TokenWriteScanner(['personal_access_tokens'], ['App\\Models\\ApiKey']))->scanSource($source), 'rule'))->toBe(['token-model-write']);
});

it('reports every write in a method, not the first', function () {
    $findings = (new TokenWriteScanner)->scanSource('<?php function f() { DB::table("personal_access_tokens")->insert([]); DB::table("personal_access_tokens")->update([]); PersonalAccessToken::create([]); }');

    expect(count($findings))->toBe(3);
});

it('does not report reads, revokes, other tables or a password-reset token', function (string $source) {
    expect((new TokenWriteScanner)->scanSource("<?php\n".$source))->toBe([]);
})->with([
    'password broker' => ['function f($o) { return Password::broker()->createToken($o); }'],
    'token read' => ['function f($b) { return PersonalAccessToken::findToken($b); }'],
    'model introspection' => ['function f() { $m = BeamAccounts::tokenModel(); return (new $m)->getKeyName(); }'],
    'relation delete' => ['function f($u) { $u->tokens()->where("name", "x")->delete(); }'],
    'other table literal' => ['function f() { DB::table("users")->insert([]); }'],
    'createToken declaration' => ['class U { public function createToken(string $name) {} }'],
    'a string mentioning createToken' => ['function f() { return "createToken("; }'],
    'deterministic class constant' => ['function f() { return class_exists(DeterministicToken::class); }'],
]);

it('exits 1 with the findings from the fleet command, and 0 on a clean tree', function () {
    $root = cs03PackageRoot();

    $this->artisan('splicewire:beam:accounts:token-writes', ['paths' => ["{$root}/src/Keys"]])
        ->expectsOutputToContain('DeterministicToken.php')
        ->assertExitCode(1);

    $this->artisan('splicewire:beam:accounts:token-writes', ['paths' => ["{$root}/src/Tokens", "{$root}/src/Auth"]])
        ->assertExitCode(0);
});

it('names the enclosing method of a write, a reserved-word method name included', function () {
    $findings = (new TokenWriteScanner)->scanSource('<?php class C { public function for($u) { return $u->createToken("x"); } public function mint($u) { $u->createToken("y"); } }');

    expect(array_column($findings, 'function'))->toBe(['for', 'mint']);
});
