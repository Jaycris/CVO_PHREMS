<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public function with(): array
    {
        $user = Auth::user();
        $employee = $user->employee;

        $awaitingApproval = collect();

        if ($employee) {
            $awaitingApproval = $awaitingApproval->merge(
                LeaveRequest::with(['employee', 'leaveType'])
                    ->where('status', 'pending_manager')
                    ->where('manager_id', $employee->id)
                    ->get()
            );
        }

        if ($user->can('leave.approve')) {
            $awaitingApproval = $awaitingApproval->merge(
                LeaveRequest::with(['employee', 'leaveType'])->where('status', 'pending_ceo')->get()
            );
        }

        /*
         * The three tables page independently, so each carries its own page
         * name. Sharing one would move all three at once and page a manager
         * away from the approval they were reading.
         */
        $myRequests = $employee
            ? LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->latest()
                ->paginate($this->perPage(), pageName: 'mine')
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage(), 1, ['pageName' => 'mine']);

        $allRequests = $user->can('leave.view_all')
            ? LeaveRequest::with(['employee', 'leaveType'])->latest()
                ->paginate($this->perPage(), pageName: 'all')
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage(), 1, ['pageName' => 'all']);

        /*
         * The counters come from their own aggregate queries now. Reading them
         * off the list only worked while the list held every row.
         */
        $counts = fn () => $user->can('leave.view_all')
            ? LeaveRequest::query()
            : ($employee ? LeaveRequest::where('employee_id', $employee->id) : LeaveRequest::whereRaw('1 = 0'));

        return [
            'awaitingApproval' => $this->paginateCollection($awaitingApproval->unique('id')->values(), 'approvals'),
            'myRequests' => $myRequests,
            'allRequests' => $allRequests,
            'totalVisibleRequests' => $counts()->count(),
            'pendingVisibleRequests' => $counts()->whereIn('status', ['pending_manager', 'pending_ceo'])->count(),
            'approvedVisibleRequests' => $counts()->where('status', 'approved')->count(),
        ];
    }
};
?>

<div class="space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Leave Requests</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">Track leave filings, approval status, and team requests.</p>
        </div>

        @if (auth()->user()->employee)
            <x-button as="a" href="{{ route('leave-requests.create') }}" wire:navigate class="h-12 rounded-xl px-5 text-sm">
                <x-icon name="plus" class="h-4 w-4" /> Request Leave
            </x-button>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold text-[#526783] dark:text-ink-400">Visible Requests</p>
            <p class="mt-1.5 text-2xl font-bold text-ink-950 dark:text-white">{{ $totalVisibleRequests }}</p>
            <p class="mt-1 text-xs font-medium text-brand-700 dark:text-brand-300">Current view</p>
        </div>
        <div class="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold text-[#526783] dark:text-ink-400">Pending</p>
            <p class="mt-1.5 text-2xl font-bold text-ink-950 dark:text-white">{{ $pendingVisibleRequests }}</p>
            <p class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-300">Needs approval</p>
        </div>
        <div class="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold text-[#526783] dark:text-ink-400">Approved</p>
            <p class="mt-1.5 text-2xl font-bold text-ink-950 dark:text-white">{{ $approvedVisibleRequests }}</p>
            <p class="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-300">Completed</p>
        </div>
    </div>

    @if ($awaitingApproval->total() > 0)
        <x-card :padding="false" class="overflow-hidden rounded-2xl">
            <div class="border-b border-ink-200 px-6 py-5 dark:border-white/10">
                <p class="muted-label">Action Needed</p>
                <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Awaiting Your Approval</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                    <thead class="bg-ink-50 dark:bg-white/5">
                        <tr>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Employee</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Type</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Dates</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Status</th>
                            <th class="px-5 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                        @foreach ($awaitingApproval as $request)
                            <tr wire:key="approve-{{ $request->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#526783] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $request->leaveType->code }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $request->start_date->format('M d') }} - {{ $request->end_date->format('M d, Y') }} ({{ $request->days_requested }}d)</td>
                                <td class="whitespace-nowrap px-5 py-4"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                                <td class="whitespace-nowrap px-5 py-4 text-right"><a href="{{ route('leave-requests.show', $request) }}" wire:navigate class="text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">Review</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($awaitingApproval->hasPages())
                <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                    {{ $awaitingApproval->links('components.pagination', ['noun' => 'requests awaiting you']) }}
                </div>
            @endif
        </x-card>
    @endif

    @if (auth()->user()->employee)
        <x-card :padding="false" class="overflow-hidden rounded-2xl">
            <div class="border-b border-ink-200 px-6 py-5 dark:border-white/10">
                <p class="muted-label">Self Service</p>
                <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">My Requests</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                    <thead class="bg-ink-50 dark:bg-white/5">
                        <tr>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Type</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Dates</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Status</th>
                            <th class="px-5 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                        @forelse ($myRequests as $request)
                            <tr wire:key="mine-{{ $request->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#526783] dark:text-white">{{ $request->leaveType->code }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $request->start_date->format('M d') }} - {{ $request->end_date->format('M d, Y') }} ({{ $request->days_requested }}d)</td>
                                <td class="whitespace-nowrap px-5 py-4"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                                <td class="whitespace-nowrap px-5 py-4 text-right"><a href="{{ route('leave-requests.show', $request) }}" wire:navigate class="text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center font-medium text-ink-500">No leave requests yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($myRequests->hasPages())
                <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                    {{ $myRequests->links('components.pagination', ['noun' => 'of your requests']) }}
                </div>
            @endif
        </x-card>
    @endif

    @if (auth()->user()->can('leave.view_all'))
        <x-card :padding="false" class="overflow-hidden rounded-2xl">
            <div class="border-b border-ink-200 px-6 py-5 dark:border-white/10">
                <p class="muted-label">HR View</p>
                <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">All Leave Requests</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                    <thead class="bg-ink-50 dark:bg-white/5">
                        <tr>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Employee</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Type</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Dates</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Status</th>
                            <th class="px-5 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                        @forelse ($allRequests as $request)
                            <tr wire:key="all-{{ $request->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#526783] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $request->leaveType->code }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-[#64748b] dark:text-ink-400">{{ $request->start_date->format('M d') }} - {{ $request->end_date->format('M d, Y') }} ({{ $request->days_requested }}d)</td>
                                <td class="whitespace-nowrap px-5 py-4"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                                <td class="whitespace-nowrap px-5 py-4 text-right"><a href="{{ route('leave-requests.show', $request) }}" wire:navigate class="text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-10 text-center font-medium text-ink-500">No leave requests yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($allRequests->hasPages())
                <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                    {{ $allRequests->links('components.pagination', ['noun' => 'leave requests']) }}
                </div>
            @endif
        </x-card>
    @endif
</div>
