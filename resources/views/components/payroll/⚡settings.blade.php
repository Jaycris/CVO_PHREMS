<?php

use App\Models\BirWithholdingBracket;
use App\Models\PagibigRate;
use App\Models\PayrollChangeLog;
use App\Models\PayrollSetting;
use App\Models\PhilhealthRate;
use App\Models\SssBracket;
use App\Models\StatutoryContributionSetting;
use App\Services\Payroll\SssTableGenerator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?string $statusMessage = null;
    public bool $showAdvanced = false;

    /** @var array<string, string> code => first|second */
    public array $cutoffs = [];
    /** @var array<string, bool> */
    public array $active = [];
    /** @var array<string, string> key => value */
    public array $settings = [];

    /**
     * The everyday figures: what each side pays, as a straight percentage of
     * monthly salary.
     *
     * PhilHealth is stored by the government as one premium and a split ratio,
     * but nobody discusses it that way — they say "we each pay 2.5%". The two
     * are converted on the way in and out.
     *
     * @var array<string, array<string, string>>
     */
    public array $rates = [];

    /**
     * The rest of the government's parameters. Real, but set by circular and
     * almost never touched, so they stay out of the way until asked for.
     *
     * @var array<string, string>
     */
    public array $advanced = [];

    /** @var list<array{income_from: string, income_to: string, base_tax: string, excess_rate: string}> */
    public array $taxBrackets = [];

    public function mount(): void
    {
        foreach (StatutoryContributionSetting::all() as $setting) {
            $this->cutoffs[$setting->code] = $setting->deduct_on_cutoff;
            $this->active[$setting->code] = (bool) $setting->is_active;
        }

        foreach (PayrollSetting::orderBy('group')->get() as $setting) {
            $this->settings[$setting->key] = (string) $setting->value;
        }

        $this->loadRates();
        $this->loadTaxBrackets();
    }

    // -----------------------------------------------------------------
    // What gets deducted
    // -----------------------------------------------------------------

    public function saveCutoffs(): void
    {
        $before = $this->snapshot();

        foreach (StatutoryContributionSetting::all() as $setting) {
            $setting->update([
                'deduct_on_cutoff' => in_array($this->cutoffs[$setting->code] ?? null, ['first', 'second'], true)
                    ? $this->cutoffs[$setting->code]
                    : $setting->deduct_on_cutoff,
                'is_active' => (bool) ($this->active[$setting->code] ?? false),
            ]);
        }

        PayrollChangeLog::record($before, $this->snapshot());
        $this->statusMessage = 'Saved. It applies the next time payroll is computed.';
    }

    // -----------------------------------------------------------------
    // Rates
    // -----------------------------------------------------------------

    protected function loadRates(): void
    {
        $pct = fn ($v) => (string) round((float) $v * 100, 4);

        $p = (new SssTableGenerator)->describe(SssBracket::effectiveOn(now())->get());

        $ph = PhilhealthRate::effectiveOn(now())->orderByDesc('effective_from')->first();
        $phRate = $ph ? (float) $ph->premium_rate : 0.05;
        $phShare = $ph ? (float) $ph->employee_share_ratio : 0.5;

        $bands = PagibigRate::effectiveOn(now())->orderBy('salary_from')->get();
        $low = $bands->first();
        $high = $bands->last();

        $this->rates = [
            'sss' => ['ee' => $pct($p['employee_rate']), 'er' => $pct($p['employer_rate'])],
            'philhealth' => ['ee' => $pct($phRate * $phShare), 'er' => $pct($phRate * (1 - $phShare))],
            'pagibig' => [
                'ee' => $high ? $pct($high->employee_rate) : '2',
                'er' => $high ? $pct($high->employer_rate) : '2',
            ],
        ];

        $this->advanced = [
            'sss_msc_floor' => (string) $p['msc_floor'],
            'sss_msc_ceiling' => (string) $p['msc_ceiling'],
            'sss_regular_ceiling' => (string) $p['regular_ceiling'],
            'sss_ec_low' => (string) $p['ec_low'],
            'sss_ec_high' => (string) $p['ec_high'],
            'sss_ec_threshold' => (string) $p['ec_threshold'],
            'ph_floor' => $ph ? (string) (float) $ph->salary_floor : '10000',
            'ph_ceiling' => $ph ? (string) (float) $ph->salary_ceiling : '100000',
            'pi_threshold' => $low ? (string) (float) $low->salary_to : '1500',
            'pi_low_ee' => $low ? $pct($low->employee_rate) : '1',
            'pi_low_er' => $low ? $pct($low->employer_rate) : '2',
            'pi_cap' => $high ? (string) (float) $high->max_contribution_base : '5000',
        ];
    }

    /**
     * Rewrites the rates in force today. Earlier periods are untouched, so
     * payslips already finalised still reproduce their figures.
     */
    public function saveRates(SssTableGenerator $generator): void
    {
        $percent = ['required', 'numeric', 'min:0', 'max:100'];
        $amount = ['required', 'numeric', 'min:0'];

        $this->validate([
            'rates.sss.ee' => $percent, 'rates.sss.er' => $percent,
            'rates.philhealth.ee' => $percent, 'rates.philhealth.er' => $percent,
            'rates.pagibig.ee' => $percent, 'rates.pagibig.er' => $percent,
            'advanced.sss_msc_floor' => ['required', 'numeric', 'min:1'],
            'advanced.sss_msc_ceiling' => ['required', 'numeric', 'gte:advanced.sss_msc_floor'],
            'advanced.sss_regular_ceiling' => ['required', 'numeric', 'gte:advanced.sss_msc_floor'],
            'advanced.sss_ec_low' => $amount, 'advanced.sss_ec_high' => $amount, 'advanced.sss_ec_threshold' => $amount,
            'advanced.ph_floor' => $amount,
            'advanced.ph_ceiling' => ['required', 'numeric', 'gte:advanced.ph_floor'],
            'advanced.pi_threshold' => $amount, 'advanced.pi_cap' => $amount,
            'advanced.pi_low_ee' => $percent, 'advanced.pi_low_er' => $percent,
        ], [], [
            'rates.sss.ee' => 'SSS employee rate', 'rates.sss.er' => 'SSS employer rate',
            'rates.philhealth.ee' => 'PhilHealth employee rate', 'rates.philhealth.er' => 'PhilHealth employer rate',
            'rates.pagibig.ee' => 'Pag-IBIG employee rate', 'rates.pagibig.er' => 'Pag-IBIG employer rate',
            'advanced.sss_msc_ceiling' => 'highest salary credit',
            'advanced.ph_ceiling' => 'PhilHealth ceiling',
        ]);

        $before = $this->snapshot();
        $a = $this->advanced;

        $effectiveFrom = SssBracket::effectiveOn(now())->min('effective_from') ?? now()->startOfYear()->toDateString();

        $generator->generate([
            'employee_rate' => (float) $this->rates['sss']['ee'] / 100,
            'employer_rate' => (float) $this->rates['sss']['er'] / 100,
            'msc_floor' => (float) $a['sss_msc_floor'],
            'msc_ceiling' => (float) $a['sss_msc_ceiling'],
            'regular_ceiling' => (float) $a['sss_regular_ceiling'],
            'ec_low' => (float) $a['sss_ec_low'],
            'ec_high' => (float) $a['sss_ec_high'],
            'ec_threshold' => (float) $a['sss_ec_threshold'],
        ], $effectiveFrom);

        // Back to the government's shape: one premium, split by ratio.
        $phEe = (float) $this->rates['philhealth']['ee'];
        $phEr = (float) $this->rates['philhealth']['er'];
        $phTotal = $phEe + $phEr;

        PhilhealthRate::effectiveOn(now())->orderByDesc('effective_from')->first()?->update([
            'premium_rate' => $phTotal / 100,
            'employee_share_ratio' => $phTotal > 0 ? $phEe / $phTotal : 0.5,
            'salary_floor' => (float) $a['ph_floor'],
            'salary_ceiling' => (float) $a['ph_ceiling'],
        ]);

        $bands = PagibigRate::effectiveOn(now())->orderBy('salary_from')->get();

        $bands->first()?->update([
            'salary_to' => (float) $a['pi_threshold'],
            'employee_rate' => (float) $a['pi_low_ee'] / 100,
            'employer_rate' => (float) $a['pi_low_er'] / 100,
            'max_contribution_base' => (float) $a['pi_cap'],
        ]);

        $bands->last()?->update([
            'salary_from' => (float) $a['pi_threshold'] + 0.01,
            'employee_rate' => (float) $this->rates['pagibig']['ee'] / 100,
            'employer_rate' => (float) $this->rates['pagibig']['er'] / 100,
            'max_contribution_base' => (float) $a['pi_cap'],
        ]);

        PayrollChangeLog::record($before, $this->snapshot());
        $this->loadRates();
        $this->statusMessage = 'Rates saved.';
    }

    // -----------------------------------------------------------------
    // Withholding tax
    // -----------------------------------------------------------------

    protected function loadTaxBrackets(): void
    {
        $this->taxBrackets = BirWithholdingBracket::effectiveOn(now())
            ->where('period', 'semi_monthly')
            ->orderBy('income_from')
            ->get()
            ->map(fn (BirWithholdingBracket $b) => [
                'income_from' => (string) (float) $b->income_from,
                'income_to' => $b->income_to === null ? '' : (string) (float) $b->income_to,
                'base_tax' => (string) (float) $b->base_tax,
                'excess_rate' => (string) round((float) $b->excess_rate * 100, 4),
            ])
            ->values()
            ->all();
    }

    public function addTaxBracket(): void
    {
        $this->taxBrackets[] = ['income_from' => '', 'income_to' => '', 'base_tax' => '0', 'excess_rate' => '0'];
    }

    public function removeTaxBracket(int $index): void
    {
        unset($this->taxBrackets[$index]);
        $this->taxBrackets = array_values($this->taxBrackets);
    }

    public function saveTaxBrackets(): void
    {
        $this->validate([
            'taxBrackets' => ['required', 'array', 'min:1'],
            'taxBrackets.*.income_from' => ['required', 'numeric', 'min:0'],
            'taxBrackets.*.income_to' => ['nullable', 'numeric', 'min:0'],
            'taxBrackets.*.base_tax' => ['required', 'numeric', 'min:0'],
            'taxBrackets.*.excess_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [], [
            'taxBrackets.*.income_from' => 'bracket start',
            'taxBrackets.*.base_tax' => 'base tax',
            'taxBrackets.*.excess_rate' => 'rate on excess',
        ]);

        $rows = collect($this->taxBrackets)
            ->map(fn (array $r) => [
                'from' => (float) $r['income_from'],
                'to' => $r['income_to'] === '' ? null : (float) $r['income_to'],
                'base' => (float) $r['base_tax'],
                'rate' => (float) $r['excess_rate'] / 100,
            ])
            ->sortBy('from')
            ->values();

        foreach ($rows as $i => $row) {
            if ($row['to'] !== null && $row['to'] < $row['from']) {
                $this->addError('taxBrackets', 'A row cannot end below where it starts. Check row ' . ($i + 1) . '.');

                return;
            }
        }

        // Without an open-ended last row, anyone earning above it falls in no
        // row at all and has nothing withheld.
        if ($rows->last()['to'] !== null) {
            $this->addError('taxBrackets', 'Leave the last row\'s "To" blank so the highest earners are still covered.');

            return;
        }

        $before = $this->snapshot();
        $effectiveFrom = BirWithholdingBracket::effectiveOn(now())->min('effective_from') ?? now()->startOfYear()->toDateString();

        DB::transaction(function () use ($rows, $effectiveFrom) {
            BirWithholdingBracket::whereDate('effective_from', $effectiveFrom)->where('period', 'semi_monthly')->delete();

            BirWithholdingBracket::insert($rows->map(fn (array $r) => [
                'period' => 'semi_monthly',
                'income_from' => $r['from'], 'income_to' => $r['to'],
                'base_tax' => $r['base'], 'excess_rate' => $r['rate'],
                'effective_from' => $effectiveFrom, 'effective_to' => null,
                'created_at' => now(), 'updated_at' => now(),
            ])->all());
        });

        PayrollChangeLog::record($before, $this->snapshot());
        $this->loadTaxBrackets();
        $this->statusMessage = 'Tax table saved.';
    }

    // -----------------------------------------------------------------
    // Company policy
    // -----------------------------------------------------------------

    public function saveSettings(): void
    {
        $before = $this->snapshot();

        foreach (PayrollSetting::all() as $setting) {
            if (! array_key_exists($setting->key, $this->settings)) {
                continue;
            }

            $setting->update([
                'value' => $setting->type === 'boolean'
                    ? (filter_var($this->settings[$setting->key], FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                    : $this->settings[$setting->key],
            ]);
        }

        PayrollChangeLog::record($before, $this->snapshot());
        $this->statusMessage = 'Payroll policy saved.';
    }

    /**
     * The page as displayed, taken before and after a save so the change log
     * records only what really moved.
     *
     * @return array<string, array<string, string>>
     */
    protected function snapshot(): array
    {
        $money = fn ($v) => '₱' . number_format((float) $v, 2);
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v * 100, 4), '0'), '.') . '%';

        $snapshot = ['Deductions' => [], 'Payroll Policy' => []];

        foreach (StatutoryContributionSetting::all() as $setting) {
            $snapshot['Deductions'][$setting->label() . ' — deducted'] = $setting->is_active ? 'Yes' : 'No';
            $snapshot['Deductions'][$setting->label() . ' — cutoff'] =
                $setting->deduct_on_cutoff === 'first' ? 'First (15th)' : 'Second (30th)';
        }

        foreach (PayrollSetting::orderBy('id')->get() as $setting) {
            $snapshot['Payroll Policy'][$setting->label] = $setting->type === 'boolean'
                ? (filter_var($setting->value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No')
                : (string) $setting->value;
        }

        $p = (new SssTableGenerator)->describe(SssBracket::effectiveOn(now())->get());
        $snapshot['SSS'] = [
            'Employee pays' => $pct($p['employee_rate']),
            'Employer pays' => $pct($p['employer_rate']),
            'Lowest salary credit' => $money($p['msc_floor']),
            'Highest salary credit' => $money($p['msc_ceiling']),
            'Savings fund starts above' => $money($p['regular_ceiling']),
            'Work injury insurance — lower' => $money($p['ec_low']),
            'Work injury insurance — higher' => $money($p['ec_high']),
            'Work injury insurance rises at' => $money($p['ec_threshold']),
        ];

        if ($ph = PhilhealthRate::effectiveOn(now())->orderByDesc('effective_from')->first()) {
            $rate = (float) $ph->premium_rate;
            $share = (float) $ph->employee_share_ratio;
            $snapshot['PhilHealth'] = [
                'Employee pays' => $pct($rate * $share),
                'Employer pays' => $pct($rate * (1 - $share)),
                'Lowest salary charged' => $money($ph->salary_floor),
                'Highest salary charged' => $money($ph->salary_ceiling),
            ];
        }

        $bands = PagibigRate::effectiveOn(now())->orderBy('salary_from')->get();
        if ($bands->isNotEmpty()) {
            $low = $bands->first();
            $high = $bands->last();
            $snapshot['Pag-IBIG'] = [
                'Employee pays' => $pct($high->employee_rate),
                'Employer pays' => $pct($high->employer_rate),
                'Rates apply to at most' => $money($high->max_contribution_base),
                'Reduced rate applies up to' => $money($low->salary_to),
                'Employee pays below that' => $pct($low->employee_rate),
                'Employer pays below that' => $pct($low->employer_rate),
            ];
        }

        $snapshot['Withholding Tax'] = [];
        foreach (BirWithholdingBracket::effectiveOn(now())->where('period', 'semi_monthly')->orderBy('income_from')->get()->values() as $i => $b) {
            $n = $i + 1;
            $snapshot['Withholding Tax']["Row {$n} — from"] = $money($b->income_from);
            $snapshot['Withholding Tax']["Row {$n} — to"] = $b->income_to === null ? 'and above' : $money($b->income_to);
            $snapshot['Withholding Tax']["Row {$n} — base tax"] = $money($b->base_tax);
            $snapshot['Withholding Tax']["Row {$n} — rate on excess"] = $pct($b->excess_rate);
        }

        return $snapshot;
    }

    public function with(): array
    {
        return [
            'contributions' => StatutoryContributionSetting::orderByRaw("field(code,'sss','philhealth','pagibig','bir')")->get(),
            'policyGroups' => PayrollSetting::orderBy('group')->orderBy('id')->get()->groupBy('group'),
            'changeLog' => PayrollChangeLog::latest()->limit(30)->get(),
        ];
    }
};
?>

@php
    $contributionRows = [
        'sss' => 'SSS',
        'philhealth' => 'PhilHealth',
        'pagibig' => 'Pag-IBIG',
    ];
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Payroll Settings</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Government deductions and the company's own payroll rules.</p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    {{-- 1. On or off. The only thing most companies ever touch here. --}}
    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">What gets deducted</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">Anything switched off is not taken from anyone's pay.</p>
        </div>

        @if ($contributions->every(fn ($c) => ! $c->is_active))
            <div class="border-b border-neutral-200 bg-[#f8fafc] px-5 py-3 dark:border-neutral-800 dark:bg-neutral-800/50">
                <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">
                    Nothing is being deducted right now. Payslips show gross pay with no government deductions.
                </p>
            </div>
        @endif

        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @foreach ($contributions as $contribution)
                <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-4" wire:key="cut-{{ $contribution->code }}">
                    <label class="flex cursor-pointer items-center gap-3">
                        <input type="checkbox" wire:model.live="active.{{ $contribution->code }}"
                               class="h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                        <span class="text-sm font-bold text-[#0f172a] dark:text-white">{{ $contribution->label() }}</span>
                        <x-badge :color="($active[$contribution->code] ?? false) ? 'green' : 'neutral'">
                            {{ ($active[$contribution->code] ?? false) ? 'Deducting' : 'Off' }}
                        </x-badge>
                    </label>

                    @if ($contribution->code !== 'bir')
                        <div class="w-56">
                            <x-select wire:model="cutoffs.{{ $contribution->code }}" :disabled="! ($active[$contribution->code] ?? false)">
                                <option value="first">Taken on the 15th</option>
                                <option value="second">Taken on the 30th</option>
                            </x-select>
                        </div>
                    @else
                        <span class="text-xs font-medium text-[#778599]">Taken on every payslip</span>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="saveCutoffs">Save</x-button>
        </div>
    </x-card>

    {{-- 2. Who pays what. Percentages of monthly salary, nothing else. --}}
    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Who pays what</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">
                Percentage of monthly basic salary. Only the employee's share comes off the payslip — the employer's
                is the company's own cost.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]"></th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee pays</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employer pays</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($contributionRows as $code => $label)
                        <tr wire:key="rate-{{ $code }}">
                            <td class="px-5 py-3 font-bold text-[#0f172a] dark:text-white">{{ $label }}</td>
                            @foreach (['ee', 'er'] as $side)
                                <td class="px-5 py-3">
                                    <div class="relative w-28">
                                        <x-input wire:model="rates.{{ $code }}.{{ $side }}" type="number" step="0.01" min="0" max="100" class="pr-8" />
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                                    </div>
                                    @error("rates.{$code}.{$side}") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button wire:click="saveRates">Save Rates</x-button>
            <button type="button" wire:click="$toggle('showAdvanced')"
                    class="ml-3 text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">
                {{ $showAdvanced ? 'Hide' : 'Edit' }} the withholding tax table
            </button>
        </div>
    </x-card>

    {{--
        The agencies' own parameters — salary credit range, floors, ceilings,
        contribution caps — are deliberately not on this screen.

        They are real and payroll uses them, but they are set by circular rather
        than by the company, change once every few years, and were the part
        nobody here could read. They live in the database and are seeded from
        StatutorySeeder; a circular that moves them is a code change, which is
        the right trade for a screen the company can actually use.
    --}}
    @if ($showAdvanced)

        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Withholding tax table</h2>
                <p class="mt-1 text-sm font-medium text-[#778599]">
                    Tax is not one rate. Each row covers a range of pay: a fixed amount, plus a percentage of whatever
                    the pay exceeds the start of the row. Employee only — the company pays no share.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Pay from</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Pay to</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Fixed tax</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Plus this % of the excess</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($taxBrackets as $i => $bracket)
                            <tr wire:key="tax-{{ $i }}">
                                <td class="px-4 py-2"><x-input wire:model="taxBrackets.{{ $i }}.income_from" type="number" step="0.01" min="0" class="w-32" /></td>
                                <td class="px-4 py-2">
                                    <x-input wire:model="taxBrackets.{{ $i }}.income_to" type="number" step="0.01" min="0"
                                             placeholder="{{ $loop->last ? 'leave blank' : '' }}" class="w-32" />
                                </td>
                                <td class="px-4 py-2"><x-input wire:model="taxBrackets.{{ $i }}.base_tax" type="number" step="0.01" min="0" class="w-32" /></td>
                                <td class="px-4 py-2">
                                    <div class="relative w-28">
                                        <x-input wire:model="taxBrackets.{{ $i }}.excess_rate" type="number" step="0.01" min="0" max="100" class="pr-8" />
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-[#778599]">%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @if (count($taxBrackets) > 1)
                                        <button wire:click="removeTaxBracket({{ $i }})" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('taxBrackets') <p class="px-5 pt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            @error('taxBrackets.*.income_from') <p class="px-5 pt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2 border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
                <x-button wire:click="saveTaxBrackets">Save Tax Table</x-button>
                <x-button variant="secondary" wire:click="addTaxBracket">Add Row</x-button>
                <p class="w-full text-xs font-medium text-[#778599]">
                    Leave the last row's <strong class="font-semibold">Pay to</strong> blank, so the highest earners are still covered.
                </p>
            </div>
        </x-card>
    @endif

    {{-- 4. Company policy. --}}
    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Company payroll rules</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">The company's own decisions, not the government's.</p>
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
            <x-button wire:click="saveSettings">Save</x-button>
        </div>
    </x-card>

    {{-- 5. Who changed what. --}}
    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Change history</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">Everything here changes what people take home, so every change is recorded.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">When</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Who</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">What</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Was</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Now</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($changeLog as $entry)
                        <tr wire:key="log-{{ $entry->id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599]">{{ $entry->created_at->format('M d, Y g:i A') }}</td>
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $entry->user_name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-neutral-300">
                                <span class="block text-xs font-medium uppercase tracking-wide text-[#778599]">{{ $entry->area }}</span>
                                {{ $entry->field }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] line-through">{{ $entry->old_value ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-bold text-[#0f172a] dark:text-white">{{ $entry->new_value ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center font-medium text-[#778599]">Nothing changed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
