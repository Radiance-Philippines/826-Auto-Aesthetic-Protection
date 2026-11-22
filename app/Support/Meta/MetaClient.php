<?php

namespace App\Support\Meta;

use App\Models\Branch;
use App\Models\MetaSetting;
use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Meta Graph API for Messenger, in-process over the HTTP client. Every call
 * uses one branch Page's token, sent in the Authorization header (never the
 * URL) with an appsecret_proof when the app secret is known.
 *
 * @see https://developers.facebook.com/docs/graph-api/securing-requests
 */
class MetaClient
{
    public const GRAPH = 'https://graph.facebook.com/v26.0';

    /**
     * Webhook fields a Page is subscribed to.
     *
     * @var list<string>
     */
    public const PAGE_FIELDS = ['messages', 'message_echoes'];

    private const TIMEOUT_SECONDS = 15;

    public function __construct(private string $pageToken, private ?string $appSecret = null) {}

    /**
     * The client for a branch's saved Page token.
     */
    public static function forBranch(Branch $branch): self
    {
        if (blank($branch->facebook_page_token)) {
            throw new MetaException('This branch has no Facebook Page token. Add it in Branches → Facebook page.');
        }

        return new self((string) $branch->facebook_page_token, MetaSetting::instance()->app_secret);
    }

    /**
     * hex(HMAC-SHA256(key: app secret, message: access token)).
     */
    public static function appSecretProof(string $accessToken, string $appSecret): string
    {
        return hash_hmac('sha256', $accessToken, $appSecret);
    }

    /**
     * A webhook is genuine when X-Hub-Signature-256 is
     * "sha256=" + hex(HMAC-SHA256(key: app secret, message: raw body)).
     *
     * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started#validate-payloads
     */
    public static function verifyWebhook(string $appSecret, string $rawBody, ?string $signature): bool
    {
        if (! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $appSecret), strtolower(trim(substr($signature, 7))));
    }

    /**
     * What a Page token belongs to: the Page's id and name.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->request('GET', '/me', ['fields' => 'id,name']);
    }

    /**
     * Send the Page's messages and message echoes to this site's webhook.
     *
     * @see https://developers.facebook.com/docs/messenger-platform/webhooks#connect-your-app
     */
    public function subscribe(string $pageId): void
    {
        $this->request('POST', '/'.self::id($pageId).'/subscribed_apps', ['subscribed_fields' => implode(',', self::PAGE_FIELDS)]);
    }

    /**
     * A text reply to a Messenger PSID. Returns Meta's message ID.
     *
     * @see https://developers.facebook.com/docs/messenger-platform/reference/send-api
     */
    public function sendMessage(string $pageId, string $recipientId, string $text): string
    {
        $data = $this->request('POST', '/'.self::id($pageId).'/messages', [
            'recipient' => ['id' => self::id($recipientId)],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ]);

        if (blank($data['message_id'] ?? null)) {
            throw new MetaException('Meta did not confirm the message.');
        }

        return (string) $data['message_id'];
    }

    /**
     * @see https://developers.facebook.com/docs/messenger-platform/send-messages/sender-actions
     */
    public function markSeen(string $pageId, string $recipientId): void
    {
        $this->request('POST', '/'.self::id($pageId).'/messages', [
            'recipient' => ['id' => self::id($recipientId)],
            'sender_action' => 'mark_seen',
        ]);
    }

    /**
     * The customer's name and photo.
     *
     * @see https://developers.facebook.com/docs/messenger-platform/identity/user-profile
     *
     * @return array{name: string|null, avatar: string|null}
     */
    public function profile(string $psid): array
    {
        $data = $this->request('GET', '/'.self::id($psid), ['fields' => 'first_name,last_name,profile_pic']);
        $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        return [
            'name' => filled($name) ? $name : null,
            'avatar' => is_string($data['profile_pic'] ?? null) ? $data['profile_pic'] : null,
        ];
    }

    /**
     * IDs go into request paths: letters, digits, dashes and underscores only.
     */
    private static function id(string $id): string
    {
        if (! preg_match('/^[0-9A-Za-z_-]{1,100}$/', $id)) {
            throw new MetaException('Invalid Meta ID.');
        }

        return $id;
    }

    /**
     * @param  'GET'|'POST'  $method
     * @param  array<string, mixed>  $params  Query (GET) or JSON body (POST)
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $url = self::GRAPH.$path;
        $proof = filled($this->appSecret) ? ['appsecret_proof' => self::appSecretProof($this->pageToken, (string) $this->appSecret)] : [];

        $response = $this->send(fn () => $method === 'GET'
            ? Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->withToken($this->pageToken)->get($url, [...$params, ...$proof])
            : Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->withToken($this->pageToken)->withQueryParameters($proof)->post($url, $params));

        return $this->data($response);
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException|TransferException) {
            // Not chained: the original message has the full URL
            throw new MetaException("Couldn't reach Meta. Try again in a few minutes.");
        }
    }

    /**
     * The JSON of a successful response, else an exception with Meta's
     * code, message and trace ID.
     *
     * @see https://developers.facebook.com/docs/graph-api/guides/error-handling
     *
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $json = $response->json();

        if (is_array($json) && is_array($json['error'] ?? null)) {
            $error = $json['error'];
            $code = preg_replace('/[^0-9]/', '', (string) ($error['code'] ?? ''));
            $message = Str::limit(Str::squish((string) ($error['error_user_msg'] ?? $error['message'] ?? 'Unknown error')), 200);
            $trace = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($error['fbtrace_id'] ?? ''));

            throw new MetaException("Meta error {$code}: {$message}".($trace !== '' ? " (trace {$trace})" : ''));
        }

        if (! $response->successful() || ! is_array($json)) {
            throw new MetaException("Meta returned an unexpected response (HTTP {$response->status()}).");
        }

        return $json;
    }
}
