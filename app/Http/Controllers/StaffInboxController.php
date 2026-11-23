<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Inbox as InboxPage;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Support\Inbox\Inbox;
use App\Support\Inbox\InboxException;
use App\Support\Inbox\InboxList;
use App\Support\Inbox\SampleInbox;
use App\Support\InboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The staff app's Inbox: the same Messenger chats and heads-up threads the
 * admin Inbox page shows (a heads-up or internal-thread reply is a team
 * note, never sent), a branch's only to that branch's staff (Conversation::visibleTo
 * and ConversationPolicy; another branch's chat is a 404). Replies go
 * through App\Support\Inbox\Inbox, which saves only locally on demo and
 * sample chats and sends for real only when Meta is connected.
 */
class StaffInboxController extends StaffController
{
    private const LIST_LIMIT = 100;

    private const MESSAGE_PAGE = 50;

    /**
     * GET /api/staff/inbox?branch=&filter=open|unread|done|all&channel=all|messenger|headsup|internal&q=
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireInbox();
        SampleInbox::ensureSeeded();

        $user = $this->user();
        $tabs = InboxList::branchTabs($user);
        $branch = InboxList::validBranch($user, $tabs, (string) $request->query('branch', 'all'));
        $filter = in_array($request->query('filter'), InboxList::FILTERS, true) ? $request->query('filter') : 'open';
        $channel = in_array($request->query('channel'), InboxList::CHANNELS, true) ? $request->query('channel') : 'all';
        $unread = InboxList::unreadByBranch($user);

        $tabRows = $tabs->map(fn (Branch $each, string $key) => ['key' => $key, 'label' => $each->name, 'unread' => $unread[(int) $key] ?? 0])->values();

        if (InboxList::showsAllTab($user, $tabs)) {
            $tabRows->prepend(['key' => 'all', 'label' => 'All', 'unread' => array_sum($unread)]);
        }

        return response()->json([
            'branch' => $branch,
            'filter' => $filter,
            'channel' => $channel,
            'showsAll' => InboxList::showsAllTab($user, $tabs),
            'tabs' => $tabRows->values()->all(),
            'totalUnread' => array_sum($unread),
            'notice' => InboxList::setupNotice($user, $tabs, $branch),
            'conversations' => InboxList::query($user, $tabs, $branch, $filter, (string) $request->query('q', ''), $channel)
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (Conversation $conversation) => $this->row($conversation))
                ->values()
                ->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/staff/inbox/{id}?before=<message id>&limit=: the thread, oldest first; opening it marks it read.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = $this->visibleConversation($id, 'view');

        app(Inbox::class)->markRead($conversation, $this->user());
        $conversation->refresh()->load(['branch', 'guest', 'assignee', 'booking', 'linkedBooking']);

        $limit = min(max((int) $request->query('limit', self::MESSAGE_PAGE), 1), 100);
        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->when($request->query('before'), fn ($query, $before) => $query->where('id', '<', (int) $before))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $last = $conversation->last_inbound_at;
        $guest = $conversation->guest && $this->user()->canAccessArea('customers.view') && Guest::query()->visibleTo($this->user())->whereKey($conversation->guest_id)->exists() ? $conversation->guest : null;

        return response()->json([
            'conversation' => $this->row($conversation) + [
                'assignedTo' => $conversation->assignee ? ['id' => $conversation->assignee->getKey(), 'name' => $conversation->assignee->name] : null,
                'customer' => $guest ? ['id' => $guest->getKey(), 'name' => $guest->name] : null,
                'pastReplyWindow' => $last !== null && $last->lt(now()->subHours(Inbox::WINDOW_HOURS)),
                'windowHours' => Inbox::WINDOW_HOURS,
                'savesLocallyOnly' => Inbox::sendsNothing($conversation),
            ],
            'messages' => $messages->take($limit)->reverse()->values()->map(fn (ConversationMessage $message) => $this->message($message, $conversation))->all(),
            'hasOlder' => $messages->count() > $limit,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/staff/inbox/{id}/reply {body}
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $conversation = $this->visibleConversation($id, 'update');

        $data = $request->validate(['body' => ['required', 'string', 'max:'.InboxPage::MAX_REPLY_LENGTH]], [
            'body.required' => 'Write a message first.',
            'body.max' => 'Keep the message under '.number_format(InboxPage::MAX_REPLY_LENGTH).' characters.',
        ]);

        try {
            $message = app(Inbox::class)->reply($conversation, $data['body'], $this->user());
        } catch (InboxException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['body' => [$exception->getMessage()]]], 422);
        }

        return response()->json([
            'message' => $this->message($message->load('sender:id,name'), $conversation),
            'conversation' => $this->row($conversation->refresh()->load(['branch', 'booking:id,reference', 'linkedBooking:id,reference'])),
            'savedOnly' => Inbox::sendsNothing($conversation),
        ], 201);
    }

    /**
     * POST /api/staff/inbox {branch, subject, body, booking?}: a new internal (team) thread in one of the person's branches.
     */
    public function start(Request $request): JsonResponse
    {
        $this->requireInbox();

        $data = $request->validate([
            'branch' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:'.InboxPage::MAX_REPLY_LENGTH],
            'booking' => ['nullable', 'string', 'max:40'],
        ], [
            'subject.required' => 'Write a subject first.',
            'body.required' => 'Write a message first.',
            'body.max' => 'Keep the message under '.number_format(InboxPage::MAX_REPLY_LENGTH).' characters.',
        ]);

        try {
            $conversation = app(Inbox::class)->startInternal($this->user(), (int) $data['branch'], $data['subject'], $data['body'], $data['booking'] ?? null);
        } catch (InboxException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['body' => [$exception->getMessage()]]], 422);
        }

        return response()->json([
            'conversation' => $this->row($conversation->load(['branch', 'booking:id,reference', 'linkedBooking:id,reference'])),
            'message' => $this->message($conversation->messages()->with('sender:id,name')->first(), $conversation),
        ], 201);
    }

    /**
     * POST /api/staff/inbox/{id}/done {done: bool}
     */
    public function done(Request $request, int $id): JsonResponse
    {
        $conversation = $this->visibleConversation($id, 'update');

        $data = $request->validate(['done' => ['required', 'boolean']]);
        $conversation->forceFill(['status' => $data['done'] ? Conversation::DONE : Conversation::OPEN])->save();

        return response()->json($this->row($conversation->load(['branch', 'booking:id,reference', 'linkedBooking:id,reference'])));
    }

    /**
     * GET /api/staff/inbox/quick-replies?conversation=<id> (or ?branch=<id>): the fixed
     * texts (config/staff.php) this branch has the data for. Nothing is
     * made up: a text without its data, or without any text yet, is left out.
     */
    public function quickReplies(Request $request): JsonResponse
    {
        $this->requireInbox();

        $conversation = $request->query('conversation') ? $this->visibleConversation((int) $request->query('conversation'), 'view') : null;

        // Canned customer texts make no sense in a team thread
        if ($conversation?->isInternal()) {
            return response()->json(['branch' => null, 'replies' => []]);
        }

        $branch = $conversation?->branch ?? $this->pickedBranch($request) ?? Branch::query()->visibleTo($this->user())->ordered()->first();
        $name = $conversation?->customer_name ? explode(' ', trim($conversation->customer_name))[0] : null;
        $map = $branch?->map()['google'] ?? null;

        $replies = collect((array) config('staff.quick_replies'))
            ->map(function (array $reply, string $key) use ($branch, $name, $map) {
                $text = $reply['text'] ?? null;

                if (blank($text) || $branch === null || ($reply['needs'] === 'map' && blank($map))) {
                    return null;
                }

                $text = str_replace(['{name}', '{branch}', '{map}'], [(string) $name, $branch->name, (string) $map], $text);

                return ['key' => $key, 'label' => $reply['label'], 'text' => $name === null ? str_replace('Hi ,', 'Hi,', $text) : $text];
            })
            ->filter()
            ->values()
            ->all();

        return response()->json(['branch' => $branch ? ['id' => $branch->getKey(), 'name' => $branch->name] : null, 'replies' => $replies]);
    }

    private function requireInbox(): void
    {
        abort_unless(InboxAccess::allows($this->user()), 403, 'Your role doesn’t include this.');
    }

    /**
     * Another branch's chat (or none) is the same 404, never a hint it exists.
     */
    private function visibleConversation(int $id, string $ability): Conversation
    {
        $this->requireInbox();

        $conversation = Conversation::visibleTo($this->user())->with(['branch', 'booking:id,reference', 'linkedBooking:id,reference'])->find($id);
        abort_unless($conversation, 404, 'Chat not found.');
        Gate::forUser($this->user())->authorize($ability, $conversation);

        return $conversation;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Conversation $conversation): array
    {
        return [
            'id' => $conversation->getKey(),
            'name' => $conversation->displayName(),
            'avatar' => $conversation->avatar,
            'branch' => $conversation->branch ? ['id' => $conversation->branch->getKey(), 'name' => $conversation->branch->name] : null,
            'preview' => $conversation->last_message_preview,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'unread' => $conversation->unreadFor($this->user()),
            'done' => $conversation->isDone(),
            'sample' => (bool) $conversation->is_sample,
            'linkedCustomer' => $conversation->guest_id !== null,
        ] + ($conversation->isTeamOnly() ? [
            // Only heads-up and internal rows carry these: a Messenger row keeps the shape the app 0.1.1 knows
            'channel' => $conversation->channel,
            'internal' => true,
            'booking' => ($booking = $conversation->aboutBooking()) ? ['id' => $booking->getKey(), 'reference' => $booking->reference] : null,
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ConversationMessage $message, Conversation $conversation): array
    {
        return [
            'id' => $message->getKey(),
            // In a team thread "out" is the viewer's own post, so the app can keep drawing its side
            'direction' => $conversation->isInternal() ? ((int) $message->sent_by === (int) $this->user()->getKey() ? ConversationMessage::OUT : ConversationMessage::IN) : $message->direction,
            'body' => $message->body,
            'attachments' => $message->attachmentList(),
            'senderName' => $message->sender?->name ?? $message->sender_name,
            'failed' => (bool) $message->failed,
            'error' => $message->error,
            'sentAt' => $message->sent_at->toIso8601String(),
        ] + ($conversation->isTeamOnly() ? ['internal' => true] : []);
    }
}
