<div
    id="global-action-loader"
    class="pointer-events-none fixed inset-0 z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-150"
    aria-hidden="true"
    aria-live="polite"
>
    <div class="absolute inset-0 bg-ink-950/45 backdrop-blur-sm"></div>

    <div
        data-action-loader-panel
        class="professional-panel relative z-10 flex min-w-64 translate-y-2 scale-95 items-center gap-4 rounded-lg px-6 py-5 opacity-0 shadow-2xl transition duration-150"
    >
        <div class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
            <span class="absolute h-8 w-8 animate-spin rounded-full border-2 border-brand-200 border-t-brand-700 dark:border-brand-500/20 dark:border-t-brand-300"></span>
            <img src="{{ asset('images/logo-mark.png') }}" alt="" class="h-5 w-5 object-contain">
        </div>

        <div>
            <p class="text-base font-bold text-ink-950 dark:text-white">Processing...</p>
            <p class="mt-0.5 text-sm font-medium text-ink-500 dark:text-ink-400">Please wait a moment.</p>
        </div>
    </div>
</div>
