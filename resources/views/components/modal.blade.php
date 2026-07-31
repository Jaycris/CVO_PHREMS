@props(['show' => false, 'maxWidth' => 'md', 'onClose' => null])

@php
$maxWidths = [
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
    '4xl' => 'max-w-4xl',
];
@endphp

@if ($show)
    <div class="fixed inset-0 z-40 flex items-center justify-center p-4" x-data x-transition.opacity.duration.150ms>
        <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm" @if($onClose) wire:click="{{ $onClose }}" @endif></div>

        <div x-data x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             @class(['professional-panel relative z-10 max-h-[90vh] w-full overflow-y-auto p-6 shadow-2xl', $maxWidths[$maxWidth] ?? $maxWidths['md']])>
            {{ $slot }}
        </div>
    </div>
@endif
