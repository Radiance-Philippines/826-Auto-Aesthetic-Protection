<?php

namespace App\Http\Controllers;

use App\Models\MetaSetting;
use App\Support\Meta\MessagingWebhookHandler;
use App\Support\Meta\MetaClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta's Messenger webhook, one URL for every branch Page. Meta first checks
 * the URL with the verify token; after that every event is signed with the
 * app secret, checked on the raw body before anything else. The work runs
 * after the response.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started
 */
class MetaWebhookController extends Controller
{
    /**
     * PHP turns the dots in hub.mode, hub.verify_token and hub.challenge into underscores.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) MetaSetting::instance()->webhook_verify_token;
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $isValid = $request->query('hub_mode') === 'subscribe'
            && $expected !== ''
            && is_string($token)
            && hash_equals($expected, $token)
            && is_string($challenge)
            && preg_match('/^[0-9A-Za-z_-]{1,100}$/', $challenge);

        return $isValid ? response($challenge, 200, ['Content-Type' => 'text/plain']) : response('', 403);
    }

    public function receive(Request $request, MessagingWebhookHandler $handler): Response
    {
        $settings = MetaSetting::instance();
        $body = $request->getContent();

        if (blank($settings->app_secret) || ! MetaClient::verifyWebhook((string) $settings->app_secret, $body, $request->header('X-Hub-Signature-256'))) {
            return response('', 401);
        }

        $payload = json_decode($body, true);

        if (is_array($payload)) {
            defer(fn () => $handler->handle($payload));
        }

        return response('', 200);
    }
}
