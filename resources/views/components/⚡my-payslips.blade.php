<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    #[Locked]
    public ?int $openId = null;

    public function mount(?Payslip $payslip = null): void
    {
        if ($payslip?->exists) {
            // Guarded here rather than in the route: the check is "is this
            // yours", which only the payslip itself can answer.
            $this->authorizeOwn($payslip);
            $this->openId = $payslip->id;
        }
    }

    /**
     * A payslip belongs to its employee, and only once HR has released it.
     *
     * The gate is notified_at rather than the run's status: finalizing locks
     * the figures, but it is HR pressing Send that decides the employee may
     * see them. Those are separate moments on purpose — HR finalizes, checks,
     * and releases when ready.
     */
    protected function authorizeOwn(Payslip $payslip): void
    {
        $employee = Auth::user()?->employee;

        abort_unless($employee && $payslip->employee_id === $employee->id, 403, 'That payslip is not yours.');
        abort_unless($payslip->notified_at !== null, 403, 'That payslip has not been released yet.');
    }

    public function open(int $id): void
    {
        $this->authorizeOwn(Payslip::with('payrollRun')->findOrFail($id));
        $this->openId = $id;
    }

    public function closePayslip(): void
    {
        $this->openId = null;
    }

    public function with(): array
    {
        $employee = Auth::user()?->employee;

        /*
         * Ordering used to happen in PHP after loading every payslip, which
         * paging cannot do — page 2 would be sorted among itself. The pay date
         * lives on the run, so it is pulled in as a sub-select instead.
         */
        $released = fn () => Payslip::where('employee_id', $employee->id)
            // Nothing appears until HR releases it. A payslip seen before then
            // could still be a figure HR was about to correct.
            ->whereNotNull('notified_at');

        $payslips = $employee
            ? $released()->with('payrollRun')
                ->orderByDesc(
                    \App\Models\PayrollRun::select('pay_date')
                        ->whereColumn('payroll_runs.id', 'payslips.payroll_run_id')
                )
                ->paginate($this->perPage())
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage());

        // Looked up on its own rather than from the page in view, so opening a
        // payslip and then paging away does not close it.
        $open = $employee && $this->openId
            ? $released()->with('payrollRun')->find($this->openId)
            : null;

        return [
            'payslips' => $payslips,
            'open' => $open,
            'earnings' => $open?->lines()->where('section', 'earning')->get() ?? collect(),
            'deductions' => $open?->lines()->where('section', 'deduction')->get() ?? collect(),
            'hasEmployee' => $employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Payslips</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Check each one and tell HR within three working days if something looks wrong.</p>
    </div>

    @unless ($hasEmployee)
        <x-card>
            <p class="text-sm font-medium text-[#778599]">Your account is not linked to an employee record yet. Ask HR to sort it out.</p>
        </x-card>
    @else
        <x-card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Period</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Paid on</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Gross</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Deductions</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Take home</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($payslips as $payslip)
                            <tr wire:key="mine-{{ $payslip->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $payslip->payrollRun->periodLabel() }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $payslip->payrollRun->pay_date->format('M j, Y') }}</td>
                                <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $payslip->gross_pay, 2) }}</td>
                                <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $payslip->total_deductions, 2) }}</td>
                                <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $payslip->net_pay, 2) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button wire:click="open({{ $payslip->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center font-medium text-[#778599]">No payslips yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($payslips->hasPages())
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    {{ $payslips->links('components.pagination', ['noun' => 'payslips']) }}
                </div>
            @endif
        </x-card>
    @endunless

    <x-modal :show="(bool) $open" onClose="closePayslip" maxWidth="2xl">
        @if ($open)
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">{{ $open->payrollRun->periodLabel() }}</h2>
                    <p class="text-sm font-medium text-[#778599]">Paid {{ $open->payrollRun->pay_date->format('F j, Y') }}</p>
                </div>
                <x-badge :color="$open->payrollRun->status === 'paid' ? 'green' : 'brand'">
                    {{ $open->payrollRun->status === 'paid' ? 'Paid' : 'Ready' }}
                </x-badge>
            </div>

            <div class="mb-5 grid grid-cols-2 gap-3 rounded-lg bg-[#f8fafc] p-3 text-sm sm:grid-cols-4 dark:bg-neutral-800/50">
                @foreach ([
                    'Days worked' => $open->days_present . ' / ' . $open->days_expected,
                    'Absent' => $open->days_absent,
                    'Late' => $open->late_minutes . ' min',
                    'Overtime' => rtrim(rtrim(number_format((float) $open->overtime_hours, 2), '0'), '.') . ' h',
                ] as $label => $value)
                    <div>
                        <p class="text-xs font-medium text-[#778599]">{{ $label }}</p>
                        <p class="mt-0.5 font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="space-y-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Earnings</p>
                    <div class="mt-2 divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($earnings as $line)
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <div>
                                    <span class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</span>
                                    @if ($line->detail)<span class="ml-1 text-xs font-medium text-[#778599]">{{ $line->detail }}</span>@endif
                                </div>
                                <span class="text-sm font-medium tabular-nums {{ (float) $line->amount < 0 ? 'text-red-600 dark:text-red-400' : 'text-[#0f172a] dark:text-white' }}">
                                    ₱{{ number_format((float) $line->amount, 2) }}
                                </span>
                            </div>
                        @endforeach
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <span class="text-sm font-bold text-[#0f172a] dark:text-white">Gross pay</span>
                            <span class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $open->gross_pay, 2) }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Deductions</p>
                    <div class="mt-2 divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($deductions as $line)
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <div>
                                    <span class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</span>
                                    @if ($line->detail)<span class="ml-1 text-xs font-medium text-[#778599]">{{ $line->detail }}</span>@endif
                                </div>
                                <span class="text-sm font-medium text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $line->amount, 2) }}</span>
                            </div>
                        @empty
                            <p class="py-2 text-sm font-medium text-[#778599]">Nothing deducted.</p>
                        @endforelse
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <span class="text-sm font-bold text-[#0f172a] dark:text-white">Total deductions</span>
                            <span class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $open->total_deductions, 2) }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-baseline justify-between gap-4 rounded-lg bg-brand-50 px-4 py-3 dark:bg-brand-900/20">
                    <span class="text-base font-bold text-[#0f172a] dark:text-white">Take home</span>
                    <span class="text-2xl font-bold text-brand-700 dark:text-brand-300 tabular-nums">₱{{ number_format((float) $open->net_pay, 2) }}</span>
                </div>
            </div>

            <div class="mt-5 flex gap-2">
                <x-button variant="secondary" wire:click="closePayslip">Close</x-button>
            </div>
        @endif
    </x-modal>
</div>
