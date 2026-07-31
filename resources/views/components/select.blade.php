<select {{ $attributes->merge(['class' => 'block w-full rounded-lg border-ink-200 bg-white px-3.5 py-2.5 text-sm font-medium text-ink-700 shadow-sm transition focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white']) }}>
    {{ $slot }}
</select>
