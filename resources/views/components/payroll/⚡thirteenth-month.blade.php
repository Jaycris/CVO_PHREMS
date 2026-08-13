<?php

use App\Models\PayrollRun;
use App\Models\ThirteenthMonthOpeningBalance;
use App\Services\Payroll\ThirteenthMonthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public int $year;
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    /** @var array<int, string> employee id => what the old payroll paid */
    public array $opening = [];

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->loadOpening();
    }

    public function updatedYear(): void
    {
        $this->loadOpening();
        $this->statusMessage = null;
    }

    protected function loadOpening(): void
    {
        $this->opening = ThirteenthMonthOpeningBalance::where('for_year', $this->year)
            ->pluck('basic_earned', 'employee_id')
            ->map(fn ($v) => (string) (float) $v)
            ->all();
    }

    /**
     * What the previous payroll paid before this system started keeping
     * payslips. Without it the first thirteenth month is short by however many
     * months predate go-live.
     */
    public function saveOpening(): void
    {
        $this->validate([
            'opening.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['opening.*' => 'amount']);

        foreach ($this->opening as $employeeId => $value) {
            $amount = (float) $value;

            if ($amount <= 0) {
                ThirteenthMonthOpeningBalance::where('employee_id', $employeeId)
                    ->where('for_year', $this->year)
                    ->delete();

                continue;
            }

            ThirteenthMonthOpeningBalance::updateOrCreate(
                ['employee_id' => $employeeId, 'for_year' => $this->year],
                ['basic_earned' => $amount, 'recorded_by_user_id' => Auth::id()]
            );
        }

        $this->loadOpening();
        $this->statusMessage = 'Saved. The totals below now include it.';
    }

    public function generate(ThirteenthMonthService $service): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;

        try {
            $run = $service->generate($this->year, Auth::user());
            $this->redirectRoute('payroll.show', $run, navigate: true);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function with(ThirteenthMonthService $service): array
    {
        return [
            'rows' => $service->preview($this->year),
            'existingRun' => PayrollRun::where('run_type', 'thirteenth_month')
                ->whereYear('period_start', $this->year)
                ->first(),
            'years' => range((int) now()->year, (int) now()->year - 3),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">13th Month Pay</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                One twelfth of the basic pay each employee actually earned. Absences reduce it; overtime and allowances do not count.
            </p>
        </div>
        <div class="w-40">
            <x-label>Year</x-label>
            <x-select wire:model.live="year">
                @foreach ($years as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </x-select>
        </div>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    @if ($existingRun)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 dark:border-brand-400/20 dark:bg-brand-500/10">
            <p class="text-sm font-medium text-brand-800 dark:text-brand-200">
                A {{ $year }} run already exists — {{ $existingRun->statusLabel() }},
                ₱{{ number_format((float) $existingRun->total_net, 2) }} for {{ $existingRun->employee_count }} employee(s).
            </p>
            <x-button as="a" href="{{ route('payroll.show', $existingRun) }}" wire:navigate variant="secondary">Open it</x-button>
        </div>
    @endif

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">What each employee is owed for {{ $year }}</h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">
                <strong class="font-semibold">From this system</strong> is the basic pay on finalized payslips.
                <strong class="font-semibold">From your old payroll</strong> is what you paid before this system started —
                type it in, or the first 13th month will be short by those months.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">From this system</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">From your old payroll</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Total basic for {{ $year }}</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">13th month</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($rows as $row)
                        <tr wire:key="tm-{{ $row['employee']->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $row['employee']->fullName() ?: $row['employee']->employee_id }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $row['employee']->employee_id }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format($row['system'], 2) }}</td>
                            <td class="px-4 py-3">
                                <div class="w-36">
                                    <x-input wire:model="opening.{{ $row['employee']->id }}" type="number" step="0.01" min="0" placeholder="0.00" />
                                </div>
                                @error('opening.' . $row['employee']->id) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format($row['total'], 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center font-medium text-[#778599]">
                                No basic pay recorded for {{ $year }}. Finalize at least one regular payroll first,
                                or enter what your old payroll paid.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white">Total to pay out</td>
                            <td class="px-4 py-3 text-right font-bold text-brand-700 dark:text-brand-400 tabular-nums">
                                ₱{{ number_format($rows->sum('amount'), 2) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-neutral-200 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-neutral-800/50">
            <x-button variant="secondary" wire:click="saveOpening">Save Old Payroll Figures</x-button>
            @if ($rows->isNotEmpty())
                <x-button wire:click="generate" wire:confirm="Create the {{ $year }} 13th month payroll for {{ $rows->count() }} employee(s)?">
                    <span wire:loading.remove wire:target="generate">{{ $existingRun ? 'Rebuild' : 'Create' }} the {{ $year }} Run</span>
                    <span wire:loading wire:target="generate">Working…</span>
                </x-button>
            @endif
            <p class="w-full text-xs font-medium text-[#778599]">
                Creating the run only works it out. It still has to be finalized and sent like any other payroll.
            </p>
        </div>
    </x-card>
</div>
