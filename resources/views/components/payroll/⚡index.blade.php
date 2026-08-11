<?php

use App\Models\PayrollRun;
use App\Services\Payroll\PayrollPeriodResolver;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showOpen = false;
    public ?string $statusMessage = null;

    public int $year;
    public int $month;
    public string $cutoff = 'second';

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    public function openForm(): void
    {
        $this->resetValidation();
        $this->showOpen = true;
    }

    public function open(PayrollService $service): void
    {
        $data = $this->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'cutoff' => ['required', 'in:first,second'],
        ]);

        $run = $service->openRun($data['year'], $data['month'], $data['cutoff']);

        $this->showOpen = false;
        $this->redirectRoute('payroll.show', $run, navigate: true);
    }

    public function cancelRun(int $id, PayrollService $service): void
    {
        $service->cancel(PayrollRun::findOrFail($id));
        $this->statusMessage = 'Draft discarded.';
    }

    public function with(): array
    {
        $resolver = new PayrollPeriodResolver();

        return [
            'runs' => PayrollRun::withCount('payslips')->orderByDesc('pay_date')->orderByDesc('id')->get(),
            'preview' => $resolver->payDateFor($this->year, $this->month, $this->cutoff),
            'months' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m, 1)->format('F')]),
            'years' => range((int) now()->year - 1, (int) now()->year + 1),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Payroll</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">One run per cutoff. Nothing is paid until someone says so.</p>
        </div>
        <x-button wire:click="openForm" pill>
            <x-icon name="plus" class="h-4 w-4" /> Start a Payroll
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Payroll Runs</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Period</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Pay date</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employees</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Gross</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Net</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $run->periodLabel() }}
                                <span class="block text-xs font-medium text-[#778599]">
                                    {{ $run->cutoff === 'first' ? 'First cutoff' : 'Second cutoff' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $run->pay_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $run->payslips_count ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $run->total_gross, 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $run->total_net, 2) }}</td>
                            <td class="px-4 py-3"><x-badge :color="$run->statusColor()">{{ $run->statusLabel() }}</x-badge></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('payroll.show', $run) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Open</a>
                                    @if ($run->status === 'draft')
                                        <button wire:click="cancelRun({{ $run->id }})" wire:confirm="Discard this draft? Nothing has been computed yet."
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Discard</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center font-medium text-[#778599]">No payroll has been run yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showOpen" onClose="$set('showOpen', false)" maxWidth="lg">
        <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Start a Payroll</h2>
        <p class="mb-5 text-sm font-medium text-[#778599]">Pick the cutoff. Opening the same one twice reuses the existing run rather than creating a second.</p>

        <form wire:submit="open" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <x-label>Month</x-label>
                    <x-select wire:model.live="month">
                        @foreach ($months as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-label>Year</x-label>
                    <x-select wire:model.live="year">
                        @foreach ($years as $y)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-label>Cutoff</x-label>
                    <x-select wire:model.live="cutoff">
                        <option value="first">First — paid on the 15th</option>
                        <option value="second">Second — paid on the 30th</option>
                    </x-select>
                </div>
            </div>

            <div class="rounded-lg bg-[#f8fafc] p-3 dark:bg-neutral-800/50">
                <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">
                    Covers <strong class="font-bold text-[#0f172a] dark:text-white">{{ $preview['start']->format('M j') }} – {{ $preview['end']->format('M j, Y') }}</strong>,
                    paid on <strong class="font-bold text-[#0f172a] dark:text-white">{{ $preview['pay_date']->format('M j, Y') }}</strong>.
                </p>
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Open Run</x-button>
                <x-button type="button" variant="secondary" wire:click="$set('showOpen', false)">Cancel</x-button>
            </div>
        </form>
    </x-modal>
</div>
