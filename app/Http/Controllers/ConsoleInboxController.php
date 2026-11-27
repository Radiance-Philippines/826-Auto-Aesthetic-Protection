<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Inbox as InboxPage;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Support\CurrentBranch;
use App\Support\Inbox\Inbox;
use App\Support\Inbox\InboxList;
use App\Support\Inbox\SampleInbox;
use App\Support\InboxAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The React admin's Inbox: what the Filament page shows, as JSON, with its
 * "Assign" and "Link customer". Reply, done and "New team thread" are the
 * staff app's own endpoints (routes/admin/inbox.php).
 */
class ConsoleInboxController extends StaffController
{
    private const PAGE_SIZE = 50;

    /**
     * GET ?branch=&filter=&channel=&search=
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
        $search = is_string($request->query('search')) ? $request->query('search') : '';
        $unread = InboxList::unreadByBranch($user);
        $rows = $tabs->map(fn (Branch $each, string $key) => ['key' => $key, 'label' => $each->name, 'unread' => $unread[(int) $key] ?? 0])->values();

        if (InboxList::showsAllTab($user, $tabs)) {
            $rows->prepend(['key' => 'all', 'label' => 'All', 'unread' => array_sum($unread)]);
        }

        return response()->json([
            'branch' => $branch,
            'filter' => $filter,
            'channel' => $channel,
            // A branch chosen in the top selector already scopes the list: no tabs then
            'tabs' => CurrentBranch::for($user) === null ? $rows->all() : [],
            'branches' => $tabs->map(fn (Branch $each, string $key) => ['value' => $key, 'label' => $each->name])->values()->all(),
            'notice' => InboxList::setupNotice($user, $tabs, $branch),
            'conversations' => InboxList::query($user, $tabs, $branch, $filter, $search, $channel)
                ->limit(100)
                ->get()
                ->map(fn (Conversation $conversation) => [
                    'id' => $conversation->getKey(),
                    'name' => $conversation->displayName(),
                    'avatar' => $conversation->avatar,
                    'channel' => $conversation->channel,
                    'branch' => $conversation->branch?->name,
                    'about' => $conversation->isHeadsUp() ? $conversation->booking?->reference : ($conversation->isInternal() ? $conversation->linkedBooking?->reference : null),
                    'preview' => $conversation->last_message_preview,
                    'when' => $conversation->last_message_at?->diffForHumans(short: true),
                    'unread' => $conversation->unreadFor($user),
                    'sample' => (bool) $conversation->is_sample,
                    'done' => $conversation->isDone(),
                ])
                ->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET {id}?limit=: the thread, oldest first. Looking at it marks it read.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = $this->conversation($id, 'view');
        $user = $this->user();

        app(Inbox::class)->markRead($conversation, $user);
        $conversation->load(['branch', 'guest', 'assignee', 'booking', 'linkedBooking']);

        $limit = min(max((int) $request->query('limit', self::PAGE_SIZE), self::PAGE_SIZE), 1000);
        $messages = $conversation->messages()->with('sender:id,name')->orderByDesc('sent_at')->orderByDesc('id')->limit($limit + 1)->get();
        $about = $conversation->aboutBooking();
        $guest = $conversation->guest;
        $last = $conversation->last_inbound_at;

        return response()->json([
            'id' => $conversation->getKey(),
            'name' => $conversation->displayName(),
            'channel' => $conversation->channel,
            'branch' => $conversation->branch?->name,
            'sample' => (bool) $conversation->is_sample,
            'done' => $conversation->isDone(),
            'booking' => $about ? ['reference' => $about->reference, 'id' => $user->canAccessArea('bookings.view') ? $about->getKey() : null] : null,
            'customer' => $guest ? ['id' => Gate::allows('view', $guest) ? $guest->getKey() : null, 'guestId' => $guest->getKey(), 'name' => $guest->name, 'label' => $this->guestLabel($guest)] : null,
            'assignee' => $conversation->assignee ? ['id' => $conversation->assignee->getKey(), 'name' => $conversation->assignee->name] : null,
            // Meta's standard window: a hint only, Meta decides whether it accepts the reply
            'pastWindow' => $last !== null && $last->lt(now()->subHours(Inbox::WINDOW_HOURS)),
            'maxLength' => InboxPage::MAX_REPLY_LENGTH,
            'messages' => $messages->take($limit)->reverse()->values()->map(fn (ConversationMessage $message) => $this->message($message, $conversation))->all(),
            'hasOlder' => $messages->count() > $limit,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET {id}/team: who this chat can be assigned to.
     */
    public function team(int $id): JsonResponse
    {
        $conversation = $this->conversation($id, 'update');

        return response()->json([
            'data' => collect(InboxList::assignable($conversation->branch_id))->map(fn (string $name, int $key) => ['value' => (string) $key, 'label' => $name])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * POST {id}/assign {assignedTo}: null for nobody.
     */
    public function assign(Request $request, int $id): Response
    {
        $conversation = $this->conversation($id, 'update');
        $data = $request->validate(
            ['assignedTo' => ['present', 'nullable', Rule::in(array_keys(InboxList::assignable($conversation->branch_id)))]],
            ['assignedTo.in' => 'Pick someone from this branch.'],
        );

        $conversation->forceFill(['assigned_to' => $data['assignedTo']])->save();

        return response()->noContent();
    }

    /**
     * GET {id}/customers?search=: customers this person may link.
     */
    public function customers(Request $request, int $id): JsonResponse
    {
        $this->messengerChat($id);

        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $guests = Guest::query()
            ->visibleTo($this->user())
            ->where(fn (Builder $match) => $match->where('guests.name', 'like', "%{$search}%")->orWhere('guests.phone', 'like', "%{$search}%"))
            ->orderBy('guests.name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $guests->map(fn (Guest $guest) => ['value' => (string) $guest->getKey(), 'label' => $this->guestLabel($guest)])->all()])->header('Cache-Control', 'no-store');
    }

    /**
     * POST {id}/customer {guestId}: null unlinks.
     */
    public function link(Request $request, int $id): Response
    {
        $conversation = $this->messengerChat($id);
        $data = $request->validate(['guestId' => ['present', 'nullable', 'integer']]);
        $guestId = $data['guestId'] === null ? null : (int) $data['guestId'];

        abort_if($guestId !== null && ! Guest::query()->visibleTo($this->user())->whereKey($guestId)->exists(), 404, 'Customer not found.');

        $conversation->forceFill(['guest_id' => $guestId])->save();

        return response()->noContent();
    }

    private function requireInbox(): void
    {
        abort_unless(InboxAccess::allows($this->user()), 403, 'Your role doesn’t include this.');
    }

    /**
     * Another branch's chat (or none) is the same 404, never a hint it exists.
     */
    private function conversation(int $id, string $ability): Conversation
    {
        $this->requireInbox();

        $conversation = Conversation::visibleTo($this->user())->find($id);
        abort_unless($conversation, 404, 'Chat not found.');
        Gate::authorize($ability, $conversation);

        return $conversation;
    }

    /**
     * Team threads have no customer to link, as on the Filament page.
     */
    private function messengerChat(int $id): Conversation
    {
        $conversation = $this->conversation($id, 'update');
        abort_if($conversation->isTeamOnly(), 403, 'A team thread has no customer.');

        return $conversation;
    }

    private function guestLabel(Guest $guest): string
    {
        return collect([$guest->name, $guest->phone])->filter()->implode(' · ');
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ConversationMessage $message, Conversation $conversation): array
    {
        $by = match (true) {
            $conversation->isInternal() => $message->sender?->name ?? 'Team',
            $conversation->isHeadsUp() => ($message->isInbound() ? 'Change' : 'Internal note').' · '.($message->sender?->name ?? 'Team'),
            ! $message->isInbound() => $message->sender?->name ?? ($message->sender_name ? 'via '.$message->sender_name : 'Shop'),
            default => null,
        };

        return [
            'id' => $message->getKey(),
            // In a team thread the right side is the viewer's own posts
            'right' => $conversation->isInternal() ? (int) $message->sent_by === (int) $this->user()->getKey() : ! $message->isInbound(),
            'body' => $message->body,
            'attachments' => $message->attachmentList(),
            'by' => $by,
            'at' => $message->sent_at->format('M j, g:i A'),
            'failed' => (bool) $message->failed,
            'note' => match (true) {
                (bool) $message->failed => "Not sent: {$message->error}",
                ! $message->isInbound() && $message->external_id === null && ! $conversation->isTeamOnly() => 'Saved here only, not sent',
                default => null,
            },
        ];
    }
}
