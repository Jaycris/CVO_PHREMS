@props(['user' => null, 'showLabel' => true])

@php
    $online = $user?->isOnline() ?? false;
    $color = $user?->presenceColor() ?? 'neutral';

    $dot = match ($color) {
        'green' => 'bg-emerald-500',
        'red' => 'bg-red-500',
        default => 'bg-ink-300 dark:bg-ink-600',
    };

    $text = match ($color) {
        'green' => 'text-emerald-700 dark:text-emerald-400',
        'red' => 'text-red-700 dark:text-red-400',
        default => 'text-ink-500 dark:text-ink-400',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 whitespace-nowrap']) }}
      title="{{ $user?->presenceLabel() }}">
    <span class="relative flex h-2.5 w-2.5 shrink-0">
        {{-- The halo only pulses while somebody is actually here, so a still
             dot means away rather than a page that stopped updating. --}}
        @if ($online)
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
        @endif
        <span class="relative inline-flex h-2.5 w-2.5 rounded-full {{ $dot }}"></span>
    </span>

    @if ($showLabel)
        <span class="text-xs font-semibold {{ $text }}">{{ $user?->presenceLabel() ?? 'No account' }}</span>
    @endif
</span>
