<?php

namespace App\Support\Live;

/**
 * A Flow WebSocket ticket (docs/APPS.md §3 of radiance-flow):
 *
 *   body   = base64url(JSON {"t": [topics], "e": unix expiry, "sub": "<who>"})
 *   ticket = body . "." . base64url(HMAC-SHA256(secret, body))
 *
 * base64url without padding; the HMAC is over the body text as sent.
 */
final class Ticket
{
    /**
     * @param  list<string>  $topics
     */
    public static function sign(array $topics, string $subject, int $expires, string $secret): string
    {
        $body = self::encode(json_encode(['t' => array_values($topics), 'e' => $expires, 'sub' => $subject], JSON_UNESCAPED_SLASHES));

        return $body.'.'.self::encode(hash_hmac('sha256', $body, $secret, true));
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
