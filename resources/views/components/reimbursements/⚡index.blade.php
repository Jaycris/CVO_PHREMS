<?php

use App\Models\ReimbursementRequest;
use App\Services\ReimbursementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The back-office view: what the company owes, and deciding on it.
 *
 * Filing a claim lives on My Reimbursement instead. Keeping them apart means
 * an approver is not scrolling past their own claims to find the queue, and an
 * employee is never shown a list of what everyone else has been spending.
 */
new #[Layout('layouts.app')] class extends Component
{
    public bool $showDecision = false;
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;
    public string $search = '';

    #[Locked]
    public ?int $decidingId = null;
    public string $approvedAmount = '';
    public string $decisionNote = '';

    public function review(int $id): void
    {
        $claim = ReimbursementRequest::findOrFail($id);

        $this->decidingId = $claim->id;
        $this->approvedAmount = (string) $claim->effectiveAmount();
        $this->decisionNote = '';
        $this->resetValidation();
        $this->showDecision = true;
    }

    public function decide(bool $approved, ReimbursementService $service): void
    {
        $this->errorMessage = null;

        if ($approved) {
            $this->validate(['approvedAmount' => ['required', 'numeric', 'min:0.01']]);
        }

        try {
            $service->decide(
                ReimbursementRequest::with('employee')->findOrFail($this->decidingId),
                Auth::user(),
                $approved,
                $approved ? (float) $this->approvedAmount : null,
                $this->decisionNote ?: null,
            );

            $this->statusMessage = $approved
                ? 'Approved. It will be paid on the next payroll run.'
                : 'Claim declined.';

            $this->closeDecision();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function closeDecision(): void
    {
        $this->reset(['decidingId', 'approvedAmount', 'decisionNote']);
        $this->resetValidation();
        $this->showDecision = false;
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'queue' => $user->can('reimbursements.approve')
                ? ReimbursementRequest::with('employee')->where('status', 'pending')->oldest('expense_date')->get()
                : collect(),
            'claims' => ReimbursementRequest::with('employee')
                ->when($this->search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e
                    ->where('first_name', 'like', "%{$this->search}%")
                    ->orWhere('last_name', 'like', "%{$this->search}%")
                    ->orWhere('employee_id', 'like', "%{$this->search}%")))
                ->latest()
                ->limit(100)
                ->get(),
            'deciding' => $this->decidingId ? ReimbursementRequest::with('employee')->find($this->decidingId) : null,
            'awaitingPayment' => ReimbursementRequest::awaitingPayment()->sum('amount_approved'),
            'paidThisYear' => ReimbursementRequest::whereNotNull('payslip_id')
                ->whereYear('paid_on', now()->year)
                ->sum('amount_approved'),
            'canApprove' => $user->can('reimbursements.approve'),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Reimbursement Record</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            Expenses staff paid out of pocket. Approving does not pay anything — the next payroll run does.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <x-card>
            <p class="text-xs font-medium text-[#778599]">Waiting for approval</p>
            <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $queue->count() }}</p>
        </x-card>
        <x-card>
            <p class="text-xs font-medium text-[#778599]">Approved, on the next payroll</p>
            <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format((float) $awaitingPayment, 2) }}</p>
        </x-card>
        <x-card>
            <p class="text-xs font-medium text-[#778599]">Paid out in {{ now()->year }}</p>
            <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $paidThisYear, 2) }}</p>
        </x-card>
    </div>

    @if ($queue->isNotEmpty())
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Awaiting Your Approval</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">What for</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Spent on</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Receipt</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Amount</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($queue as $claim)
                            <tr wire:key="q-{{ $claim->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                    {{ $claim->employee->fullName() ?: $claim->employee->employee_id }}
                                    <span class="block text-xs font-medium text-[#778599]">{{ $claim->employee->employee_id }}</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">
                                    {{ $claim->categoryLabel() }}
                                    <span class="block max-w-md truncate text-xs font-medium text-[#778599]">{{ $claim->description }}</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $claim->expense_date->format('M j, Y') }}</td>
                                <td class="px-4 py-3">
                                    @if ($claim->receipt_path)
                                        <x-badge color="green">Attached</x-badge>
                                    @else
                                        <x-badge color="amber">None</x-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $claim->amount_requested, 2) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button wire:click="review({{ $claim->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Review</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">All Claims</h2>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employee…"
                   class="w-64 rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-[#65758c] shadow-sm placeholder:text-[#778599] focus:border-brand-500 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">What for</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Spent on</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Claimed</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Approved</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($claims as $claim)
                        <tr wire:key="c-{{ $claim->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $claim->employee->fullName() ?: $claim->employee->employee_id }}</td>
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-neutral-300">
                                {{ $claim->categoryLabel() }}
                                <span class="block max-w-xs truncate text-xs font-medium text-[#778599]">{{ $claim->description }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $claim->expense_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $claim->amount_requested, 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">
                                {{ $claim->amount_approved !== null ? '₱' . number_format((float) $claim->amount_approved, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :color="$claim->statusColor()">{{ $claim->statusLabel() }}</x-badge>
                                @if ($claim->isPaid())
                                    <span class="block text-xs font-medium text-[#778599]">{{ $claim->paid_on?->format('M j, Y') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center font-medium text-[#778599]">No claims yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showDecision" onClose="closeDecision" maxWidth="lg">
        @if ($deciding)
            <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Review Claim</h2>
            <p class="mb-4 text-sm font-medium text-[#778599]">
                {{ $deciding->employee->fullName() ?: $deciding->employee->employee_id }} &middot;
                {{ $deciding->categoryLabel() }} &middot;
                spent {{ $deciding->expense_date->format('M j, Y') }}
            </p>

            <div class="mb-4 rounded-lg bg-[#f8fafc] p-3 text-sm font-medium text-[#65758c] dark:bg-neutral-800/50 dark:text-neutral-300">
                {{ $deciding->description }}
            </div>

            @if ($deciding->receipt_path)
                <p class="mb-4 text-sm font-medium text-[#778599]">A receipt was attached.</p>
            @else
                <p class="mb-4 text-sm font-medium text-amber-600 dark:text-amber-400">No receipt was attached.</p>
            @endif

            <div class="space-y-4">
                <div>
                    <x-label>Amount to pay back</x-label>
                    <x-input wire:model="approvedAmount" type="number" step="0.01" min="0.01" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">
                        Claimed ₱{{ number_format((float) $deciding->amount_requested, 2) }}. You may approve less, but not more.
                    </p>
                    @error('approvedAmount') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-textarea wire:model="decisionNote" rows="2" placeholder="The employee sees this" />
                </div>

                {{-- Deciding sends an email, which on a slow mail host takes a
                     second or two. Without a busy state the button looks dead and
                     gets clicked again, so every control is disabled for the
                     duration and the one that was pressed says what it is doing. --}}
                <div class="flex flex-wrap gap-2 pt-2" wire:loading.class="opacity-70" wire:target="decide">
                    <x-button wire:click="decide(true)" wire:loading.attr="disabled" wire:target="decide">
                        <span wire:loading.remove wire:target="decide(true)">Approve</span>
                        <span wire:loading wire:target="decide(true)">Approving…</span>
                    </x-button>
                    <x-button variant="danger" wire:click="decide(false)" wire:loading.attr="disabled" wire:target="decide">
                        <span wire:loading.remove wire:target="decide(false)">Decline</span>
                        <span wire:loading wire:target="decide(false)">Declining…</span>
                    </x-button>
                    <x-button variant="secondary" wire:click="closeDecision" wire:loading.attr="disabled" wire:target="decide">Cancel</x-button>
                </div>

                <p class="text-xs font-medium text-[#778599]">
                    Approving does not pay it. It goes onto the employee's next payslip.
                </p>
            </div>
        @endif
    </x-modal>
</div>
