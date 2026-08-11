<?php

use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public int $payslipId;

    public bool $showAdd = false;
    public string $adjustmentType = 'earning';
    public string $adjustmentLabel = '';
    public string $adjustmentAmount = '';
    public string $adjustmentNote = '';
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public function mount(Payslip $payslip): void
    {
        $this->payslipId = $payslip->id;
    }

    protected function payslip(): Payslip
    {
        return Payslip::with('payrollRun', 'employee')->findOrFail($this->payslipId);
    }

    public function addAdjustment(PayrollService $service): void
    {
        $data = $this->validate([
            'adjustmentType' => ['required', 'in:earning,deduction'],
            'adjustmentLabel' => ['required', 'string', 'max:120'],
            'adjustmentAmount' => ['required', 'numeric', 'min:0.01'],
            'adjustmentNote' => ['nullable', 'string', 'max:200'],
        ], [], [
            'adjustmentLabel' => 'description',
            'adjustmentAmount' => 'amount',
        ]);

        $payslip = $this->payslip();

        try {
            PayslipAdjustment::create([
                'payslip_id' => $payslip->id,
                'type' => $data['adjustmentType'],
                'label' => $data['adjustmentLabel'],
                'amount' => round((float) $data['adjustmentAmount'], 2),
                'note' => $data['adjustmentNote'] ?: null,
                'created_by_user_id' => Auth::id(),
                'created_by_name' => Auth::user()?->name,
            ]);

            // Folded into the totals straight away, so the figure on screen is
            // never a step behind what was just added.
            $service->compute($payslip->payrollRun, Auth::user());

            $this->reset(['adjustmentLabel', 'adjustmentAmount', 'adjustmentNote']);
            $this->showAdd = false;
            $this->statusMessage = 'Added and the payslip recomputed.';
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function removeAdjustment(int $id, PayrollService $service): void
    {
        try {
            $payslip = $this->payslip();
            PayslipAdjustment::where('payslip_id', $payslip->id)->findOrFail($id)->delete();
            $service->compute($payslip->payrollRun, Auth::user());
            $this->statusMessage = 'Removed and the payslip recomputed.';
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function with(): array
    {
        $payslip = $this->payslip();

        return [
            'payslip' => $payslip,
            'run' => $payslip->payrollRun,
            'earnings' => $payslip->lines()->where('section', 'earning')->get(),
            'deductions' => $payslip->lines()->where('section', 'deduction')->get(),
            'employerCosts' => $payslip->lines()->where('section', 'employer')->get(),
            'adjustments' => $payslip->adjustments()->latest()->get(),
            'locked' => $payslip->isLocked(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('payroll.show', $run) }}" wire:navigate class="text-sm font-medium text-[#778599] hover:text-[#65758c]">&larr; {{ $run->periodLabel() }}</a>
            <h1 class="mt-1 text-xl font-bold text-[#0f172a] dark:text-white">{{ $payslip->employeeName() }}</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                {{ $payslip->employeeCode() }}
                @if ($payslip->employee_snapshot['position'] ?? null) &middot; {{ $payslip->employee_snapshot['position'] }} @endif
                &middot; paid {{ $run->pay_date->format('M j, Y') }}
            </p>
        </div>
        <x-badge :color="$run->statusColor()">{{ $run->statusLabel() }}</x-badge>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    <x-card>
        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-5">
            @foreach ([
                'Days worked' => $payslip->days_present . ' / ' . $payslip->days_expected,
                'Absent' => $payslip->days_absent,
                'Late' => $payslip->late_minutes . ' min',
                'Overtime' => rtrim(rtrim(number_format((float) $payslip->overtime_hours, 2), '0'), '.') . ' h',
                'Night shift days' => $payslip->night_diff_days,
            ] as $label => $value)
                <div>
                    <p class="text-xs font-medium text-[#778599]">{{ $label }}</p>
                    <p class="mt-1 text-lg font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </x-card>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Earnings</h2>
            </div>
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ($earnings as $line)
                    <div class="flex items-baseline justify-between gap-4 px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</p>
                            @if ($line->detail)<p class="text-xs font-medium text-[#778599]">{{ $line->detail }}</p>@endif
                        </div>
                        <p class="text-sm font-medium tabular-nums {{ (float) $line->amount < 0 ? 'text-red-600 dark:text-red-400' : 'text-[#0f172a] dark:text-white' }}">
                            ₱{{ number_format((float) $line->amount, 2) }}
                        </p>
                    </div>
                @endforeach
                <div class="flex items-baseline justify-between gap-4 bg-[#f8fafc] px-5 py-3 dark:bg-neutral-800/50">
                    <p class="text-sm font-bold text-[#0f172a] dark:text-white">Gross pay</p>
                    <p class="text-base font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $payslip->gross_pay, 2) }}</p>
                </div>
            </div>
        </x-card>

        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Deductions</h2>
            </div>
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @forelse ($deductions as $line)
                    <div class="flex items-baseline justify-between gap-4 px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</p>
                            @if ($line->detail)<p class="text-xs font-medium text-[#778599]">{{ $line->detail }}</p>@endif
                        </div>
                        <p class="text-sm font-medium text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $line->amount, 2) }}</p>
                    </div>
                @empty
                    <div class="px-5 py-3 text-sm font-medium text-[#778599]">Nothing deducted.</div>
                @endforelse
                <div class="flex items-baseline justify-between gap-4 bg-[#f8fafc] px-5 py-3 dark:bg-neutral-800/50">
                    <p class="text-sm font-bold text-[#0f172a] dark:text-white">Total deductions</p>
                    <p class="text-base font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $payslip->total_deductions, 2) }}</p>
                </div>
            </div>
        </x-card>
    </div>

    <x-card>
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <p class="text-lg font-bold text-[#0f172a] dark:text-white">Net pay</p>
            <p class="text-3xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format((float) $payslip->net_pay, 2) }}</p>
        </div>
        <p class="mt-1 text-xs font-medium text-[#778599]">
            ₱{{ number_format((float) $payslip->gross_pay, 2) }} gross less ₱{{ number_format((float) $payslip->total_deductions, 2) }} deductions.
        </p>
    </x-card>

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <div>
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Added by hand</h2>
                <p class="mt-1 text-sm font-medium text-[#778599]">
                    Bonuses, reimbursements, corrections. These survive a recompute — everything else is worked out fresh.
                </p>
            </div>
            @unless ($locked)
                <x-button wire:click="$set('showAdd', true)">Add</x-button>
            @endunless
        </div>

        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @forelse ($adjustments as $adjustment)
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3" wire:key="adj-{{ $adjustment->id }}">
                    <div>
                        <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">
                            {{ $adjustment->label }}
                            <x-badge :color="$adjustment->isEarning() ? 'green' : 'amber'">
                                {{ $adjustment->isEarning() ? 'Added to pay' : 'Taken from pay' }}
                            </x-badge>
                        </p>
                        <p class="text-xs font-medium text-[#778599]">
                            {{ $adjustment->note ? $adjustment->note . ' · ' : '' }}{{ $adjustment->created_by_name }} · {{ $adjustment->created_at->format('M j, g:i A') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-4">
                        <p class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $adjustment->amount, 2) }}</p>
                        @unless ($locked)
                            <button wire:click="removeAdjustment({{ $adjustment->id }})" wire:confirm="Remove this?"
                                    class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400">Remove</button>
                        @endunless
                    </div>
                </div>
            @empty
                <div class="px-5 py-6 text-center text-sm font-medium text-[#778599]">Nothing added by hand.</div>
            @endforelse
        </div>

        @if ($locked)
            <div class="border-t border-neutral-200 bg-[#f8fafc] px-5 py-3 dark:border-neutral-800 dark:bg-neutral-800/50">
                <p class="text-xs font-medium text-[#778599]">
                    This run is {{ strtolower($run->statusLabel()) }}, so the payslip cannot be changed. Put the correction on the next payroll.
                </p>
            </div>
        @endif
    </x-card>

    @if ($employerCosts->isNotEmpty())
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Paid by the company</h2>
                <p class="mt-1 text-sm font-medium text-[#778599]">Not deducted from this payslip. Recorded for the remittance forms.</p>
            </div>
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ($employerCosts as $line)
                    <div class="flex items-baseline justify-between gap-4 px-5 py-3">
                        <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</p>
                        <p class="text-sm font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $line->amount, 2) }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-modal :show="$showAdd" onClose="$set('showAdd', false)" maxWidth="lg">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">Add to this payslip</h2>

        <form wire:submit="addAdjustment" class="space-y-4">
            <div>
                <x-label>Add to pay, or take from pay?</x-label>
                <x-select wire:model="adjustmentType">
                    <option value="earning">Add to pay — bonus, reimbursement, back pay</option>
                    <option value="deduction">Take from pay — a correction or recovery</option>
                </x-select>
            </div>

            <div>
                <x-label>Description</x-label>
                <x-input wire:model="adjustmentLabel" type="text" placeholder="e.g. Perfect attendance bonus" />
                <p class="mt-1 text-xs font-medium text-[#778599]">This prints on the payslip, so write it for the employee.</p>
                @error('adjustmentLabel') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Amount</x-label>
                <x-input wire:model="adjustmentAmount" type="number" step="0.01" min="0.01" />
                @error('adjustmentAmount') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Internal note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="adjustmentNote" type="text" placeholder="Why, for the record" />
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Add</x-button>
                <x-button type="button" variant="secondary" wire:click="$set('showAdd', false)">Cancel</x-button>
            </div>
        </form>
    </x-modal>
</div>
