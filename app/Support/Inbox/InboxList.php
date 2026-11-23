<?php

namespace App\Support\Inbox;

use App\Models\Branch;
use App\Models\Conversation;
use App\Models\MetaSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which chats a team member's inbox lists: a tab per branch they may see
 * (and "All"), a filter and a search. Shared by the admin Inbox page and
 * the staff app, so both show the same chats.
 */
final class InboxList
{
    /**
     * @var list<string>
     */
    public const FILTERS = ['open', 'unread', 'done', 'all'];

    /**
     * @var list<string>
     */
    public const CHANNELS = ['all', Conversation::MESSENGER, Conversation::HEADSUP, Conversation::INTERNAL];

    /**
     * The branches this user may see: the tabs (after "All").
     *
     * @return Collection<string, Branch>
     */
    public static function branchTabs(User $user): Collection
    {
        return Branch::query()->visibleTo($user)->ordered()->get()->keyBy(fn (Branch $branch): string => (string) $branch->getKey());
    }

    /**
     * "All" is for a super admin, or someone with two or more branches (it
     * then only covers their own); one branch has just its own tab.
     *
     * @param  Collection<string, Branch>  $tabs
     */
    public static function showsAllTab(User $user, Collection $tabs): bool
    {
        return $user->isOwner() || $tabs->count() > 1;
    }

    /**
     * A branch id the user may see, else "all" (when they get that tab) or their one branch.
     *
     * @param  Collection<string, Branch>  $tabs
     */
    public static function validBranch(User $user, Collection $tabs, string $branch): string
    {
        if ($tabs->has($branch)) {
            return $branch;
        }

        return self::showsAllTab($user, $tabs) ? 'all' : (string) $tabs->keys()->first();
    }

    /**
     * @param  Collection<string, Branch>  $tabs
     * @return Builder<Conversation>
     */
    public static function query(User $user, Collection $tabs, string $branch, string $filter, string $search, string $channel = 'all'): Builder
    {
        $search = trim($search);

        return Conversation::query()
            ->visibleTo($user)
            ->ofChannel($channel)
            ->with(['branch:id,name', 'booking:id,reference', 'linkedBooking:id,reference'])
            ->when($branch !== 'all' && $tabs->has($branch), fn ($query) => $query->where('branch_id', (int) $branch))
            ->when($filter === 'unread', fn ($query) => $query->unread($user))
            ->when($filter === 'open', fn ($query) => $query->where('status', Conversation::OPEN))
            ->when($filter === 'done', fn ($query) => $query->where('status', Conversation::DONE))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('last_message_preview', 'like', "%{$search}%")))
            ->latestFirst();
    }

    /**
     * What to tell the team above the list: sample chats, or Meta not set up.
     *
     * @param  Collection<string, Branch>  $tabs
     */
    public static function setupNotice(User $user, Collection $tabs, string $branch): ?string
    {
        if (SampleInbox::active()) {
            return 'These are sample chats, so you can see how the inbox works. Nothing is sent or received until Meta is set up; replies here are only saved on this page.';
        }

        if (! MetaSetting::instance()->isReady()) {
            return $user->isOwner()
                ? 'Messenger is not set up yet. Add the Meta app in Settings → Meta, then each branch’s Facebook Page in Branches → Facebook page.'
                : 'Messenger is not set up yet. Ask an owner to connect Meta and your branch’s Facebook Page.';
        }

        $tab = $tabs->get($branch);

        return $tab && ! $tab->hasFacebookPage()
            ? "{$tab->name} has no connected Facebook Page yet (Branches → Facebook page)."
            : null;
    }

    /**
     * Unread chats per branch id, for the tab badges.
     *
     * @return array<int, int>
     */
    public static function unreadByBranch(User $user): array
    {
        return Conversation::visibleTo($user)->unread($user)
            ->selectRaw('branch_id, count(*) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
