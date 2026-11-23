<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * A fake Google/Apple: our own RSA key, published as the provider's key set
 * (Http::fake, no network), and ID tokens signed with it.
 */
trait SignsIdTokens
{
    private static ?OpenSSLAsymmetricKey $idProviderKey = null;

    protected function providerKey(): OpenSSLAsymmetricKey
    {
        return self::$idProviderKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    /**
     * Publishes the key set at Google's and Apple's URLs.
     */
    protected function fakeProviderKeys(string $kid = 'test-key'): void
    {
        $rsa = openssl_pkey_get_details($this->providerKey())['rsa'];
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => $this->base64Url($rsa['n']), 'e' => $this->base64Url($rsa['e'])]]];

        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => Http::response($jwks),
            'appleid.apple.com/auth/keys' => Http::response($jwks),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function idToken(array $claims, string $kid = 'test-key', ?OpenSSLAsymmetricKey $key = null): string
    {
        $head = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
        $body = $this->base64Url(json_encode($claims));
        openssl_sign("{$head}.{$body}", $signature, $key ?? $this->providerKey(), OPENSSL_ALGO_SHA256);

        return "{$head}.{$body}.".$this->base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function googleClaims(array $overrides = []): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'android.apps.googleusercontent.com',
            'sub' => '1098765432100',
            'email' => 'maria@example.com',
            'email_verified' => true,
            'name' => 'Maria Santos',
            'picture' => 'https://lh3.example/photo.jpg',
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function appleClaims(array $overrides = []): array
    {
        return [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'ph.radianceph.aap826',
            'sub' => '001234.abcdef.5678',
            'email' => 'maria@example.com',
            'email_verified' => 'true',
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
            ...$overrides,
        ];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
