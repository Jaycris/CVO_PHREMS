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
    {{--
        The switch is called modalOpen, not open.

        Livewire evaluates wire:click inside the surrounding Alpine scope, so a
        variable named `open` here silently swallows wire:click="open" on any
        button in the slot — the expression resolves to this boolean instead of
        calling the component's open() method, and the button does nothing at
        all. A name nobody would give a Livewire action avoids it.
    --}}
    <div x-data="{ modalOpen: $wire.entangle('{{ $wire }}').live }"
         x-show="modalOpen"
         x-cloak
         x-transition.opacity.duration.150ms
         class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm"
             @click="modalOpen = false"
             @if ($onClose) wire:click="{{ $onClose }}" @endif></div>

        <div x-show="modalOpen"
             x-transition:enter="ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             class="{{ $panel }}">
            {{ $slot }}
        </div>
    </div>
@elseif ($show)
    {{-- Named modalOpen for the same reason as the branch above: a variable
         called `open` in scope swallows wire:click="open" and
         wire:submit="open" on anything in the slot. --}}
    <div
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        x-data="{ modalOpen: true }"
        x-show="modalOpen"
        x-on:close-modal-visual.window="modalOpen = false"
        x-transition:enter.opacity.duration.120ms
        x-transition:leave.opacity.duration.100ms
    >
        <div
            class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm"
            x-on:click="modalOpen = false"
            @if($onClose) wire:click="{{ $onClose }}" @endif
        ></div>

        <div x-data x-show="modalOpen" x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0 scale-100" x-transition:leave-end="opacity-0 translate-y-2 scale-95"
             class="{{ $panel }}">
            {{ $slot }}
        </div>
    </div>
@endif
