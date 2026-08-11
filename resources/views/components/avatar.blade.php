@props([
    'employee' => null,
    'size' => 'md',
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

@if ($url)
    <img src="{{ $url }}"
         alt="{{ $employee?->fullName() ?: 'Employee photo' }}"
         {{ $attributes->merge(['class' => "$sizeClasses shrink-0 rounded-full object-cover ring-1 ring-black/5 dark:ring-white/10"]) }}>
@else
    <span {{ $attributes->merge(['class' => "$sizeClasses $tone inline-flex shrink-0 items-center justify-center rounded-full font-bold ring-1 ring-black/5 dark:ring-white/10"]) }}
          aria-hidden="true">{{ $initials }}</span>
@endif
