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
    Initials remain underneath as a broken-image fallback, but disappear only
    after the photo has loaded successfully. This matters for transparent PNGs:
    without the loaded state, the initials show through the image itself.

    Alpine's listeners are written x-on: rather than with the @ shorthand.
    @error is a Blade directive of its own, so @error="photoLoaded = false"
    compiled as the opening of an error block that never closed, and the whole
    app died with "unexpected end of file, expecting elseif or else or endif".
--}}
<span role="img"
      aria-label="{{ $employee?->fullName() ?: 'Employee' }}"
      x-data="{ photoUrl: @js($url), photoLoaded: false }"
      x-init="$nextTick(() => {
          if ($refs.photo?.complete && $refs.photo.naturalWidth > 0) photoLoaded = true;
      })"
      @if ($reactive)
          x-on:profile-photo-updated.window="photoLoaded = false; photoUrl = $event.detail.url"
      @endif
      {{ $attributes->merge(['class' => "$sizeClasses $tone relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-bold ring-1 ring-black/5 dark:ring-white/10"]) }}>
    <span aria-hidden="true" :class="photoLoaded ? 'opacity-0' : 'opacity-100'">{{ $initials }}</span>

    @if ($reactive)
        <img x-cloak
             x-ref="photo"
             x-show="photoUrl"
             :src="photoUrl || ''"
             x-on:load="photoLoaded = true"
             x-on:error="photoLoaded = false"
             alt=""
             class="absolute inset-0 h-full w-full bg-white object-cover">
    @elseif ($url)
        <img x-ref="photo"
             src="{{ $url }}"
             x-on:load="photoLoaded = true"
             x-on:error="photoLoaded = false"
             alt=""
             class="absolute inset-0 h-full w-full bg-white object-cover">
    @endif
</span>
