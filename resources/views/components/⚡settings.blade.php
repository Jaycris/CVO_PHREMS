<?php

use App\Models\AppSetting;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * App-wide display preferences, separate from Payroll Settings.
 *
 * Payroll Settings belongs to whoever owns the government rates; this belongs
 * to whoever administers the app. Keeping them apart means neither screen
 * needs the other's permission.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** @var array<string, string> */
    public array $settings = [];

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->loadSettings();
    }

    protected function loadSettings(): void
    {
        $this->settings = AppSetting::query()
            ->orderBy('group')
            ->orderBy('id')
            ->pluck('value', 'key')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    public function save(): void
    {
        $this->validate([
            'settings.rows_per_page' => ['required', 'integer', 'in:' . implode(',', AppSetting::ROWS_PER_PAGE_CHOICES)],
        ], [], [
            'settings.rows_per_page' => 'rows per page',
        ]);

        foreach ($this->settings as $key => $value) {
            AppSetting::where('key', $key)->update(['value' => $value]);
        }

        // The mass update above bypasses model events, so nothing has cleared
        // the memo or the cache. Without this the old size stays in effect
        // until the next request that happens to miss the cache.
        AppSetting::flushCache();

        $this->statusMessage = 'Settings saved.';
    }

    public function with(): array
    {
        return [
            'groups' => AppSetting::query()
                ->orderBy('group')
                ->orderBy('id')
                ->get()
                ->groupBy('group'),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">System Settings</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            App-wide preferences. These apply to everyone.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $statusMessage }}
        </div>
    @endif

    <x-card :padding="false">
        <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
            @foreach ($groups as $group => $items)
                <div class="px-5 py-4">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">{{ $group }}</p>

                    <div class="mt-3 space-y-4">
                        @foreach ($items as $setting)
                            <div class="flex flex-wrap items-start justify-between gap-4" wire:key="set-{{ $setting->key }}">
                                <div class="max-w-xl">
                                    <p class="text-sm font-medium text-[#65758c] dark:text-white">{{ $setting->label }}</p>
                                    @if ($setting->description)
                                        <p class="mt-0.5 text-xs font-medium text-[#778599]">{{ $setting->description }}</p>
                                    @endif
                                </div>

                                <div class="w-48 shrink-0">
                                    @if ($setting->key === 'rows_per_page')
                                        <x-select wire:model="settings.rows_per_page">
                                            @foreach (App\Models\AppSetting::ROWS_PER_PAGE_CHOICES as $choice)
                                                <option value="{{ $choice }}">{{ $choice }} rows</option>
                                            @endforeach
                                        </x-select>
                                    @elseif ($setting->type === 'boolean')
                                        <x-select wire:model="settings.{{ $setting->key }}">
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </x-select>
                                    @else
                                        <x-input wire:model="settings.{{ $setting->key }}" type="text" />
                                    @endif

                                    @error('settings.' . $setting->key)
                                        <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="save">Save</x-button>
        </div>
    </x-card>

    <div class="rounded-xl bg-[#f8fafc] p-5 dark:bg-neutral-800/50">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">About rows per page</p>
        <p class="mt-2 max-w-2xl text-sm font-medium text-[#65758c] dark:text-neutral-300">
            This applies to every table in the app at once — Employees, Leave Requests, the Cash Advance Record,
            the payslips inside a payroll run, and the rest. A larger number means fewer clicks but a slower page,
            which matters most on the payroll run screen where every row carries a full set of figures.
        </p>
    </div>
</div>
