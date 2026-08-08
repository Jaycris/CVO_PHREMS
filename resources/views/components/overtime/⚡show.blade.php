<?php

use App\Models\OvertimeRequest;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public OvertimeRequest $overtimeRequest;

    public string $approvedHours = '';
    public string $note = '';
    public ?string $statusMessage = null;

    public function mount(OvertimeRequest $overtimeRequest): void
    {
        $this->overtimeRequest = $overtimeRequest->load(['employee', 'manager']);

        $user = Auth::user();
        $employee = $user->employee;

        // Visible to the requester, the assigned approver, and HR/Admin only.
        $canView = $user->hasAnyRole(['Admin', 'HR'])
            || ($employee && $employee->id === $overtimeRequest->employee_id)
            || ($employee && $employee->id === $overtimeRequest->manager_id);

        abort_unless($canView, 403, 'You cannot view this overtime request.');

        $this->approvedHours = (string) $overtimeRequest->hours_requested;
    }

    public function canDecide(): bool
    {
        if (! $this->overtimeRequest->isPending()) {
            return false;
        }

        $user = Auth::user();
        $employee = $user->employee;

        if ($employee && $this->overtimeRequest->manager_id === $employee->id) {
            return true;
        }

        return $this->overtimeRequest->manager_id === null && $user->hasAnyRole(['HR', 'Admin', 'CEO']);
    }

    public function canCancel(): bool
    {
        $employee = Auth::user()->employee;

        return $this->overtimeRequest->isPending()
            && $employee
            && $employee->id === $this->overtimeRequest->employee_id;
    }

    public function approve(OvertimeService $overtimeService): void
    {
        $data = $this->validate([
            'approvedHours' => ['required', 'numeric', 'min:0.25'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $overtimeService->managerDecide(
            $this->overtimeRequest,
            Auth::user()->employee,
            true,
            (float) $data['approvedHours'],
            $data['note'] ?: null
        );

        $this->overtimeRequest->refresh()->load(['employee', 'manager']);
        $this->statusMessage = 'Overtime approved.';
    }

    public function decline(OvertimeService $overtimeService): void
    {
        $data = $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        $overtimeService->managerDecide(
            $this->overtimeRequest,
            Auth::user()->employee,
            false,
            null,
            $data['note'] ?: null
        );

        $this->overtimeRequest->refresh()->load(['employee', 'manager']);
        $this->statusMessage = 'Overtime declined.';
    }

    public function cancel(OvertimeService $overtimeService): void
    {
        $overtimeService->cancel($this->overtimeRequest, Auth::user()->employee);

        $this->overtimeRequest->refresh();
        $this->statusMessage = 'Overtime request cancelled.';
    }
};
?>

<div class="max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Overtime Request</h1>
        <a href="{{ route('overtime.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">← Back to Overtime</a>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <x-card>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Employee</dt><dd class="text-[#65758c] dark:text-white">{{ $overtimeRequest->employee->fullName() ?: $overtimeRequest->employee->employee_id }}</dd></div>
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Date Worked</dt><dd class="text-[#65758c] dark:text-white">{{ $overtimeRequest->work_date->format('M d, Y') }}</dd></div>
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Hours Filed</dt><dd class="text-[#65758c] dark:text-white">{{ rtrim(rtrim(number_format((float) $overtimeRequest->hours_requested, 2), '0'), '.') }} h</dd></div>
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Hours Approved</dt><dd class="text-[#65758c] dark:text-white">{{ $overtimeRequest->hours_approved !== null ? rtrim(rtrim(number_format((float) $overtimeRequest->hours_approved, 2), '0'), '.') . ' h' : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Reason</dt><dd class="text-right text-[#65758c] dark:text-white">{{ $overtimeRequest->reason ?: '—' }}</dd></div>
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Status</dt><dd><x-badge :color="$overtimeRequest->statusColor()">{{ $overtimeRequest->statusLabel() }}</x-badge></dd></div>
            <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Approver</dt><dd class="text-[#65758c] dark:text-white">{{ $overtimeRequest->manager?->fullName() ?? 'Unassigned (HR/Admin)' }}</dd></div>
            @if ($overtimeRequest->manager_note)
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Note</dt><dd class="text-right text-[#65758c] dark:text-white">{{ $overtimeRequest->manager_note }}</dd></div>
            @endif
        </dl>
    </x-card>

    @if ($this->canDecide())
        <x-card>
            <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Decision</h2>
            <div class="space-y-4">
                <div>
                    <x-label>Hours to Approve</x-label>
                    <x-input wire:model="approvedHours" type="number" step="0.25" min="0.25" max="{{ $overtimeRequest->hours_requested }}" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">You may approve fewer hours than were filed, but not more.</p>
                    @error('approvedHours') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="note" type="text" placeholder="Visible to the employee" />
                    @error('note') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <x-button type="button" wire:click="approve">Approve</x-button>
                    <x-button type="button" variant="danger" wire:click="decline" wire:confirm="Decline this overtime request?">Decline</x-button>
                </div>
            </div>
        </x-card>
    @endif

    @if ($this->canCancel())
        <x-card>
            <x-button type="button" variant="secondary" wire:click="cancel" wire:confirm="Withdraw this overtime request?">Withdraw Request</x-button>
        </x-card>
    @endif
</div>
