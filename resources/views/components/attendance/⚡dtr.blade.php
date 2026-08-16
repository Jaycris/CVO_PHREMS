<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\AttendanceDay;
use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public string $employeeId = '';
    public string $fromDate = '';
    public string $toDate = '';

    public function mount(): void
    {
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->toDateString();
    }

    /** Every property here is a filter, so any change starts the list again. */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        // whereDate rather than whereBetween: a DATE column compared against a
        // plain string drops the last day of the range on some drivers.
        $days = AttendanceDay::with(['employee', 'breaks'])
            ->whereDate('work_date', '>=', $this->fromDate)
            ->whereDate('work_date', '<=', $this->toDate)
            ->when($this->employeeId, fn ($q) => $q->where('employee_id', $this->employeeId))
            ->orderByDesc('work_date')
            ->paginate($this->perPage());

        return [
            'employees' => Employee::orderBy('employee_id')->get(),
            'days' => $days,
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Daily Time Record</h1>

    <x-card class="flex flex-wrap items-end gap-3">
        <div>
            <x-label>Employee</x-label>
            <x-select wire:model.live="employeeId">
                <option value="">All employees</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}">{{ $employee->employee_id }} — {{ $employee->fullName() ?: $employee->company_email }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-label>From</x-label>
            <x-input wire:model.live="fromDate" type="date" />
        </div>
        <div>
            <x-label>To</x-label>
            <x-input wire:model.live="toDate" type="date" />
        </div>
    </x-card>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Time In</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Time Out</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Late</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Break</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Worked</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($days as $day)
                        <tr wire:key="dtr-{{ $day->id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $day->work_date->format('M d, Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->employee->employee_id }} — {{ $day->employee->fullName() ?: $day->employee->company_email }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->time_in?->format('g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->time_out?->format('g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->lateMinutes() !== null ? $day->lateMinutes() . ' min' : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->totalBreakMinutes() }} min @if($day->overBreakMinutes() > 0) <span class="text-red-600 dark:text-red-400">(+{{ $day->overBreakMinutes() }} over)</span> @endif</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $day->totalWorkedMinutes() !== null ? number_format($day->totalWorkedMinutes() / 60, 1) . ' hrs' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center font-medium text-[#778599]">No attendance records for this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($days->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $days->links('components.pagination', ['noun' => 'days']) }}
            </div>
        @endif
    </x-card>
</div>