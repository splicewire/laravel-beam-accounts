<?php

namespace Splicewire\Beam\Accounts\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Accounts\Oidc\SigningKey;

/**
 * Boots the engine with the OIDC-issuer module opted IN, and proves the whole
 * self-federation loop end-to-end: generate a key, mint a self-signed identity token, and
 * verify it using ONLY the public material a real federation consumer (GCP's WIF/STS, or
 * anything else fetching the JWKS route) would ever see — never the private key directly.
 */
class OidcIssuerTest extends Orchestra
{
    protected string $keyPath;

    protected function getPackageProviders($app): array
    {
        return [
            // popcorn's shared RegistryIndex singleton — see TestCase::getPackageProviders().
            PopcornServiceProvider::class,
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            BeamAccountsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->keyPath = sys_get_temp_dir().'/oidc-issuer-test-'.uniqid().'.pem';

        $config->set('beam.accounts.oidc.enabled', true);
        $config->set('beam.accounts.oidc.issuer', 'https://tower.example.test');
        $config->set('beam.accounts.oidc.signing_key_path', $this->keyPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }

        parent::tearDown();
    }

    public function test_command_is_registered_when_the_module_is_enabled(): void
    {
        $this->assertArrayHasKey(
            'splicewire:beam:accounts:oidc:generate-signing-key',
            $this->app[Kernel::class]->all(),
        );
    }

    public function test_generate_command_is_idempotent(): void
    {
        $this->artisan('splicewire:beam:accounts:oidc:generate-signing-key')->assertSuccessful();
        $firstKid = $this->app->make(SigningKey::class)->kid();

        // Re-running without --force leaves the key untouched.
        $this->artisan('splicewire:beam:accounts:oidc:generate-signing-key')->assertSuccessful();
        $this->assertSame($firstKid, $this->app->make(SigningKey::class)->kid());
    }

    public function test_discovery_and_jwks_routes_serve_the_public_key(): void
    {
        $this->artisan('splicewire:beam:accounts:oidc:generate-signing-key')->assertSuccessful();

        $discovery = $this->get('/.well-known/openid-configuration')->assertSuccessful()->json();
        $this->assertSame('https://tower.example.test', $discovery['issuer']);
        $this->assertSame('https://tower.example.test/.well-known/jwks.json', $discovery['jwks_uri']);

        $jwks = $this->get('/.well-known/jwks.json')->assertSuccessful()->json();
        $this->assertCount(1, $jwks['keys']);
        $this->assertSame('RSA', $jwks['keys'][0]['kty']);
        $this->assertSame('RS256', $jwks['keys'][0]['alg']);
        $this->assertArrayNotHasKey('d', $jwks['keys'][0]); // never the private exponent
    }

    public function test_minted_token_verifies_against_the_published_jwk_alone(): void
    {
        $this->artisan('splicewire:beam:accounts:oidc:generate-signing-key')->assertSuccessful();

        $minter = $this->app->make(IdentityTokenMinter::class);
        $jwt = $minter->mint(audience: '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/tower-pool/providers/tower-oidc');

        $jwks = $this->get('/.well-known/jwks.json')->assertSuccessful()->json();
        $jwk = $jwks['keys'][0];

        $publicKeyPem = $this->jwkToPem($jwk['n'], $jwk['e']);
        $decoded = (array) JWT::decode($jwt, new Key($publicKeyPem, 'RS256'));

        $this->assertSame('https://tower.example.test', $decoded['iss']);
        $this->assertSame('//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/tower-pool/providers/tower-oidc', $decoded['aud']);
        $this->assertLessThanOrEqual(300, $decoded['exp'] - $decoded['iat']);
    }

    /** Minimal JWK(RSA public)→PEM reconstruction, exercising the DER shape a REAL federation consumer builds from the JWKS, independent of this package's own signing path. */
    private function jwkToPem(string $n64, string $e64): string
    {
        $n = $this->base64UrlDecode($n64);
        $e = $this->base64UrlDecode($e64);

        $modulus = $this->derInteger($n);
        $exponent = $this->derInteger($e);
        $rsaPublicKey = $this->derSequence($modulus.$exponent);

        $rsaOid = pack('H*', '300d06092a864886f70d0101010500');
        $bitString = "\x00".$rsaPublicKey;
        $bitStringEncoded = chr(3).$this->derLength(strlen($bitString)).$bitString;
        $spki = $this->derSequence($rsaOid.$bitStringEncoded);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $bytes): string
    {
        if (ord($bytes[0]) > 127) {
            $bytes = "\x00".$bytes;
        }

        return chr(2).$this->derLength(strlen($bytes)).$bytes;
    }

    private function derSequence(string $bytes): string
    {
        return chr(0x30).$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }

        $bytes = ltrim(pack('N', $len), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
