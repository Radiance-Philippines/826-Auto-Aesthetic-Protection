<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Checks an OpenID Connect ID token (Google's or Apple's): RS256 signature
 * against the provider's published keys (fetched over HTTP, cached), then
 * issuer, audience and expiry. Uses PHP's openssl only: no JWT package.
 */
final class IdTokenVerifier
{
    private const KEYS_TTL_SECONDS = 3600;

    /** An unknown key id refetches the keys, but not more often than this. */
    private const REFETCH_AFTER_SECONDS = 300;

    private const LEEWAY_SECONDS = 60;

    /**
     * @param  list<string>  $issuers
     * @param  list<string>  $audiences
     * @return array<string, mixed> the token's claims
     *
     * @throws RuntimeException with a message that is safe to show
     */
    public static function verify(string $token, string $keysUrl, array $issuers, array $audiences): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('That sign-in token isn’t valid.');
        }

        $header = self::json($parts[0]);
        $claims = self::json($parts[1]);
        $signature = self::decode($parts[2]);

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null) || $signature === null) {
            throw new RuntimeException('That sign-in token isn’t valid.');
        }

        $key = self::key($keysUrl, $header['kid']);

        if ($key === null || openssl_verify($parts[0].'.'.$parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('That sign-in token isn’t valid.');
        }

        $audience = $claims['aud'] ?? null;
        $audience = is_array($audience) ? $audience : [$audience];

        if (! in_array($claims['iss'] ?? null, $issuers, true) || array_intersect($audience, $audiences) === []) {
            throw new RuntimeException('That sign-in token isn’t for this app.');
        }

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] + self::LEEWAY_SECONDS < now()->getTimestamp()) {
            throw new RuntimeException('That sign-in has expired. Try again.');
        }

        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] > now()->getTimestamp() + self::LEEWAY_SECONDS) {
            throw new RuntimeException('That sign-in token isn’t valid.');
        }

        if (blank($claims['sub'] ?? null)) {
            throw new RuntimeException('That sign-in token isn’t valid.');
        }

        return $claims;
    }

    /**
     * A claim that a provider sends as true or "true".
     */
    public static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * The public key (PEM) for a key id, from the cached key set.
     */
    private static function key(string $url, string $kid): ?string
    {
        $cacheKey = 'app826.jwks.'.md5($url);
        $cached = Cache::get($cacheKey);

        if (! is_array($cached) || (! isset($cached['keys'][$kid]) && now()->getTimestamp() - $cached['at'] > self::REFETCH_AFTER_SECONDS)) {
            $cached = ['at' => now()->getTimestamp(), 'keys' => self::fetch($url)];
            Cache::put($cacheKey, $cached, self::KEYS_TTL_SECONDS);
        }

        return $cached['keys'][$kid] ?? null;
    }

    /**
     * @return array<string, string> kid → PEM public key
     */
    private static function fetch(string $url): array
    {
        try {
            $jwks = Http::timeout(5)->acceptJson()->get($url)->throw()->json('keys');
        } catch (Throwable) {
            throw new RuntimeException('We couldn’t check your sign-in right now. Try again in a moment.', 503);
        }

        $keys = [];

        foreach (is_array($jwks) ? $jwks : [] as $jwk) {
            if (($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['kid'] ?? null) && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                $n = self::decode($jwk['n']);
                $e = self::decode($jwk['e']);

                if ($n !== null && $e !== null) {
                    $keys[$jwk['kid']] = self::pem($n, $e);
                }
            }
        }

        return $keys;
    }

    /**
     * An RSA public key (modulus and exponent bytes) as a PEM
     * "SubjectPublicKeyInfo", built by hand in DER.
     */
    public static function pem(string $modulus, string $exponent): string
    {
        $rsaKey = self::der(0x30, self::derInteger($modulus).self::derInteger($exponent));
        $algorithm = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $der = self::der(0x30, $algorithm.self::der(0x03, "\x00".$rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        $encoded = $length < 128 ? chr($length) : (function () use ($length) {
            $bytes = ltrim(pack('N', $length), "\x00");

            return chr(0x80 | strlen($bytes)).$bytes;
        })();

        return chr($tag).$encoded.$content;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        return self::der(0x02, (ord($bytes[0] ?? "\x00") & 0x80 ? "\x00" : '').$bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $segment): array
    {
        $decoded = json_decode((string) self::decode($segment), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decode(string $segment): ?string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
