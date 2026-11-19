<?php

use Splicewire\Beam\Accounts\Tokens\TokenWriteScanner;

/**
 * credential-scope 03, fleet ADR 0006 C7: no token row is written outside AuthTokenFactory and MachineTokenIssuer.
 *
 * ## Why the package scan pins a list instead of asserting empty
 *
 * The check is RED today, by design: ticket 03 builds the issuer, and ticket 04 moves every existing writer onto it.
 * Asserting `[]` here would fail this package's own suite until 04 lands; skipping it would make the check
 * invisible. So the package scan asserts its findings EQUAL the recorded current offenders below:
 *
 *  - a new token writer anywhere in this package fails (the set grew);
 *  - a writer 04 moves onto the issuer fails until its line is deleted here (the set shrank), so the list can only
 *    empty, and 04's acceptance is this constant reaching `[]`.
 *
 * The fleet half (tower, the flagship, satellites, seeders) is the `splicewire:beam:accounts:token-writes` command,
 * which exits 1 on any finding; its current fleet offenders are recorded in the credential-scope 03 proof.
 */
const CS03_CURRENT_OFFENDERS = [
    // The Tokens page's own minter: writes the configured model directly and turns an empty request into `*`.
    'src/Http/Controllers/Account/ApiTokenController.php' => 'token-model-write',
    // The deterministic key writer: a query-builder insert whose abilities default to `*`.
    'src/Keys/DeterministicToken.php' => 'token-table-write',
    // The console door onto it, whose `--ability` defaults to `*`.
    'src/Console/MintKeyCommand.php' => 'deterministic-key',
];

function cs03PackageRoot(): string
{
    return dirname(__DIR__);
}

/** @return array<string, string> relative file => rule */
function cs03PackageFindings(): array
{
    $root = cs03PackageRoot();
    $findings = (new TokenWriteScanner)->scan(array_map(fn ($d) => "{$root}/{$d}", ['src', 'database', 'routes', 'config']));

    $byFile = [];
    foreach ($findings as $finding) {
        $byFile[ltrim(str_replace($root, '', $finding['file']), '/')] = $finding['rule'];
    }
    ksort($byFile);

    return $byFile;
}

it('finds exactly the recorded current token writers in this package, so 04 can only empty the list', function () {
    $expected = CS03_CURRENT_OFFENDERS;
    ksort($expected);

    expect(cs03PackageFindings())->toBe($expected);
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
]);

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
