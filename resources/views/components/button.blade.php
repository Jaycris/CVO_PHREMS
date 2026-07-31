@props(['variant' => 'primary', 'as' => 'button', 'type' => 'submit', 'pill' => false])

@php
$variants = [
    'primary' => 'bg-brand-700 text-white shadow-sm shadow-brand-900/15 hover:bg-brand-800 focus-visible:outline-brand-700',
    'secondary' => 'bg-white font-semibold text-ink-700 shadow-sm ring-1 ring-inset ring-ink-200 hover:bg-ink-50 dark:bg-ink-900 dark:text-ink-200 dark:ring-white/10 dark:hover:bg-white/10',
    'danger' => 'bg-red-600 text-white shadow-sm shadow-red-900/15 hover:bg-red-500 focus-visible:outline-red-600',
    'success' => 'bg-emerald-600 text-white shadow-sm shadow-emerald-900/15 hover:bg-emerald-500 focus-visible:outline-emerald-600',
];
$base = 'inline-flex items-center justify-center gap-2 ' . ($pill ? 'rounded-full' : 'rounded-lg') . ' px-4 py-2.5 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50';
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => "$base {$variants[$variant]}"]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "$base {$variants[$variant]}"]) }}>{{ $slot }}</button>
@endif
