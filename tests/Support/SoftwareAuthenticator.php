<?php

namespace Tests\Support;

/**
 * A software passkey, standing in for a phone or laptop in tests: it makes
 * a real P-256 key, and builds the registration and sign-in answers a
 * browser would send (CBOR attestation object, authenticator data, an
 * ES256 signature). Nothing here bypasses the server's checks: they run
 * for real against what this produces.
 */
class SoftwareAuthenticator
{
    public string $credentialId;

    private \OpenSSLAsymmetricKey $key;

    private int $counter = 0;

    public function __construct(public string $origin = 'http://localhost', public string $rpId = 'localhost', public bool $userVerified = true)
    {
        $this->credentialId = random_bytes(32);
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    /**
     * What navigator.credentials.create() gives back, as toJSON() prints it.
     *
     * @param  array<string, mixed>  $options  the registration options the server sent
     * @return array<string, mixed>
     */
    public function register(array $options): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = "\xA5"
            .$this->cbor(1).$this->cbor(2)
            .$this->cbor(3).$this->cbor(-7)
            .$this->cbor(-1).$this->cbor(1)
            .$this->cbor(-2).$this->bin(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT))
            .$this->cbor(-3).$this->bin(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));

        $authData = hash('sha256', $this->rpId, true)
            .chr($this->flags() | 0x40)
            .pack('N', $this->counter)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$cose;

        $attestation = "\xA3"
            .$this->cbor('fmt').$this->cbor('none')
            .$this->cbor('attStmt')."\xA0"
            .$this->cbor('authData').$this->bin($authData);

        return [
            'id' => $this->b64($this->credentialId),
            'rawId' => $this->b64($this->credentialId),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => (object) [],
            'response' => [
                'clientDataJSON' => $this->b64($this->clientData('webauthn.create', $options['challenge'])),
                'attestationObject' => $this->b64($attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * What navigator.credentials.get() gives back.
     *
     * @param  array<string, mixed>  $options  the sign-in options the server sent
     * @return array<string, mixed>
     */
    public function assert(array $options, int|string $userId, ?string $origin = null): array
    {
        $clientData = $this->clientData('webauthn.get', $options['challenge'], $origin);
        $authData = hash('sha256', $this->rpId, true).chr($this->flags()).pack('N', ++$this->counter);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->b64($this->credentialId),
            'rawId' => $this->b64($this->credentialId),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => (object) [],
            'response' => [
                'clientDataJSON' => $this->b64($clientData),
                'authenticatorData' => $this->b64($authData),
                'signature' => $this->b64($signature),
                'userHandle' => $this->b64((string) $userId),
            ],
        ];
    }

    private function flags(): int
    {
        return 0x01 | ($this->userVerified ? 0x04 : 0);
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin, 'crossOrigin' => false]);
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * The little CBOR the answers need: small ints and text, and byte strings.
     */
    private function cbor(int|string $value): string
    {
        if (is_int($value)) {
            return $value >= 0 ? $this->head(0, $value) : $this->head(1, -1 - $value);
        }

        return $this->head(3, strlen($value)).$value;
    }

    private function bin(string $bytes): string
    {
        return $this->head(2, strlen($bytes)).$bytes;
    }

    private function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr(($major << 5) | $length),
            $length < 256 => chr(($major << 5) | 24).chr($length),
            default => chr(($major << 5) | 25).pack('n', $length),
        };
    }
}
