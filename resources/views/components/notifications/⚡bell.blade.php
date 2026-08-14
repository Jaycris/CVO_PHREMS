<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function markRead(string $notificationId): void
    {
        Auth::user()->notifications()->where('id', $notificationId)->first()?->markAsRead();
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function with(): array
    {
        return [
            'notifications' => Auth::user()->notifications()->latest()->limit(10)->get(),
            'unreadCount' => Auth::user()->unreadNotifications()->count(),
        ];
    }
};
?>

{{--
    Polled rather than pushed.

    True real-time would mean websockets, and those need a process running
    permanently — which shared hosting does not give us. Polling every fifteen
    seconds gets a notification in front of someone while they are still looking
    at the screen, which is what "real-time" has to mean here.

    .visible stops the polling while the tab is in the background, so a browser
    left open all day costs nothing.
--}}
<div class="relative" wire:poll.15s.visible x-data @click.outside="
        $refs.bellPanel.classList.add('opacity-0', 'scale-95', 'pointer-events-none');
        $refs.bellPanel.classList.remove('opacity-100', 'scale-100');
    ">
    <button @click="
            const willOpen = $refs.bellPanel.classList.contains('opacity-0');
            $refs.bellPanel.classList.toggle('opacity-0', !willOpen);
            $refs.bellPanel.classList.toggle('scale-95', !willOpen);
            $refs.bellPanel.classList.toggle('pointer-events-none', !willOpen);
            $refs.bellPanel.classList.toggle('opacity-100', willOpen);
            $refs.bellPanel.classList.toggle('scale-100', willOpen);
        " class="relative flex h-12 w-12 items-center justify-center rounded-xl border border-neutral-200 bg-white font-medium text-[#778599] shadow-sm hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800">
        <x-icon name="bell" stroke-width="2.25" class="h-5 w-5" />
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-[10px] font-semibold text-white">{{ $unreadCount }}</span>
        @endif
    </button>

    <div x-ref="bellPanel"
         class="absolute right-0 z-20 mt-2 w-96 origin-top-right scale-95 rounded-xl border border-neutral-200/70 bg-white opacity-0 shadow-lg transition duration-150 ease-out pointer-events-none dark:border-neutral-800 dark:bg-neutral-900">
        <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
            <div>
                <span class="text-sm font-bold text-[#0f172a] dark:text-white">Notifications</span>
                <p class="text-xs font-medium text-[#778599]">{{ $unreadCount }} unread</p>
            </div>
            @if ($unreadCount > 0)
                <button wire:click="markAllRead" class="text-xs font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Mark all read</button>
            @endif
        </div>
        <div class="max-h-80 overflow-y-auto">
            @forelse ($notifications as $notification)
                @php
                    // Older leave notifications only carry leave_request_id; newer ones
                    // carry an explicit url. Fall back to the bell itself so a
                    // notification type without either can never render a broken link.
                    $target = $notification->data['url']
                        ?? (isset($notification->data['leave_request_id'])
                            ? '/leave-requests/' . $notification->data['leave_request_id']
                            : null);
                @endphp
                <a href="{{ $target ? url($target) : '#' }}"
                   wire:navigate
                   wire:click="markRead('{{ $notification->id }}')"
                   class="group flex gap-3 border-b border-neutral-100 px-4 py-3 text-sm transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50 {{ $notification->read_at ? 'bg-white text-[#778599] dark:bg-neutral-900' : 'bg-brand-50/80 text-[#0f172a] dark:bg-brand-500/10 dark:text-white' }}">
                    <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $notification->read_at ? 'bg-neutral-300 dark:bg-neutral-700' : 'bg-brand-700 dark:bg-brand-300' }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block {{ $notification->read_at ? 'font-medium' : 'font-bold' }}">{{ $notification->data['message'] ?? 'Notification' }}</span>
                        <span class="mt-1 flex items-center gap-2 text-xs font-medium text-[#778599]">
                            <span>{{ $notification->created_at->diffForHumans() }}</span>
                            <span>•</span>
                            <span>{{ $notification->read_at ? 'Read' : 'Unread' }}</span>
                        </span>
                    </span>
                </a>
            @empty
                <p class="px-4 py-8 text-center text-sm font-medium text-[#778599]">No notifications yet.</p>
            @endforelse
        </div>
    </div>
</div>
