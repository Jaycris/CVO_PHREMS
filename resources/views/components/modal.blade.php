@props(['show' => false, 'maxWidth' => 'md', 'onClose' => null, 'wire' => null])

@php
$maxWidths = [
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
    '4xl' => 'max-w-4xl',
];
$panel = 'professional-panel relative z-10 max-h-[90vh] w-full overflow-y-auto p-6 shadow-2xl ' . ($maxWidths[$maxWidth] ?? $maxWidths['md']);
@endphp

@if ($wire)
    {{--
        Pass `wire="propertyName"` and the modal is built into the page from the
        start, hidden, with Alpine holding the switch.

        Without it the markup only exists once the server has replied, so a
        click opens nothing at all until the round trip lands — which reads as
        the button not having worked. Entangling means the click paints
        immediately and Livewire catches up behind it.
    --}}
    <div x-data="{ open: $wire.entangle('{{ $wire }}').live }"
         x-show="open"
         x-cloak
         x-transition.opacity.duration.150ms
         class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm"
             @click="open = false"
             @if ($onClose) wire:click="{{ $onClose }}" @endif></div>

        <div x-show="open"
             x-transition:enter="ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             class="{{ $panel }}">
            {{ $slot }}
        </div>
    </div>
@elseif ($show)
    <div class="fixed inset-0 z-[100] flex items-center justify-center p-4" x-data x-transition.opacity.duration.150ms>
        <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm" @if($onClose) wire:click="{{ $onClose }}" @endif></div>

        <div x-data x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="{{ $panel }}">
            {{ $slot }}
        </div>
    </div>
@endif
