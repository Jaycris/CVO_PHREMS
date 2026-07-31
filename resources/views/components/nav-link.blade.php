@props(['href', 'active' => false, 'icon' => null])

<a href="{{ $href }}" wire:navigate
@class([
       'group flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition',
       'bg-brand-700 text-white shadow-sm shadow-brand-900/20 dark:bg-brand-600 dark:text-white' => $active,
       'text-ink-600 hover:bg-ink-100 hover:text-ink-950 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-white' => ! $active,
   ])>
    @if ($icon)
        <x-icon :name="$icon" stroke-width="2" @class(['h-5 w-5 shrink-0', 'text-white' => $active, 'text-ink-400 group-hover:text-ink-700 dark:text-ink-500 dark:group-hover:text-white' => ! $active])/>
    @endif
    <span>{{ $slot }}</span>
</a>
