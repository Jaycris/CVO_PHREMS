<?php

use App\Models\AttendanceDay;
use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $fromDate = '';
    public string $toDate = '';

    public function mount(): void
    {
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->toDateString();
    }

    public function with(): array
    {
        $days = AttendanceDay::with('breaks')
            ->whereBetween('work_date', [$this->fromDate, $this->toDate])
            ->get()
            ->groupBy('employee_id');

        $summary = Employee::orderBy('employee_id')->get()->map(function (Employee $employee) use ($days) {
            $employeeDays = $days->get($employee->id, collect());

            return (object) [
                'employee' => $employee,
                'daysPresent' => $employeeDays->whereNotNull('time_in')->count(),
                'totalLateMinutes' => $employeeDays->sum(fn (AttendanceDay $d) => $d->lateMinutes() ?? 0),
                'totalWorkedHours' => round($employeeDays->sum(fn (AttendanceDay $d) => $d->totalWorkedMinutes() ?? 0) / 60, 1),
                'totalOverBreakMinutes' => $employeeDays->sum(fn (AttendanceDay $d) => $d->overBreakMinutes()),
            ];
        });

        return ['summary' => $summary];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Attendance Summary</h1>
        <x-button as="a" variant="secondary" href="{{ route('reports.employees.export') }}">
            <x-icon name="document" class="h-4 w-4" /> Export Employee Master List (CSV)
        </x-button>
    </div>

    <x-card class="flex flex-wrap items-end gap-3">
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
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Days Present</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Total Late (min)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Total Worked (hrs)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Over-Break (min)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($summary as $row)
                        <tr wire:key="sum-{{ $row->employee->id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $row->employee->employee_id }} — {{ $row->employee->fullName() ?: $row->employee->company_email }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $row->daysPresent }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $row->totalLateMinutes }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $row->totalWorkedHours }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $row->totalOverBreakMinutes }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center font-medium text-[#778599]">No employees yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>