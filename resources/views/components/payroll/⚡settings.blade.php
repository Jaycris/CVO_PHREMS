<?php

use App\Models\BirWithholdingBracket;
use App\Models\PagibigRate;
use App\Models\PayrollSetting;
use App\Models\PhilhealthRate;
use App\Models\SssBracket;
use App\Models\StatutoryContributionSetting;
use App\Services\Payroll\SssTableGenerator;
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

    // Rates are typed as percentages, because that is how the circulars are
    // written and how the company will discuss them. They are converted to
    // fractions on save.
    public array $sss = [];
    public array $philhealth = [];
    public array $pagibigLow = [];
    public array $pagibigHigh = [];

    public function mount(): void
    {
        foreach (StatutoryContributionSetting::all() as $setting) {
            $this->cutoffs[$setting->code] = $setting->deduct_on_cutoff;
            $this->active[$setting->code] = (bool) $setting->is_active;
        }

        $this->loadRates();

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

    protected function loadRates(): void
    {
        $generator = new SssTableGenerator();
        $brackets = SssBracket::effectiveOn(now())->get();
        $p = $generator->describe($brackets);

        $this->sss = [
            'employee_rate' => (string) round($p['employee_rate'] * 100, 4),
            'employer_rate' => (string) round($p['employer_rate'] * 100, 4),
            'msc_floor' => (string) $p['msc_floor'],
            'msc_ceiling' => (string) $p['msc_ceiling'],
            'regular_ceiling' => (string) $p['regular_ceiling'],
            'ec_low' => (string) $p['ec_low'],
            'ec_high' => (string) $p['ec_high'],
            'ec_threshold' => (string) $p['ec_threshold'],
        ];

        $ph = PhilhealthRate::effectiveOn(now())->orderByDesc('effective_from')->first();
        $this->philhealth = [
            'premium_rate' => $ph ? (string) round((float) $ph->premium_rate * 100, 4) : '5',
            'employee_share_ratio' => $ph ? (string) round((float) $ph->employee_share_ratio * 100, 4) : '50',
            'salary_floor' => $ph ? (string) (float) $ph->salary_floor : '10000',
            'salary_ceiling' => $ph ? (string) (float) $ph->salary_ceiling : '100000',
        ];

        $bands = PagibigRate::effectiveOn(now())->orderBy('salary_from')->get();
        $low = $bands->first();
        $high = $bands->last();

        $this->pagibigLow = [
            'threshold' => $low ? (string) (float) $low->salary_to : '1500',
            'employee_rate' => $low ? (string) round((float) $low->employee_rate * 100, 4) : '1',
            'employer_rate' => $low ? (string) round((float) $low->employer_rate * 100, 4) : '2',
        ];
        $this->pagibigHigh = [
            'employee_rate' => $high ? (string) round((float) $high->employee_rate * 100, 4) : '2',
            'employer_rate' => $high ? (string) round((float) $high->employer_rate * 100, 4) : '2',
            'max_contribution_base' => $high ? (string) (float) $high->max_contribution_base : '5000',
        ];
    }

    /**
     * Rewrites the rate tables in force today. Earlier effective periods are
     * untouched, so payslips already computed still reproduce their figures.
     */
    public function saveRates(SssTableGenerator $generator): void
    {
        $this->validate([
            'sss.employee_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sss.employer_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sss.msc_floor' => ['required', 'numeric', 'min:1'],
            'sss.msc_ceiling' => ['required', 'numeric', 'gte:sss.msc_floor'],
            'sss.regular_ceiling' => ['required', 'numeric', 'gte:sss.msc_floor'],
            'sss.ec_low' => ['required', 'numeric', 'min:0'],
            'sss.ec_high' => ['required', 'numeric', 'min:0'],
            'sss.ec_threshold' => ['required', 'numeric', 'min:0'],
            'philhealth.premium_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'philhealth.employee_share_ratio' => ['required', 'numeric', 'min:0', 'max:100'],
            'philhealth.salary_floor' => ['required', 'numeric', 'min:0'],
            'philhealth.salary_ceiling' => ['required', 'numeric', 'gte:philhealth.salary_floor'],
            'pagibigLow.threshold' => ['required', 'numeric', 'min:0'],
            'pagibigLow.employee_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'pagibigLow.employer_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'pagibigHigh.employee_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'pagibigHigh.employer_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'pagibigHigh.max_contribution_base' => ['required', 'numeric', 'min:0'],
        ], [], [
            'sss.employee_rate' => 'SSS employee rate',
            'sss.employer_rate' => 'SSS employer rate',
            'sss.msc_floor' => 'lowest salary credit',
            'sss.msc_ceiling' => 'highest salary credit',
            'sss.regular_ceiling' => 'provident fund threshold',
            'philhealth.premium_rate' => 'PhilHealth premium rate',
            'philhealth.employee_share_ratio' => "PhilHealth employee's share",
            'philhealth.salary_ceiling' => 'PhilHealth ceiling',
            'pagibigHigh.max_contribution_base' => 'Pag-IBIG maximum base',
        ]);

        $effectiveFrom = SssBracket::effectiveOn(now())->min('effective_from') ?? now()->startOfYear()->toDateString();

        $rows = $generator->generate([
            'employee_rate' => (float) $this->sss['employee_rate'] / 100,
            'employer_rate' => (float) $this->sss['employer_rate'] / 100,
            'msc_floor' => (float) $this->sss['msc_floor'],
            'msc_ceiling' => (float) $this->sss['msc_ceiling'],
            'regular_ceiling' => (float) $this->sss['regular_ceiling'],
            'ec_low' => (float) $this->sss['ec_low'],
            'ec_high' => (float) $this->sss['ec_high'],
            'ec_threshold' => (float) $this->sss['ec_threshold'],
        ], $effectiveFrom);

        PhilhealthRate::effectiveOn(now())->orderByDesc('effective_from')->first()?->update([
            'premium_rate' => (float) $this->philhealth['premium_rate'] / 100,
            'employee_share_ratio' => (float) $this->philhealth['employee_share_ratio'] / 100,
            'salary_floor' => (float) $this->philhealth['salary_floor'],
            'salary_ceiling' => (float) $this->philhealth['salary_ceiling'],
        ]);

        $bands = PagibigRate::effectiveOn(now())->orderBy('salary_from')->get();

        $bands->first()?->update([
            'salary_to' => (float) $this->pagibigLow['threshold'],
            'employee_rate' => (float) $this->pagibigLow['employee_rate'] / 100,
            'employer_rate' => (float) $this->pagibigLow['employer_rate'] / 100,
            'max_contribution_base' => (float) $this->pagibigHigh['max_contribution_base'],
        ]);

        $bands->last()?->update([
            'salary_from' => (float) $this->pagibigLow['threshold'] + 0.01,
            'employee_rate' => (float) $this->pagibigHigh['employee_rate'] / 100,
            'employer_rate' => (float) $this->pagibigHigh['employer_rate'] / 100,
            'max_contribution_base' => (float) $this->pagibigHigh['max_contribution_base'],
        ]);

        $this->loadRates();
        $this->statusMessage = "Contribution rates saved. The SSS table was rebuilt with {$rows} salary brackets.";
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
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Contribution rates</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">
                SSS, PhilHealth and Pag-IBIG are shared between the employee and the employer, and both sides fund the
                employee's benefits. Only the employee's share is taken off the payslip; the employer's is recorded for
                the remittance forms. Every figure is worked out from monthly basic salary, not from what was earned
                this cutoff — that is how the government tables are published.
            </p>
        </div>

        <div class="space-y-8 p-5">
            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">SSS</p>
                    <p class="text-xs font-medium text-[#778599]">{{ $sssCount }} salary brackets in force</p>
                </div>
                <p class="mt-1 text-sm font-medium text-[#778599]">
                    The salary brackets are rebuilt from these figures when you save, so there is no thirty-row table to retype.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <x-label>Employee pays</x-label>
                        <div class="relative">
                            <x-input wire:model="sss.employee_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('sss.employee_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employer pays</x-label>
                        <div class="relative">
                            <x-input wire:model="sss.employer_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('sss.employer_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Lowest salary credit</x-label>
                        <x-input wire:model="sss.msc_floor" type="number" step="1" min="1" />
                        @error('sss.msc_floor') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Highest salary credit</x-label>
                        <x-input wire:model="sss.msc_ceiling" type="number" step="1" min="1" />
                        @error('sss.msc_ceiling') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <x-label>Provident fund starts above</x-label>
                        <x-input wire:model="sss.regular_ceiling" type="number" step="1" min="0" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">Contributions on salary credit above this go to WISP and are remitted separately.</p>
                        @error('sss.regular_ceiling') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employer EC — lower</x-label>
                        <x-input wire:model="sss.ec_low" type="number" step="0.01" min="0" />
                        @error('sss.ec_low') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employer EC — higher</x-label>
                        <x-input wire:model="sss.ec_high" type="number" step="0.01" min="0" />
                        @error('sss.ec_high') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>EC rises at</x-label>
                        <x-input wire:model="sss.ec_threshold" type="number" step="1" min="0" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">Employee Compensation is paid entirely by the employer.</p>
                        @error('sss.ec_threshold') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="border-t border-neutral-100 pt-6 dark:border-neutral-800">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">PhilHealth</p>
                <p class="mt-1 text-sm font-medium text-[#778599]">
                    The premium is a percentage of monthly basic salary, held between a floor and a ceiling, then split between the two sides.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <x-label>Premium rate</x-label>
                        <div class="relative">
                            <x-input wire:model="philhealth.premium_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('philhealth.premium_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employee's share of it</x-label>
                        <div class="relative">
                            <x-input wire:model="philhealth.employee_share_ratio" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        <p class="mt-1 text-xs font-medium text-[#778599]">50 splits it evenly. The employer covers the rest.</p>
                        @error('philhealth.employee_share_ratio') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Salary floor</x-label>
                        <x-input wire:model="philhealth.salary_floor" type="number" step="1" min="0" />
                        @error('philhealth.salary_floor') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Salary ceiling</x-label>
                        <x-input wire:model="philhealth.salary_ceiling" type="number" step="1" min="0" />
                        @error('philhealth.salary_ceiling') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="border-t border-neutral-100 pt-6 dark:border-neutral-800">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Pag-IBIG</p>
                <p class="mt-1 text-sm font-medium text-[#778599]">
                    Two rate bands by salary. Both sides are held at the maximum by applying their rate to a capped base rather than to actual pay.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div>
                        <x-label>Lower band up to</x-label>
                        <x-input wire:model="pagibigLow.threshold" type="number" step="1" min="0" />
                        @error('pagibigLow.threshold') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employee — lower band</x-label>
                        <div class="relative">
                            <x-input wire:model="pagibigLow.employee_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('pagibigLow.employee_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employer — lower band</x-label>
                        <div class="relative">
                            <x-input wire:model="pagibigLow.employer_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('pagibigLow.employer_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employee — upper band</x-label>
                        <div class="relative">
                            <x-input wire:model="pagibigHigh.employee_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('pagibigHigh.employee_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Employer — upper band</x-label>
                        <div class="relative">
                            <x-input wire:model="pagibigHigh.employer_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                        </div>
                        @error('pagibigHigh.employer_rate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Rates apply to at most</x-label>
                        <x-input wire:model="pagibigHigh.max_contribution_base" type="number" step="1" min="0" />
                        @error('pagibigHigh.max_contribution_base') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="border-t border-neutral-100 pt-6 dark:border-neutral-800">
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
                <p class="mt-2 text-xs font-medium text-[#778599]">
                    Withholding tax is the employee's alone — there is no employer share to split.
                </p>
            </div>
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="saveRates">
                <span wire:loading.remove wire:target="saveRates">Save Rates</span>
                <span wire:loading wire:target="saveRates">Rebuilding table...</span>
            </x-button>
            <p class="mt-2 text-xs font-medium text-[#778599]">
                Saving rewrites the rates in force today. Payslips already computed keep the figures they were
                finalised with.
            </p>
        </div>
    </x-card>
</div>
