@props(['label', 'value', 'caption' => null, 'color' => 'brand'])

@php
$captionColors = [
    'brand' => 'text-brand-700 dark:text-brand-300',
    'blue' => 'text-blue-600 dark:text-blue-400',
    'amber' => 'text-amber-600 dark:text-amber-400',
    'red' => 'text-red-600 dark:text-red-400',
    'neutral' => 'font-medium text-ink-500 dark:text-ink-400',
];
@endphp

<x-card class="rounded-2xl">
    <p class="muted-label">{{ $label }}</p>
    <p class="mt-3 text-3xl font-bold tracking-tight text-ink-950 dark:text-white">{{ $value }}</p>
    @if ($caption)
        <p @class(['mt-2 text-sm font-medium', $captionColors[$color] ?? $captionColors['brand']])>{{ $caption }}</p>
    @endif
</x-card>
