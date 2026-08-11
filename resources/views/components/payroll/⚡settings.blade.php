<?php

use App\Models\BirWithholdingBracket;
use App\Models\PagibigRate;
use App\Models\PayrollSetting;
use App\Models\PhilhealthRate;
use App\Models\SssBracket;
use App\Models\StatutoryContributionSetting;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?string $statusMessage = null;

    /** @var array<string, string> code => first|second */
    public array $cutoffs = [];
    /** @var array<string, bool> */
    public array $active = [];
    /** @var array<string, string> key => value */
    public array $settings = [];

    public function mount(): void
    {
        foreach (StatutoryContributionSetting::all() as $setting) {
            $this->cutoffs[$setting->code] = $setting->deduct_on_cutoff;
            $this->active[$setting->code] = (bool) $setting->is_active;
        }

        foreach (PayrollSetting::orderBy('group')->get() as $setting) {
            $this->settings[$setting->key] = (string) $setting->value;
        }
    }

    public function saveCutoffs(): void
    {
        foreach (StatutoryContributionSetting::all() as $setting) {
            $setting->update([
                'deduct_on_cutoff' => in_array($this->cutoffs[$setting->code] ?? null, ['first', 'second'], true)
                    ? $this->cutoffs[$setting->code]
                    : $setting->deduct_on_cutoff,
                'is_active' => (bool) ($this->active[$setting->code] ?? false),
            ]);
        }

        $this->statusMessage = 'Contribution schedule saved. It applies the next time a payroll run is computed.';
    }

    public function saveSettings(): void
    {
        foreach (PayrollSetting::all() as $setting) {
            if (! array_key_exists($setting->key, $this->settings)) {
                continue;
            }

            $value = $this->settings[$setting->key];

            $setting->update([
                'value' => $setting->type === 'boolean'
                    ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                    : $value,
            ]);
        }

        $this->statusMessage = 'Payroll policy saved.';
    }

    public function with(): array
    {
        $asOf = now();

        return [
            'contributions' => StatutoryContributionSetting::orderByRaw("field(code,'sss','philhealth','pagibig','bir')")->get(),
            'policyGroups' => PayrollSetting::orderBy('group')->orderBy('id')->get()->groupBy('group'),
            'philhealth' => PhilhealthRate::effectiveOn($asOf)->orderByDesc('effective_from')->first(),
            'pagibigBands' => PagibigRate::effectiveOn($asOf)->orderBy('salary_from')->get(),
            'birBrackets' => BirWithholdingBracket::effectiveOn($asOf)->where('period', 'semi_monthly')->orderBy('income_from')->get(),
            'sssCount' => SssBracket::effectiveOn($asOf)->count(),
            'sssFirst' => SssBracket::effectiveOn($asOf)->orderBy('monthly_salary_credit')->first(),
            'sssLast' => SssBracket::effectiveOn($asOf)->orderByDesc('monthly_salary_credit')->first(),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Payroll Settings</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Government contribution rates and the company's own payroll policy.</p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/20 dark:bg-amber-400/10">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Check these figures before your first real payroll</p>
        <p class="mt-1 text-sm font-medium text-amber-700 dark:text-amber-300">
            SSS, PhilHealth, Pag-IBIG and BIR change their rates by circular, often every year. The figures below are
            a starting point so payroll computes something sensible — they are not a substitute for the current issuances.
        </p>
    </div>

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Which cutoff each contribution comes out of</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">
                The whole month is taken in one go rather than split in half. Spreading the types across the two
                cutoffs keeps either payslip from carrying all of them at once.
            </p>
        </div>

        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @foreach ($contributions as $contribution)
                <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-4" wire:key="cut-{{ $contribution->code }}">
                    <div class="min-w-40">
                        <p class="text-sm font-bold text-[#0f172a] dark:text-white">{{ $contribution->label() }}</p>
                        @if ($contribution->code === 'bir')
                            <p class="text-xs font-medium text-[#778599]">Withheld on every payslip, on what was actually paid.</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                            <input type="checkbox" wire:model="active.{{ $contribution->code }}"
                                   class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                            Deduct this
                        </label>

                        <div class="w-64">
                            <x-select wire:model="cutoffs.{{ $contribution->code }}" :disabled="$contribution->code === 'bir'">
                                <option value="first">First cutoff — paid on the 15th</option>
                                <option value="second">Second cutoff — paid on the 30th</option>
                            </x-select>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="saveCutoffs">Save Schedule</x-button>
        </div>
    </x-card>

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Company payroll policy</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">Figures the company sets, as opposed to anything the government mandates.</p>
        </div>

        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @foreach ($policyGroups as $group => $items)
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
                                <div class="w-40 shrink-0">
                                    @if ($setting->type === 'boolean')
                                        <x-select wire:model="settings.{{ $setting->key }}">
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </x-select>
                                    @elseif ($setting->type === 'time')
                                        <x-input wire:model="settings.{{ $setting->key }}" type="time" />
                                    @else
                                        <x-input wire:model="settings.{{ $setting->key }}" type="text" />
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="saveSettings">Save Policy</x-button>
        </div>
    </x-card>

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Current rates in use</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">
                Every contribution is worked out from the employee's monthly basic salary, not from what they earned
                this cutoff — that is how the government tables are published.
            </p>
        </div>

        <div class="space-y-6 p-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">SSS</p>
                @if ($sssFirst && $sssLast)
                    <p class="mt-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                        {{ $sssCount }} salary brackets, from a monthly salary credit of
                        ₱{{ number_format((float) $sssFirst->monthly_salary_credit, 2) }}
                        to ₱{{ number_format((float) $sssLast->monthly_salary_credit, 2) }}.
                        The employee pays ₱{{ number_format((float) $sssFirst->employee_share, 2) }} at the bottom and
                        ₱{{ number_format((float) $sssLast->employee_share + (float) $sssLast->employee_mpf_share, 2) }} at the top.
                    </p>
                    <p class="mt-1 text-xs font-medium text-[#778599]">
                        Anything above the regular ceiling goes to the provident fund (WISP) and is remitted separately.
                        Salaries under the lowest bracket are treated as the lowest; over the highest, as the highest.
                    </p>
                @else
                    <p class="mt-2 text-sm font-medium text-red-600">No SSS brackets loaded — payroll would deduct nothing.</p>
                @endif
            </div>

            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">PhilHealth</p>
                @if ($philhealth)
                    <p class="mt-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                        {{ rtrim(rtrim(number_format((float) $philhealth->premium_rate * 100, 2), '0'), '.') }}% of monthly basic salary,
                        held between ₱{{ number_format((float) $philhealth->salary_floor, 2) }} and ₱{{ number_format((float) $philhealth->salary_ceiling, 2) }}.
                        The employee pays {{ rtrim(rtrim(number_format((float) $philhealth->employee_share_ratio * 100, 2), '0'), '.') }}% of the premium.
                    </p>
                @else
                    <p class="mt-2 text-sm font-medium text-red-600">No PhilHealth rate loaded.</p>
                @endif
            </div>

            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Pag-IBIG</p>
                <div class="mt-2 space-y-1">
                    @forelse ($pagibigBands as $band)
                        <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">
                            ₱{{ number_format((float) $band->salary_from, 2) }}
                            {{ $band->salary_to ? 'to ₱' . number_format((float) $band->salary_to, 2) : 'and above' }} —
                            employee {{ rtrim(rtrim(number_format((float) $band->employee_rate * 100, 2), '0'), '.') }}%,
                            employer {{ rtrim(rtrim(number_format((float) $band->employer_rate * 100, 2), '0'), '.') }}%,
                            applied to at most ₱{{ number_format((float) $band->max_contribution_base, 2) }}.
                        </p>
                    @empty
                        <p class="text-sm font-medium text-red-600">No Pag-IBIG rates loaded.</p>
                    @endforelse
                </div>
            </div>

            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Withholding Tax — semi-monthly</p>
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                        <thead>
                            <tr>
                                <th class="py-2 pr-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Taxable income</th>
                                <th class="py-2 pr-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Tax</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($birBrackets as $bracket)
                                <tr>
                                    <td class="py-2 pr-4 font-medium text-[#65758c] dark:text-neutral-300">
                                        ₱{{ number_format((float) $bracket->income_from, 2) }}
                                        {{ $bracket->income_to ? '– ₱' . number_format((float) $bracket->income_to, 2) : 'and above' }}
                                    </td>
                                    <td class="py-2 pr-4 font-medium text-[#778599]">
                                        @if ((float) $bracket->excess_rate === 0.0 && (float) $bracket->base_tax === 0.0)
                                            No tax
                                        @else
                                            ₱{{ number_format((float) $bracket->base_tax, 2) }} +
                                            {{ rtrim(rtrim(number_format((float) $bracket->excess_rate * 100, 2), '0'), '.') }}%
                                            of anything over ₱{{ number_format((float) $bracket->income_from, 2) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-card>
</div>
