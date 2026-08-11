<?php

use App\Models\OvertimeRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $user = Auth::user();
        $employee = $user->employee;
        $isAdminHr = $user->can('overtime.view_all');

        $awaitingApproval = collect();

        if ($employee) {
            $awaitingApproval = OvertimeRequest::with('employee')
                ->where('status', 'pending_manager')
                ->where('manager_id', $employee->id)
                ->orderBy('work_date')
                ->get();
        }

        // Requests from employees with no manager on file would otherwise sit
        // unseen, so HR/Admin pick them up.
        if ($isAdminHr) {
            $awaitingApproval = $awaitingApproval->merge(
                OvertimeRequest::with('employee')
                    ->where('status', 'pending_manager')
                    ->whereNull('manager_id')
                    ->orderBy('work_date')
                    ->get()
            );
        }

        return [
            'awaitingApproval' => $awaitingApproval->unique('id'),
            'myRequests' => $employee
                ? OvertimeRequest::where('employee_id', $employee->id)->latest('work_date')->get()
                : collect(),
            'allRequests' => $isAdminHr
                ? OvertimeRequest::with('employee')->latest('work_date')->limit(50)->get()
                : collect(),
            'isAdminHr' => $isAdminHr,
            'hasEmployee' => $employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Overtime</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Only manager-approved hours reach payroll.</p>
        </div>
        @if ($hasEmployee)
            <x-button as="a" href="{{ route('overtime.create') }}" wire:navigate pill>
                <x-icon name="plus" class="h-4 w-4" /> File Overtime
            </x-button>
        @endif
    </div>

    @if ($awaitingApproval->isNotEmpty())
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Awaiting Your Approval</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Date</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Hours</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($awaitingApproval as $request)
                            <tr wire:key="pending-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $request->work_date->format('M d, Y') }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ rtrim(rtrim(number_format((float) $request->hours_requested, 2), '0'), '.') }} h</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('overtime.show', $request) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Review</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">{{ $isAdminHr ? 'All Overtime' : 'My Overtime' }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        @if ($isAdminHr)
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        @endif
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Date</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Filed</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Approved</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse (($isAdminHr ? $allRequests : $myRequests) as $request)
                        <tr wire:key="ot-{{ $request->id }}">
                            @if ($isAdminHr)
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                            @endif
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->work_date->format('M d, Y') }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ rtrim(rtrim(number_format((float) $request->hours_requested, 2), '0'), '.') }} h</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                {{ $request->hours_approved !== null ? rtrim(rtrim(number_format((float) $request->hours_approved, 2), '0'), '.') . ' h' : '—' }}
                            </td>
                            <td class="px-4 py-3"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('overtime.show', $request) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isAdminHr ? 6 : 5 }}" class="px-4 py-8 text-center font-medium text-[#778599]">No overtime filed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
