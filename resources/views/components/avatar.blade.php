@props([
    'employee' => null,
    'size' => 'md',
    'reactive' => false,
])

@php
    $sizes = [
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-10 w-10 text-sm',
        'lg' => 'h-16 w-16 text-lg',
        'xl' => 'h-24 w-24 text-2xl',
    ];
    $sizeClasses = $sizes[$size] ?? $sizes['md'];

    $url = $employee?->photoUrl();
    $initials = $employee?->initials() ?? '?';

    // Deterministic colour per person so the same employee always looks the
    // same, rather than shuffling on every render.
    $palette = [
        'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200',
        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
        'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    ];
    $seed = $employee?->employee_id ?? $initials;
    $tone = $palette[crc32((string) $seed) % count($palette)];
@endphp

{{--
    The initials are always drawn, and the photo sits on top of them.

    The photo used to be an <img> on its own, carrying the person's name as its
    alt text. When the file did not load — a missing storage symlink on the
    server was enough — the browser painted that alt text instead, so "Jane Rose
    Ledesma" appeared wrapped across the little circle. Layering means a photo
    that fails to load simply reveals the initials underneath, with no script
    and nothing to go wrong.
--}}
<span role="img"
      aria-label="{{ $employee?->fullName() ?: 'Employee' }}"
      @if ($reactive)
          x-data="{ photoUrl: @js($url) }"
          @profile-photo-updated.window="photoUrl = $event.detail.url"
      @endif
      {{ $attributes->merge(['class' => "$sizeClasses $tone relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-bold ring-1 ring-black/5 dark:ring-white/10"]) }}>
    <span aria-hidden="true">{{ $initials }}</span>

    @if ($reactive)
        <img x-cloak
             x-show="photoUrl"
             :src="photoUrl || ''"
             alt=""
             class="absolute inset-0 h-full w-full object-cover">
    @elseif ($url)
        <img src="{{ $url }}" alt="" class="absolute inset-0 h-full w-full object-cover">
    @endif
</span>
