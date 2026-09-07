@props([
    'items' => [],
    'today' => null,
    'heading' => "What's Happening Today",
])

@php
    $today = $today ?? now('Asia/Manila');
    $items = collect($items);
@endphp

<section class="overflow-hidden rounded-lg border border-ink-200 bg-white shadow-sm dark:border-white/10 dark:bg-ink-900">
    <div class="flex flex-col gap-4 bg-brand-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:bg-white/[0.03]">
        <div class="flex min-w-0 items-center gap-4">
            <div class="today-board-date flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-lg bg-brand-700 text-center shadow-sm">
                <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-brand-100">{{ $today->format('M') }}</span>
                <span class="mt-0.5 text-2xl font-bold leading-none text-white">{{ $today->format('j') }}</span>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-700 dark:text-brand-300">{{ $today->format('l') }}</p>
                <h2 class="mt-1 truncate text-xl font-bold text-ink-950 dark:text-white">{{ $heading }}</h2>
                <p class="mt-1 flex items-center gap-2 text-xs font-medium text-ink-500 dark:text-ink-400">
                    @if ($items->isNotEmpty())
                        <span class="today-board-live-dot h-1.5 w-1.5 shrink-0 rounded-full bg-brand-600 dark:bg-brand-400"></span>
                    @endif
                    {{ $items->isEmpty() ? 'No updates for today' : $items->count() . ' ' . str('update')->plural($items->count()) . ' for today' }}
                </p>
            </div>
        </div>

        @can('announcements.manage')
            <a href="{{ route('announcements.index') }}" wire:navigate class="inline-flex h-10 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-brand-700 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 sm:self-auto dark:bg-brand-600 dark:hover:bg-brand-500">
                <x-icon name="plus" class="h-4 w-4" />
                Post Notice
            </a>
        @endcan
    </div>

    <div class="divide-y divide-ink-100 dark:divide-white/10">
        @forelse ($items as $item)
            @php
                $tones = [
                    'green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
                    'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                    'red' => 'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-300',
                    'blue' => 'bg-blue-50 text-blue-700 dark:bg-blue-400/10 dark:text-blue-300',
                    'brand' => 'bg-brand-50 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300',
                    'neutral' => 'bg-ink-100 text-ink-600 dark:bg-white/10 dark:text-ink-300',
                ];
                $tone = $tones[$item['tone'] ?? 'neutral'] ?? $tones['neutral'];
                $url = $item['url'] ?? null;
            @endphp

            <div class="today-board-item group relative flex items-start gap-4 px-5 py-4 {{ $url ? 'cursor-pointer transition hover:bg-ink-50/80 dark:hover:bg-white/[0.04]' : '' }}" style="--board-item-index: {{ $loop->index }}">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $tone }}">
                    <x-icon :name="$item['icon'] ?? 'bell'" class="h-5 w-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-ink-500 dark:text-ink-400">{{ $item['label'] ?? '' }}</p>
                        @if ($item['pinned'] ?? false)
                            <span class="inline-flex items-center rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-700 dark:bg-brand-400/10 dark:text-brand-300">Pinned</span>
                        @endif
                    </div>
                    <p class="mt-1 text-[17px] font-bold leading-snug text-ink-950 dark:text-white">{{ $item['title'] ?? '' }}</p>
                    @if ($item['detail'] ?? null)
                        <p class="mt-1.5 max-w-5xl whitespace-pre-line text-sm font-medium leading-6 text-ink-600 dark:text-ink-300">{{ $item['detail'] }}</p>
                    @endif
                </div>

                @if ($url)
                    <span class="mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-ink-400 transition group-hover:translate-x-0.5 group-hover:bg-white group-hover:text-brand-700 group-hover:shadow-sm dark:group-hover:bg-white/10 dark:group-hover:text-brand-300">
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </span>

                    {{-- Covers the row so the whole thing is clickable, without
                         nesting the heading and body inside an anchor. --}}
                    <a href="{{ $url }}" wire:navigate class="absolute inset-0 rounded-none focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" aria-label="{{ $item['title'] ?? 'Open' }}"></a>
                @endif
            </div>
        @empty
            <div class="px-5 py-10 text-center">
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-400 dark:bg-white/5 dark:text-ink-500">
                    <x-icon name="sun" class="h-6 w-6" />
                </span>
                <p class="mt-3 text-sm font-bold text-ink-800 dark:text-ink-200">Your board is clear today</p>
                <p class="mt-1 text-xs font-medium text-ink-500 dark:text-ink-400">There are no holidays, company notices, or scheduled events.</p>
            </div>
        @endforelse
    </div>
</section>
