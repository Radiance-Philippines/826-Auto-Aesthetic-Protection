<?php

namespace App\Support\Meta;

use App\Models\Branch;
use App\Models\MetaSetting;
use App\Support\Inbox\InboxException;
use Illuminate\Support\Str;

/**
 * "Connect check" on a branch: asks Meta whose Page token this is and
 * confirms it is the Page id on the branch. Only then is the branch marked
 * connected and (best effort) subscribed to message webhooks.
 */
final class PageConnection
{
    /**
     * @param  string|null  $pageToken  A newly typed token; the saved one is used when blank
     * @return string|null a warning when the Page is connected but could not be subscribed
     *
     * @throws InboxException
     */
    public static function check(Branch $branch, string $pageId, ?string $pageToken = null): ?string
    {
        $pageId = trim($pageId);

        if ($pageId === '') {
            throw new InboxException('Enter the Facebook Page ID first.');
        }

        if (Branch::where('facebook_page_id', $pageId)->whereKeyNot($branch->getKey())->exists()) {
            throw new InboxException('Another branch already uses this Facebook Page.');
        }

        $token = filled($pageToken) ? $pageToken : $branch->facebook_page_token;

        if (blank($token)) {
            throw new InboxException('Paste the Page access token first.');
        }

        $client = new MetaClient((string) $token, MetaSetting::instance()->app_secret);
        $page = $client->me();

        if ((string) ($page['id'] ?? '') !== $pageId) {
            throw new InboxException('This token belongs to another Page (or a user), not the Page ID entered. Generate the token for this Page.');
        }

        $branch->forceFill([
            'facebook_page_id' => $pageId,
            'facebook_page_name' => isset($page['name']) ? Str::limit((string) $page['name'], 250, '') : null,
            'facebook_page_token' => (string) $token,
            'facebook_connected_at' => now(),
        ])->save();

        try {
            $client->subscribe($pageId);
        } catch (InboxException $exception) {
            return "The Page is connected, but subscribing it to messages failed: {$exception->getMessage()} Subscribe it in the Meta app's Webhooks settings.";
        }

        return null;
    }
}
