<button
    x-data
    @click="
        document.documentElement.classList.toggle('dark');
        localStorage.theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    "
    class="inline-flex h-12 items-center gap-2 rounded-xl border border-ink-200 bg-white px-4 text-sm font-bold text-ink-600 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10"
    title="Toggle dark mode"
>
    <x-icon name="sun" class="hidden h-4 w-4 text-amber-500 dark:block" />
    <x-icon name="moon" class="block h-4 w-4 text-indigo-400 dark:hidden" />
    <span class="hidden dark:inline">Light</span>
    <span class="inline dark:hidden">Dark</span>
</button>
