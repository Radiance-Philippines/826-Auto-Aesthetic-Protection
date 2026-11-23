{{-- Admin inbox: a tab per branch, the conversation list (left) and the open thread (right). --}}
@php
    $active = $this->activeConversation;
    $notice = $this->setupNotice();
    $unreadByBranch = $this->unreadByBranch;
@endphp

<x-filament-panels::page>
    <div wire:poll.20s="refreshInbox" class="flex flex-col gap-3">
        @if ($notice)
            <div class="flex items-start gap-2 rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-800 ring-1 ring-warning-200 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30" role="status">
                <x-filament::icon icon="heroicon-m-information-circle" class="mt-0.5 size-5 shrink-0" />
                <p>{{ $notice }}</p>
            </div>
        @endif

        {{-- One tab per branch --}}
        <nav class="flex gap-1 overflow-x-auto pb-1" aria-label="Branches">
            @if ($this->showsAllTab())
            <button
                type="button"
                wire:click="$set('branch', 'all')"
                @class(['flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-primary-600 text-white' => $branch === 'all', 'bg-white text-gray-700 ring-1 ring-gray-950/5 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5' => $branch !== 'all'])
            >
                All
                @if (($total = array_sum($unreadByBranch)) > 0)
                    <span class="rounded-full bg-danger-600 px-1.5 text-[10px] font-bold leading-4 text-white">{{ $total }}</span>
                @endif
            </button>
            @endif
            @foreach ($this->branchTabs as $id => $tab)
                <button
                    type="button"
                    wire:key="tab-{{ $id }}"
                    wire:click="$set('branch', '{{ $id }}')"
                    @class(['flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-primary-600 text-white' => $branch === (string) $id, 'bg-white text-gray-700 ring-1 ring-gray-950/5 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5' => $branch !== (string) $id])
                >
                    {{ $tab->name }}
                    @if (($unreadByBranch[$id] ?? 0) > 0)
                        <span class="rounded-full bg-danger-600 px-1.5 text-[10px] font-bold leading-4 text-white">{{ $unreadByBranch[$id] }}</span>
                    @endif
                </button>
            @endforeach
        </nav>

        <div class="grid min-h-[32rem] gap-4 lg:h-[calc(100dvh-16rem)] lg:grid-cols-[22rem_minmax(0,1fr)]">
            {{-- Conversation list --}}
            <section @class(['flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10', 'hidden lg:flex' => $active])>
                <div class="space-y-3 border-b border-gray-200 p-3 dark:border-white/10">
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Search by name or message" />
                    </x-filament::input.wrapper>
                    <div class="inline-flex gap-1 rounded-lg bg-gray-100 p-1 text-xs dark:bg-white/5" role="group" aria-label="Channel">
                        @foreach ($this->channels() as $value => $label)
                            <button
                                type="button"
                                wire:click="$set('channel', '{{ $value }}')"
                                @class(['shrink-0 rounded-lg px-3 py-1 font-medium', 'bg-white text-gray-950 shadow-sm dark:bg-white/10 dark:text-white' => $channel === $value, 'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white' => $channel !== $value])
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-1 text-xs">
                        @foreach ($this->filters() as $value => $label)
                            <button
                                type="button"
                                wire:click="$set('filter', '{{ $value }}')"
                                @class(['shrink-0 rounded-full px-2.5 py-1 font-medium', 'bg-primary-600 text-white' => $filter === $value, 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $filter !== $value])
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-white/5">
                    @forelse ($this->conversations as $item)
                        <li wire:key="conversation-{{ $item->id }}">
                            <button
                                type="button"
                                wire:click="open({{ $item->id }})"
                                @class(['flex w-full items-start gap-3 px-3 py-3 text-start hover:bg-gray-50 dark:hover:bg-white/5', 'bg-primary-50 dark:bg-primary-500/10' => $active?->id === $item->id])
                            >
                                @if ($item->avatar)
                                    <img src="{{ $item->avatar }}" alt="" class="size-10 shrink-0 rounded-full object-cover" loading="lazy" referrerpolicy="no-referrer">
                                @else
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ mb_strtoupper(mb_substr($item->displayName(), 0, 1)) }}</span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span @class(['truncate text-sm', 'font-semibold text-gray-950 dark:text-white' => $item->unread_count > 0, 'font-medium text-gray-700 dark:text-gray-200' => $item->unread_count === 0])>{{ $item->displayName() }}</span>
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $item->last_message_at?->diffForHumans(short: true) }}</span>
                                    </span>
                                    <span class="flex items-center justify-between gap-2">
                                        <span @class(['truncate text-xs', 'text-gray-900 dark:text-gray-100' => $item->unread_count > 0, 'text-gray-500 dark:text-gray-400' => $item->unread_count === 0])>{{ $item->last_message_preview }}</span>
                                        @if ($item->unread_count > 0)
                                            <span class="shrink-0 rounded-full bg-primary-600 px-1.5 text-[10px] font-bold leading-4 text-white">{{ $item->unread_count }}</span>
                                        @endif
                                    </span>
                                    <span class="mt-1 flex flex-wrap items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400">
                                        @if ($branch === 'all')
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/5">{{ $item->branch?->name }}</span>
                                        @endif
                                        @if ($item->channel === 'headsup')
                                            <span class="rounded bg-primary-600 px-1.5 py-0.5 font-semibold text-white">Heads-up{{ $item->booking ? ' · '.$item->booking->reference : '' }}</span>
                                        @endif
                                        @if ($item->is_sample)
                                            <span class="rounded bg-warning-100 px-1.5 py-0.5 font-semibold text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">Sample</span>
                                        @endif
                                        @if ($item->isDone())
                                            <span class="rounded bg-success-100 px-1.5 py-0.5 text-success-800 dark:bg-success-400/10 dark:text-success-300">Done</span>
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ $search !== '' || $filter !== 'open' ? 'No chats match.' : ($channel === 'headsup' ? 'No open heads-ups. They show up here when operations changes a booking’s ready date or unit.' : 'No open chats. They show up here as soon as a customer messages a branch’s Facebook Page, or operations changes a booking.') }}
                        </li>
                    @endforelse
                </ul>
            </section>

            {{-- Thread --}}
            <section @class(['min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10', 'flex' => $active, 'hidden lg:flex' => ! $active])>
                @if (! $active)
                    <div class="flex flex-1 items-center justify-center p-6 text-sm text-gray-500 dark:text-gray-400">Pick a chat on the left.</div>
                @else
                    <header class="flex flex-wrap items-center gap-3 border-b border-gray-200 p-3 dark:border-white/10">
                        <button type="button" wire:click="close" class="lg:hidden" aria-label="Back to chats">
                            <x-filament::icon icon="heroicon-m-arrow-left" class="size-5 text-gray-500" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 truncate text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $active->displayName() }}
                                @if ($active->is_sample)
                                    <span class="rounded bg-warning-100 px-1.5 py-0.5 text-[11px] font-semibold text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">Sample</span>
                                @endif
                            </p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                @if ($active->isHeadsUp())
                                    <span class="rounded bg-primary-600 px-1.5 py-0.5 font-semibold text-white">Heads-up</span>
                                    @if ($bookingUrl = $this->bookingUrl($active))
                                        <a href="{{ $bookingUrl }}" class="text-primary-700 underline dark:text-primary-400">Booking {{ $active->booking->reference }}</a> ·
                                    @endif
                                    {{ $active->branch?->name }}
                                @else
                                    Messenger · {{ $active->branch?->name }}
                                @endif
                                @if ($active->guest)
                                    @can('view', $active->guest)
                                        · <a href="{{ $this->customerUrl($active->guest) }}" class="text-primary-700 underline dark:text-primary-400">Customer: {{ $active->guest->name }}</a>
                                    @else
                                        · Customer: {{ $active->guest->name }}
                                    @endcan
                                @endif
                            </p>
                        </div>
                        {{ $this->assignAction }}
                        @unless ($active->isHeadsUp())
                            {{ $this->linkCustomerAction }}
                        @endunless
                        <x-filament::button size="sm" color="gray" :icon="$active->isDone() ? 'heroicon-m-arrow-uturn-left' : 'heroicon-m-check'" wire:click="toggleDone">
                            {{ $active->isDone() ? 'Reopen' : 'Mark done' }}
                        </x-filament::button>
                    </header>

                    <div
                        class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-4"
                        x-data
                        x-init="$el.scrollTop = $el.scrollHeight"
                        x-on:inbox-sent.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        wire:key="thread-{{ $active->id }}"
                    >
                        @if ($this->hasOlderMessages())
                            <div class="text-center">
                                <x-filament::link tag="button" size="sm" wire:click="loadOlder">Load older messages</x-filament::link>
                            </div>
                        @endif

                        @foreach ($this->messages->take(-$messageLimit) as $message)
                            <div wire:key="message-{{ $message->id }}" @class(['flex flex-col max-w-[80%] gap-1', 'self-start items-start' => $message->isInbound(), 'self-end items-end' => ! $message->isInbound()])>
                                <div @class([
                                    'rounded-2xl px-3 py-2 text-sm break-words',
                                    'rounded-bl-sm bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-100' => $message->isInbound(),
                                    'rounded-br-sm bg-primary-600 text-white' => ! $message->isInbound() && ! $message->failed,
                                    'rounded-br-sm bg-danger-50 text-danger-900 ring-1 ring-danger-500 dark:bg-danger-500/10 dark:text-danger-200' => $message->failed,
                                ])>
                                    @foreach ($message->attachmentList() as $attachment)
                                        @if (($attachment['type'] ?? null) === 'image' && str_starts_with((string) ($attachment['url'] ?? ''), 'https://'))
                                            <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener noreferrer" class="mb-1 block">
                                                <img src="{{ $attachment['url'] }}" alt="Photo from the customer" class="max-h-60 rounded-lg" loading="lazy" referrerpolicy="no-referrer">
                                            </a>
                                        @elseif (str_starts_with((string) ($attachment['url'] ?? ''), 'https://'))
                                            <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener noreferrer" class="mb-1 block underline">{{ $attachment['name'] ?? ucfirst($attachment['type'] ?? 'attachment') }}</a>
                                        @else
                                            <span class="mb-1 block italic opacity-80">{{ $attachment['name'] ?? ucfirst($attachment['type'] ?? 'attachment') }}</span>
                                        @endif
                                    @endforeach
                                    @if (filled($message->body))
                                        <p class="whitespace-pre-line">{{ $message->body }}</p>
                                    @endif
                                </div>
                                <span class="text-[11px] text-gray-500 dark:text-gray-400">
                                    @if ($active->isHeadsUp())
                                        {{ $message->isInbound() ? 'Change' : 'Internal note' }} · {{ $message->sender?->name ?? 'Team' }} ·
                                    @elseif (! $message->isInbound())
                                        {{ $message->sender?->name ?? ($message->sender_name ? 'via '.$message->sender_name : 'Shop') }} ·
                                    @endif
                                    {{ $message->sent_at->format('M j, g:i A') }}
                                    @if ($message->failed)
                                        · <span class="text-danger-600 dark:text-danger-400">Not sent: {{ $message->error }}</span>
                                    @elseif (! $message->isInbound() && $message->external_id === null && ! $active->isHeadsUp())
                                        · Saved here only, not sent
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <footer class="space-y-2 border-t border-gray-200 p-3 dark:border-white/10">
                        @if ($active->isHeadsUp())
                            <p class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <x-filament::icon icon="heroicon-m-lock-closed" class="size-4" />
                                Internal note to operations. It stays in the system and is never sent to the customer.
                            </p>
                        @endif

                        @if ($this->isPastReplyWindow())
                            <p class="text-xs text-warning-700 dark:text-warning-400">It has been over 24 hours since the customer’s last message. Meta may refuse this reply; if it does, answer in Meta Business Suite.</p>
                        @endif

                        <form wire:submit="send" x-on:submit="$nextTick(() => $dispatch('inbox-sent'))" class="flex items-end gap-2">
                            <div class="flex-1">
                                <x-filament::input.wrapper :valid="! $errors->has('reply')">
                                    <textarea
                                        wire:model="reply"
                                        rows="2"
                                        maxlength="{{ \App\Filament\Pages\Inbox::MAX_REPLY_LENGTH }}"
                                        placeholder="{{ $active->isHeadsUp() ? 'Internal note to operations… (Ctrl+Enter to send)' : 'Reply on Messenger… (Ctrl+Enter to send)' }}"
                                        aria-label="Reply"
                                        x-on:keydown.ctrl.enter.prevent="$wire.send()"
                                        x-on:keydown.meta.enter.prevent="$wire.send()"
                                        class="block w-full resize-y border-none bg-transparent px-3 py-2 text-sm text-gray-950 placeholder:text-gray-400 focus:ring-0 dark:text-white"
                                    ></textarea>
                                </x-filament::input.wrapper>
                                @error('reply')
                                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                @enderror
                            </div>
                            <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled" wire:target="send">Send</x-filament::button>
                        </form>
                    </footer>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
