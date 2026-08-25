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
    <div x-data="{
             modalOpen: $wire.entangle('{{ $wire }}').live,
             closeForAction(event) {
                 const control = event.target.closest('[wire\\:click]');
                 const action = control?.getAttribute('wire:click') ?? '';
                 if (action.startsWith('close') || action === 'acknowledge' || (action.startsWith('$set(') && action.includes('false'))) this.modalOpen = false;
             }
         }"
         x-show="modalOpen"
         x-cloak
         @open-phrems-modal.window="if ($event.detail === '{{ $wire }}') modalOpen = true"
         @click="closeForAction($event)"
         x-transition.opacity.duration.150ms
         class="fixed inset-0 z-[100] flex h-[100dvh] min-h-[100dvh] w-screen items-center justify-center overflow-hidden p-4">
        <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm"
             @click="modalOpen = false"
             @if ($onClose) wire:click="{{ $onClose }}" @endif></div>

        <div x-show="modalOpen"
             x-transition:enter="ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="ease-in duration-100"
             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 scale-95"
             class="{{ $panel }}">
            {{ $slot }}
        </div>
    </div>
@elseif ($show)
    <div
        class="fixed inset-0 z-[100] flex h-[100dvh] min-h-[100dvh] w-screen items-center justify-center overflow-hidden p-4"
        x-data="{
            modalOpen: true,
            closeForAction(event) {
                const control = event.target.closest('[wire\\:click]');
                const action = control?.getAttribute('wire:click') ?? '';
                if (action.startsWith('close') || action === 'acknowledge' || (action.startsWith('$set(') && action.includes('false'))) this.modalOpen = false;
            }
        }"
        x-show="modalOpen"
        @click="closeForAction($event)"
        x-on:close-modal-visual.window="modalOpen = false"
        x-transition:enter.opacity.duration.120ms
        x-transition:leave.opacity.duration.100ms
    >
        <div
            class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm"
            x-on:click="modalOpen = false"
            @if($onClose) wire:click="{{ $onClose }}" @endif
        ></div>

        <div x-data x-show="modalOpen"
             x-transition:enter="ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="ease-in duration-100"
             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 scale-95"
             class="{{ $panel }}">
            {{ $slot }}
        </div>
    </div>
@endif
