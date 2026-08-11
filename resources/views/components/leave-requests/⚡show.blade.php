<?php

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public LeaveRequest $leaveRequest;
    public string $note = '';
    public ?string $statusMessage = null;

    public function mount(LeaveRequest $leaveRequest): void
    {
        $this->leaveRequest = $leaveRequest->load(['employee', 'leaveType', 'manager', 'ceo']);
    }

    public function canActAsManager(): bool
    {
        $employee = Auth::user()->employee;

        return $this->leaveRequest->status === 'pending_manager'
            && $employee
            && $this->leaveRequest->manager_id === $employee->id;
    }

    public function canActAsCeo(): bool
    {
        return $this->leaveRequest->status === 'pending_ceo' && Auth::user()->can('leave.approve');
    }

    public function managerApprove(LeaveService $leaveService): void
    {
        abort_unless($this->canActAsManager(), 403);
        $leaveService->managerDecide($this->leaveRequest, true, $this->note ?: null);
        $this->refreshRequest('Approved and forwarded to CEO/COO.');
    }

    public function managerDecline(LeaveService $leaveService): void
    {
        abort_unless($this->canActAsManager(), 403);
        $leaveService->managerDecide($this->leaveRequest, false, $this->note ?: null);
        $this->refreshRequest('Declined.');
    }

    public function ceoApprove(LeaveService $leaveService): void
    {
        abort_unless($this->canActAsCeo(), 403);
        $leaveService->ceoDecide($this->leaveRequest, Auth::user()->employee, true, $this->note ?: null);
        $this->refreshRequest('Approved.');
    }

    public function ceoDecline(LeaveService $leaveService): void
    {
        abort_unless($this->canActAsCeo(), 403);
        $leaveService->ceoDecide($this->leaveRequest, Auth::user()->employee, false, $this->note ?: null);
        $this->refreshRequest('Declined.');
    }

    protected function refreshRequest(string $message): void
    {
        $this->leaveRequest->refresh()->load(['employee', 'leaveType', 'manager', 'ceo']);
        $this->note = '';
        $this->statusMessage = $message;
    }
};
?>

<div class="max-w-5xl space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Leave Request</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">Review request details and approval status.</p>
        </div>
        <x-button as="a" href="{{ route('leave-requests.index') }}" wire:navigate variant="secondary" class="h-12 rounded-xl px-5 text-sm">
            Back to Requests
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-400/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <x-card class="rounded-2xl">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-ink-100 pb-5 dark:border-white/10">
                <div>
                    <p class="muted-label">Request Details</p>
                    <h2 class="mt-1 text-2xl font-bold text-ink-950 dark:text-white">{{ $leaveRequest->employee->fullName() ?: $leaveRequest->employee->employee_id }}</h2>
                </div>
                <x-badge :color="$leaveRequest->statusColor()">{{ $leaveRequest->statusLabel() }}</x-badge>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Leave Type</p>
                    <p class="mt-2 text-sm font-bold text-ink-950 dark:text-white">{{ $leaveRequest->leaveType->name }}</p>
                </div>
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Days Requested</p>
                    <p class="mt-2 text-sm font-bold text-ink-950 dark:text-white">{{ $leaveRequest->days_requested }} day(s)</p>
                </div>
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Start Date</p>
                    <p class="mt-2 text-sm font-bold text-ink-950 dark:text-white">{{ $leaveRequest->start_date->format('M d, Y') }}</p>
                </div>
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">End Date</p>
                    <p class="mt-2 text-sm font-bold text-ink-950 dark:text-white">{{ $leaveRequest->end_date->format('M d, Y') }}</p>
                </div>
            </div>

            <div class="mt-5 rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Reason</p>
                <p class="mt-2 text-sm font-medium leading-6 text-[#64748b] dark:text-ink-300">{{ $leaveRequest->reason ?: 'No reason provided.' }}</p>
            </div>

            @if ($leaveRequest->is_lwop)
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-700 dark:border-amber-400/20 dark:bg-amber-500/10 dark:text-amber-300">
                    This request is Leave Without Pay (LWOP) due to insufficient credits or employee status.
                </div>
            @endif
        </x-card>

        <x-card class="rounded-2xl">
            <p class="muted-label">Approval Trail</p>
            <div class="mt-4 space-y-3">
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">Manager</p>
                    <p class="mt-2 text-sm font-bold text-ink-950 dark:text-white">{{ $leaveRequest->manager?->fullName() ?? 'Not assigned' }}</p>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">{{ $leaveRequest->manager_decision ?: 'Pending' }}</p>
                </div>
                <div class="rounded-xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-400">CEO/COO</p>
                    <p class="mt-2 text-sm font-bold text-ink-950 dark:text-white">{{ $leaveRequest->ceo?->fullName() ?? 'Not assigned' }}</p>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">{{ $leaveRequest->ceo_decision ?: 'Pending' }}</p>
                </div>
            </div>
        </x-card>
    </div>

    @if ($this->canActAsManager() || $this->canActAsCeo())
        <x-card class="rounded-2xl">
            <p class="muted-label">Decision</p>
            <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Approval Action</h2>
            <div class="mt-5">
                <x-label>Note (optional)</x-label>
                <x-textarea wire:model="note" rows="3" placeholder="Add an approval or decline note." />
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($this->canActAsManager())
                    <x-button variant="success" wire:click="managerApprove">Approve</x-button>
                    <x-button variant="danger" wire:click="managerDecline">Decline</x-button>
                @endif
                @if ($this->canActAsCeo())
                    <x-button variant="success" wire:click="ceoApprove">Approve Final</x-button>
                    <x-button variant="danger" wire:click="ceoDecline">Decline Final</x-button>
                @endif
            </div>
        </x-card>
    @endif
</div>
