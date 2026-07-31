@props(['icon', 'variant' => 'neutral', 'as' => 'button', 'disabled' => false])

@php
$variants = [
    'neutral' => 'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-white',
    'brand' => 'text-brand-600 hover:bg-brand-50 hover:text-brand-800 dark:text-brand-300 dark:hover:bg-brand-500/10',
    'danger' => 'text-red-500 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/10',
];
$base = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900';
$disabledClasses = 'pointer-events-none opacity-40';
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => "$base {$variants[$variant]}" . ($disabled ? " $disabledClasses" : '')]) }}>
        <x-icon :name="$icon" class="h-4 w-4" />
    </a>
@else
    <button type="button" @disabled($disabled) {{ $attributes->merge(['class' => "$base {$variants[$variant]}" . ($disabled ? " $disabledClasses" : '')]) }}>
        <x-icon :name="$icon" class="h-4 w-4" />
    </button>
@endif
