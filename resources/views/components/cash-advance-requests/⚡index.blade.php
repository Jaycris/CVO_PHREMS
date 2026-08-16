<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CashAdvanceRequest;
use App\Services\CashAdvanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public bool $showDecision = false;
    public ?string $statusMessage = null;

    public string $amount = '';
    public string $deductionPlan = 'split_two_cutoffs';
    public string $neededBy = '';
    public string $reason = '';

    #[Locked]
    public ?int $decidingId = null;
    public string $approvedAmount = '';
    public string $approvedPlan = 'split_two_cutoffs';
    public string $startDate = '';
    public string $decisionNote = '';

    public function create(): void
    {
        $this->reset(['amount', 'deductionPlan', 'neededBy', 'reason']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function submit(CashAdvanceService $service): void
    {
        $data = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . $service->maxRequestAmount()],
            'deductionPlan' => ['required', 'in:' . implode(',', array_keys(CashAdvanceRequest::deductionPlans()))],
            'neededBy' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'amount.max' => 'A cash advance request cannot exceed ₱' . number_format($service->maxRequestAmount(), 2) . '.',
            'deductionPlan.required' => 'Choose how this will be deducted from your salary.',
        ]);

        $service->submitRequest(
            Auth::user()->employee,
            (float) $data['amount'],
            $data['deductionPlan'],
            $data['neededBy'] ?: null,
            $data['reason'],
        );

        $this->showForm = false;
        $this->reset(['amount', 'deductionPlan', 'neededBy', 'reason']);
        $this->statusMessage = 'Request submitted. It is now with the CEO/COO for approval.';
    }

    public function review(int $id): void
    {
        $request = CashAdvanceRequest::findOrFail($id);

        $this->decidingId = $request->id;
        $this->approvedAmount = (string) $request->effectiveAmount();
        $this->approvedPlan = $request->deduction_plan;
        $this->startDate = now()->toDateString();
        $this->decisionNote = '';
        $this->resetValidation();
        $this->showDecision = true;
    }

    /** HR and the accountant revise the figures; only the CEO/COO decides. */
    public function amend(CashAdvanceService $service): void
    {
        $this->validate([
            'approvedAmount' => ['required', 'numeric', 'min:0.01'],
            'approvedPlan' => ['required', 'in:' . implode(',', array_keys(CashAdvanceRequest::deductionPlans()))],
        ]);

        $service->amendRequest(
            CashAdvanceRequest::findOrFail($this->decidingId),
            Auth::user(),
            (float) $this->approvedAmount,
            $this->approvedPlan,
        );

        $this->closeDecision();
        $this->statusMessage = 'Amount updated. The request is still waiting on the CEO/COO.';
    }

    public function decide(bool $approved, CashAdvanceService $service): void
    {
        if ($approved) {
            $this->validate([
                'approvedAmount' => ['required', 'numeric', 'min:0.01'],
                'approvedPlan' => ['required', 'in:' . implode(',', array_keys(CashAdvanceRequest::deductionPlans()))],
                'startDate' => ['required', 'date'],
            ]);
        }

        $service->decide(
            CashAdvanceRequest::with('employee')->findOrFail($this->decidingId),
            Auth::user(),
            $approved,
            $approved ? (float) $this->approvedAmount : null,
            $approved ? $this->approvedPlan : null,
            $this->decisionNote ?: null,
            $approved ? $this->startDate : null,
        );

        $this->statusMessage = $approved
            ? 'Approved. The advance is now in the register and HR has been notified.'
            : 'Request declined.';

        $this->closeDecision();
    }

    public function closeDecision(): void
    {
        $this->reset(['decidingId', 'approvedAmount', 'approvedPlan', 'decisionNote']);
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
        $service = app(CashAdvanceService::class);
        $isBackOffice = $user->can('cash_advances.view_all');

        // Each table pages on its own name, so paging one leaves the rest be.
        $empty = fn (string $name) => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage(), 1, ['pageName' => $name]);

        return [
            // Anyone who can approve or amend needs to see what is waiting.
            'queue' => ($service->canApprove($user) || $service->canAmend($user))
                ? CashAdvanceRequest::with('employee')->where('status', 'pending')->oldest()
                    ->paginate($this->perPage(), pageName: 'queue')
                : $empty('queue'),
            'myRequests' => $user->employee
                ? CashAdvanceRequest::where('employee_id', $user->employee->id)->latest()
                    ->paginate($this->perPage(), pageName: 'mine')
                : $empty('mine'),
            // Was capped at 50, which quietly hid everything older.
            'allRequests' => $isBackOffice
                ? CashAdvanceRequest::with('employee')->latest()
                    ->paginate($this->perPage(), pageName: 'all')
                : $empty('all'),
            'deciding' => $this->decidingId ? CashAdvanceRequest::with('employee')->find($this->decidingId) : null,
            'plans' => CashAdvanceRequest::deductionPlans(),
            'maxAmount' => $service->maxRequestAmount(),
            'canApprove' => $service->canApprove($user),
            'canAmend' => $service->canAmend($user),
            'isBackOffice' => $isBackOffice,
            'hasEmployee' => $user->employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Cash Advance Requests</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Approved by the CEO/COO before any money is released.</p>
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

    @if ($queue->total() > 0)
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">
                    {{ $canApprove ? 'Awaiting Your Approval' : 'Pending Approval' }}
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Amount</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Deduction</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Needed By</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($queue as $request)
                            <tr wire:key="queue-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                    ₱{{ number_format($request->effectiveAmount(), 2) }}
                                    @if ($request->wasAmended())
                                        <span class="block text-xs font-medium text-[#778599]">asked ₱{{ number_format((float) $request->amount_requested, 2) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                    ₱{{ number_format($request->perCutoffAmount(), 2) }}
                                    <span class="block text-xs font-medium text-[#778599]">
                                        {{ $request->deduction_plan === 'full_next_payroll' ? 'one cutoff' : 'over two cutoffs' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $request->needed_by?->format('M d, Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button wire:click="review({{ $request->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">
                                        {{ $canApprove ? 'Review' : 'Adjust Amount' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($queue->hasPages())
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    {{ $queue->links('components.pagination', ['noun' => 'requests waiting']) }}
                </div>
            @endif
        </x-card>
    @endif

    @php($listed = $isBackOffice ? $allRequests : $myRequests)

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">{{ $isBackOffice ? 'All Requests' : 'My Requests' }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        @if ($isBackOffice)
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        @endif
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Requested</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Approved</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Deduction Plan</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Filed</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($listed as $request)
                        <tr wire:key="car-{{ $request->id }}">
                            @if ($isBackOffice)
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $request->employee->fullName() ?: $request->employee->employee_id }}</td>
                            @endif
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">₱{{ number_format((float) $request->amount_requested, 2) }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                {{ $request->amount_approved !== null ? '₱' . number_format((float) $request->amount_approved, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                {{ $request->deductionPlanLabel() }}
                                <span class="block text-xs font-medium text-[#778599]">₱{{ number_format($request->perCutoffAmount(), 2) }} per cutoff</span>
                            </td>
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
                        <tr><td colspan="{{ $isBackOffice ? 7 : 6 }}" class="px-4 py-8 text-center font-medium text-[#778599]">No cash advance requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($listed->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $listed->links('components.pagination', ['noun' => 'cash advance requests']) }}
            </div>
        @endif
    </x-card>

    <x-modal :show="$showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">Request Cash Advance</h2>

        <form wire:submit="submit" class="space-y-4">
            <div>
                <x-label>Amount Needed</x-label>
                <x-input wire:model="amount" type="number" step="0.01" min="0.01" :max="$maxAmount" />
                <p class="mt-1 text-xs font-medium text-[#778599]">Up to ₱{{ number_format($maxAmount, 2) }} per request.</p>
                @error('amount') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>How should this be deducted from your salary?</x-label>
                <div class="mt-2 space-y-2">
                    @foreach ($plans as $value => $label)
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-neutral-200 p-3 hover:bg-[#f8fafc] dark:border-neutral-700 dark:hover:bg-neutral-800/50">
                            <input type="radio" wire:model.live="deductionPlan" value="{{ $value }}"
                                   class="mt-0.5 border-neutral-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm">
                                <span class="block font-medium text-[#65758c] dark:text-white">{{ $label }}</span>
                                <span class="block text-xs font-medium text-[#778599]">
                                    @if ($amount && is_numeric($amount))
                                        ₱{{ number_format(\App\Models\CashAdvanceRequest::perCutoffFor((float) $amount, $value), 2) }} per cutoff
                                    @else
                                        {{ $value === 'full_next_payroll' ? 'Taken in one go on the next payroll.' : 'Half on the 15th, half on the 30th.' }}
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('deductionPlan') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
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
            <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">
                {{ $canApprove ? 'Review Cash Advance Request' : 'Adjust Cash Advance Amount' }}
            </h2>
            <p class="mb-4 text-sm font-medium text-[#778599]">
                {{ $deciding->employee->fullName() ?: $deciding->employee->employee_id }} &middot;
                asked for ₱{{ number_format((float) $deciding->amount_requested, 2) }}
                @if ($deciding->needed_by) &middot; needed by {{ $deciding->needed_by->format('M d, Y') }} @endif
            </p>

            <div class="mb-4 rounded-lg bg-[#f8fafc] p-3 text-sm font-medium text-[#65758c] dark:bg-neutral-800/50 dark:text-neutral-300">
                {{ $deciding->reason }}
            </div>

            @if ($deciding->wasAmended())
                <p class="mb-4 text-sm font-medium text-amber-600 dark:text-amber-400">
                    Amount already revised to ₱{{ number_format((float) $deciding->amount_approved, 2) }}
                    @if ($deciding->amendedBy) by {{ $deciding->amendedBy->name }} @endif
                </p>
            @endif

            <div class="space-y-4">
                <div>
                    <x-label>Amount to Release</x-label>
                    <x-input wire:model.live="approvedAmount" type="number" step="0.01" min="0.01" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">You may release more or less than requested.</p>
                    @error('approvedAmount') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Deduction Plan</x-label>
                    <x-select wire:model.live="approvedPlan">
                        @foreach ($plans as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    @if ($approvedAmount && is_numeric($approvedAmount))
                        <p class="mt-1 text-xs font-medium text-[#778599]">
                            ₱{{ number_format(\App\Models\CashAdvanceRequest::perCutoffFor((float) $approvedAmount, $approvedPlan), 2) }} deducted per cutoff.
                        </p>
                    @endif
                    @error('approvedPlan') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($canApprove)
                    <div>
                        <x-label>Start Deducting From</x-label>
                        <x-input wire:model="startDate" type="date" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">The first payroll period ending on or after this date.</p>
                        @error('startDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-textarea wire:model="decisionNote" rows="2" />
                    </div>
                @endif

                <div class="flex flex-wrap gap-2 pt-2">
                    @if ($canApprove)
                        <x-button wire:click="decide(true)">Approve &amp; Release</x-button>
                        <x-button variant="danger" wire:click="decide(false)">Decline</x-button>
                    @else
                        <x-button wire:click="amend">Save Amount</x-button>
                    @endif
                    <x-button variant="secondary" wire:click="closeDecision">Cancel</x-button>
                </div>

                @unless ($canApprove)
                    <p class="text-xs font-medium text-[#778599]">Approval rests with the CEO/COO. Saving here only revises the figures they will see.</p>
                @endunless
            </div>
        @endif
    </x-modal>
</div>
