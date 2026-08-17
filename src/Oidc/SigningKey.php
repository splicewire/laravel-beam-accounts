<?php

namespace Splicewire\Beam\Accounts\Oidc;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Tower's own OIDC-issuer signing key (tenant-database-upsell ticket 16's OIDC-issuer
 * primitive): a per-host RSA keypair whose PUBLIC half is served at
 * {@see \Splicewire\Beam\Accounts\Http\Controllers\OidcDiscoveryController}'s JWKS endpoint
 * and whose PRIVATE half signs {@see IdentityTokenMinter}'s tokens. Generated once via
 * `splicewire:beam:accounts:oidc:generate-signing-key`, never regenerated silently — a
 * rotated key invalidates every federation trust relationship (a GCP Workload Identity
 * Federation provider, or any other OIDC-federation consumer) already configured against
 * the old JWKS, so rotation is a deliberate operator action, not an automatic one.
 *
 * Per-host, never cross-host, mirroring {@see \Splicewire\Beam\Accounts\Keys\DeterministicToken}'s
 * own posture — each site that turns this on mints and owns its own issuer identity.
 */
class SigningKey
{
    protected ?string $privatePem = null;

    /** @var array{n: string, e: string}|null Raw binary RSA components, cached per-instance. */
    protected ?array $components = null;

    public function __construct(
        protected string $path,
    ) {}

    public function exists(): bool
    {
        return File::exists($this->path);
    }

    /**
     * @param  int  $bits  RSA key size. 2048 is GCP WIF's own documented floor for an OIDC provider's keys.
     */
    public function generate(int $bits = 2048, bool $force = false): void
    {
        if ($this->exists() && ! $force) {
            throw new RuntimeException("An OIDC signing key already exists at `{$this->path}` — pass force to replace it (this invalidates every existing federation trust configured against the old JWKS).");
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Could not generate an RSA keypair: '.openssl_error_string());
        }

        openssl_pkey_export($resource, $pem);

        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, $pem);
        chmod($this->path, 0600);

        $this->privatePem = $pem;
        $this->components = null;
    }

    public function privatePem(): string
    {
        if ($this->privatePem !== null) {
            return $this->privatePem;
        }

        if (! $this->exists()) {
            throw new RuntimeException("No OIDC signing key at `{$this->path}` — run `splicewire:beam:accounts:oidc:generate-signing-key` first.");
        }

        return $this->privatePem = File::get($this->path);
    }

    /** A stable identifier for this key, derived from its own public modulus — no separate tracking needed. */
    public function kid(): string
    {
        $c = $this->rsaComponents();

        return substr(hash('sha256', $c['n'].$c['e']), 0, 16);
    }

    /**
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public function jwk(): array
    {
        $c = $this->rsaComponents();

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid(),
            'n' => self::base64UrlEncode($c['n']),
            'e' => self::base64UrlEncode($c['e']),
        ];
    }

    /** @return array{n: string, e: string} Raw binary. */
    protected function rsaComponents(): array
    {
        if ($this->components !== null) {
            return $this->components;
        }

        $resource = openssl_pkey_get_private($this->privatePem());
        if ($resource === false) {
            throw new RuntimeException("OIDC signing key at `{$this->path}` is not a valid RSA private key: ".openssl_error_string());
        }

        $details = openssl_pkey_get_details($resource);
        if (! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException("OIDC signing key at `{$this->path}` is not an RSA key — only RSA is supported.");
        }

        return $this->components = ['n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
    }

    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
