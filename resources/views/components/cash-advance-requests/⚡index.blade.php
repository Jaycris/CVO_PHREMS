<?php

use App\Models\CashAdvanceRequest;
use App\Services\CashAdvanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showForm = false;
    public bool $showDecision = false;
    public ?string $statusMessage = null;

    public string $amount = '';
    public string $perCutoff = '';
    public string $neededBy = '';
    public string $reason = '';

    #[Locked]
    public ?int $decidingId = null;
    public string $approvedAmount = '';
    public string $approvedPerCutoff = '';
    public string $startDate = '';
    public string $decisionNote = '';

    public function create(): void
    {
        $this->reset(['amount', 'perCutoff', 'neededBy', 'reason']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function submit(CashAdvanceService $service): void
    {
        $data = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'perCutoff' => ['required', 'numeric', 'min:0.01', 'lte:amount'],
            'neededBy' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['perCutoff' => 'per-cutoff deduction']);

        $service->submitRequest(
            Auth::user()->employee,
            (float) $data['amount'],
            (float) $data['perCutoff'],
            $data['neededBy'] ?: null,
            $data['reason'],
        );

        $this->showForm = false;
        $this->reset(['amount', 'perCutoff', 'neededBy', 'reason']);
        $this->statusMessage = 'Request submitted. Your manager has been notified.';
    }

    public function review(int $id): void
    {
        $request = CashAdvanceRequest::findOrFail($id);

        $this->decidingId = $request->id;
        $this->approvedAmount = (string) $request->effectiveAmount();
        $this->approvedPerCutoff = (string) $request->effectivePerCutoff();
        $this->startDate = now()->toDateString();
        $this->decisionNote = '';
        $this->resetValidation();
        $this->showDecision = true;
    }

    public function decide(bool $approved, CashAdvanceService $service): void
    {
        $request = CashAdvanceRequest::with('employee')->findOrFail($this->decidingId);
        $employee = Auth::user()->employee;

        if ($approved) {
            $this->validate([
                'approvedAmount' => ['required', 'numeric', 'min:0.01'],
                'approvedPerCutoff' => ['required', 'numeric', 'min:0.01', 'lte:approvedAmount'],
                'startDate' => ['required', 'date'],
            ]);
        }

        $note = $this->decisionNote ?: null;

        if ($request->status === 'pending_manager') {
            $service->managerDecide(
                $request,
                $employee,
                $approved,
                $approved ? (float) $this->approvedAmount : null,
                $approved ? (float) $this->approvedPerCutoff : null,
                $note,
            );

            $this->statusMessage = $approved
                ? 'Endorsed. The request now sits with the CEO/COO for final approval.'
                : 'Request declined.';
        } else {
            $service->ceoDecide(
                $request,
                Auth::user(),
                $approved,
                $approved ? (float) $this->approvedAmount : null,
                $approved ? (float) $this->approvedPerCutoff : null,
                $note,
                $approved ? $this->startDate : null,
            );

            $this->statusMessage = $approved
                ? 'Approved. The advance has been opened and HR notified.'
                : 'Request declined.';
        }

        $this->closeDecision();
    }

    public function closeDecision(): void
    {
        $this->reset(['decidingId', 'approvedAmount', 'approvedPerCutoff', 'decisionNote']);
        $this->resetValidation();
        $this->showDecision = false;
    }

    public function cancel(int $id, CashAdvanceService $service): void
    {
        $service->cancelRequest(CashAdvanceRequest::findOrFail($id), Auth::user()->employee);
        $this->statusMessage = 'Request withdrawn.';
    }

    public function with(): array
    {
        $user = Auth::user();
        $employee = $user->employee;
        $isAdminHr = $user->hasAnyRole(['Admin', 'HR']);
        $isCeo = $user->hasAnyRole(['CEO', 'Admin']);

        $queue = collect();

        if ($employee) {
            $queue = CashAdvanceRequest::with('employee')
                ->where('status', 'pending_manager')
                ->where('manager_id', $employee->id)
                ->get();
        }

        // Requests from employees with no manager on file would otherwise sit
        // unseen at the first tier, so HR/Admin pick them up.
        if ($isAdminHr) {
            $queue = $queue->merge(
                CashAdvanceRequest::with('employee')
                    ->where('status', 'pending_manager')
                    ->whereNull('manager_id')
                    ->get()
            );
        }

        if ($isCeo) {
            $queue = $queue->merge(
                CashAdvanceRequest::with('employee')->where('status', 'pending_ceo')->get()
            );
        }

        return [
            'queue' => $queue->unique('id')->sortBy('created_at')->values(),
            'myRequests' => $employee
                ? CashAdvanceRequest::where('employee_id', $employee->id)->latest()->get()
                : collect(),
            'allRequests' => $isAdminHr
                ? CashAdvanceRequest::with('employee')->latest()->limit(50)->get()
                : collect(),
            'deciding' => $this->decidingId ? CashAdvanceRequest::with('employee')->find($this->decidingId) : null,
            'isAdminHr' => $isAdminHr,
            'hasEmployee' => $employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Cash Advance Requests</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Approved by your manager, then the CEO/COO, before any money is released.</p>
        </div>
        @if ($hasEmployee)
            <x-button wire:click="create" pill>
                <x-icon name="plus" class="h-4 w-4" /> Request Advance
            </x-button>
        @endif
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($queue->isNotEmpty())
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Awaiting Your Approval</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Amount</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Needed By</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Stage</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($queue as $request)
                            <tr wire:key="queue-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">₱{{ number_format($request->effectiveAmount(), 2) }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $request->needed_by?->format('M d, Y') ?? '—' }}</td>
                                <td class="px-4 py-3"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                                <td class="px-4 py-3 text-right">
                                    <button wire:click="review({{ $request->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Review</button>
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
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">{{ $isAdminHr ? 'All Requests' : 'My Requests' }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        @if ($isAdminHr)
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        @endif
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Requested</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Approved</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Per Cutoff</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Filed</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse (($isAdminHr ? $allRequests : $myRequests) as $request)
                        <tr wire:key="car-{{ $request->id }}">
                            @if ($isAdminHr)
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                            @endif
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">₱{{ number_format((float) $request->amount_requested, 2) }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                {{ $request->amount_approved !== null ? '₱' . number_format((float) $request->amount_approved, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">₱{{ number_format($request->effectivePerCutoff(), 2) }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $request->created_at->format('M d, Y') }}</td>
                            <td class="px-4 py-3"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                            <td class="px-4 py-3 text-right">
                                @if ($request->isPending() && $request->employee_id === auth()->user()->employee?->id)
                                    <button wire:click="cancel({{ $request->id }})" wire:confirm="Withdraw this request?"
                                            class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Withdraw</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isAdminHr ? 7 : 6 }}" class="px-4 py-8 text-center font-medium text-[#778599]">No cash advance requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">Request Cash Advance</h2>

        <form wire:submit="submit" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Amount Needed</x-label>
                    <x-input wire:model="amount" type="number" step="0.01" min="0.01" />
                    @error('amount') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Repay Per Cutoff</x-label>
                    <x-input wire:model="perCutoff" type="number" step="0.01" min="0.01" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">Deducted from each payslip until settled.</p>
                    @error('perCutoff') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>Needed By <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="neededBy" type="date" />
                @error('neededBy') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Reason</x-label>
                <x-textarea wire:model="reason" rows="3" placeholder="e.g. Hospital bill for a family member" />
                @error('reason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Submit Request</x-button>
                <x-button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$showDecision" onClose="closeDecision" maxWidth="lg">
        @if ($deciding)
            <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Review Cash Advance Request</h2>
            <p class="mb-4 text-sm font-medium text-[#778599]">
                {{ $deciding->employee->fullName() ?: $deciding->employee->employee_id }} &middot;
                requested ₱{{ number_format((float) $deciding->amount_requested, 2) }}
                @if ($deciding->needed_by) &middot; needed by {{ $deciding->needed_by->format('M d, Y') }} @endif
            </p>

            <div class="mb-4 rounded-lg bg-[#f8fafc] p-3 text-sm font-medium text-[#65758c] dark:bg-neutral-800/50 dark:text-neutral-300">
                {{ $deciding->reason }}
            </div>

            @if ($deciding->manager_note)
                <p class="mb-4 text-sm font-medium text-[#778599]">Manager note: {{ $deciding->manager_note }}</p>
            @endif

            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-label>Approve Amount</x-label>
                        <x-input wire:model="approvedAmount" type="number" step="0.01" min="0.01" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">You may release less than requested.</p>
                        @error('approvedAmount') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Deduct Per Cutoff</x-label>
                        <x-input wire:model="approvedPerCutoff" type="number" step="0.01" min="0.01" />
                        @error('approvedPerCutoff') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($deciding->status === 'pending_ceo')
                    <div>
                        <x-label>Start Deducting From</x-label>
                        <x-input wire:model="startDate" type="date" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">The first payroll period ending on or after this date.</p>
                        @error('startDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-textarea wire:model="decisionNote" rows="2" />
                </div>

                <div class="flex gap-2 pt-2">
                    <x-button wire:click="decide(true)">
                        {{ $deciding->status === 'pending_ceo' ? 'Approve & Release' : 'Endorse to CEO/COO' }}
                    </x-button>
                    <x-button variant="danger" wire:click="decide(false)">Decline</x-button>
                    <x-button variant="secondary" wire:click="closeDecision">Cancel</x-button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
