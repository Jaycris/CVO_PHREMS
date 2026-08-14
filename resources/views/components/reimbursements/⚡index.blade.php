<?php

use App\Models\ReimbursementRequest;
use App\Services\ReimbursementService;
use App\Support\StoresReceipt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use StoresReceipt, WithFileUploads;

    public bool $showForm = false;
    public bool $showDecision = false;
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public string $amount = '';
    public string $expenseDate = '';
    public string $category = 'travel';
    public string $description = '';
    public $receipt = null;

    #[Locked]
    public ?int $decidingId = null;
    public string $approvedAmount = '';
    public string $decisionNote = '';

    public function create(): void
    {
        $this->reset(['amount', 'category', 'description', 'receipt']);
        $this->category = 'travel';
        $this->expenseDate = now()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function submit(ReimbursementService $service): void
    {
        $data = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expenseDate' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', 'in:' . implode(',', array_keys(ReimbursementRequest::categories()))],
            'description' => ['required', 'string', 'max:500'],
            'receipt' => $this->receiptRules(),
        ], [
            'expenseDate.before_or_equal' => 'The expense date cannot be in the future.',
            'description.required' => 'Say what the expense was for.',
        ], [
            'expenseDate' => 'expense date',
        ]);

        $service->submit(
            Auth::user()->employee,
            (float) $data['amount'],
            $data['expenseDate'],
            $data['category'],
            $data['description'],
            $this->receipt,
        );

        $this->showForm = false;
        $this->reset(['amount', 'description', 'receipt']);
        $this->statusMessage = 'Claim submitted. Once approved it is paid on the next payroll.';
    }

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

    public function cancel(int $id, ReimbursementService $service): void
    {
        $service->cancel(ReimbursementRequest::findOrFail($id), Auth::user()->employee);
        $this->statusMessage = 'Claim withdrawn.';
    }

    public function with(): array
    {
        $user = Auth::user();
        $canApprove = $user->can('reimbursements.approve');
        $canViewAll = $user->can('reimbursements.view_all') || $canApprove;

        return [
            'queue' => $canApprove
                ? ReimbursementRequest::with('employee')->where('status', 'pending')->oldest('expense_date')->get()
                : collect(),
            'myClaims' => $user->employee
                ? ReimbursementRequest::where('employee_id', $user->employee->id)->latest()->get()
                : collect(),
            'allClaims' => $canViewAll
                ? ReimbursementRequest::with('employee')->latest()->limit(50)->get()
                : collect(),
            'deciding' => $this->decidingId ? ReimbursementRequest::with('employee')->find($this->decidingId) : null,
            'categories' => ReimbursementRequest::categories(),
            'awaitingPayment' => $canViewAll
                ? ReimbursementRequest::awaitingPayment()->sum('amount_approved')
                : 0,
            'canApprove' => $canApprove,
            'canViewAll' => $canViewAll,
            'hasEmployee' => $user->employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Reimbursements</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Money you spent for the company, paid back on the next payroll. It is not taxed — it is your own money returning.
            </p>
        </div>
        @if ($hasEmployee)
            <x-button wire:click="create" pill>
                <x-icon name="plus" class="h-4 w-4" /> Claim an Expense
            </x-button>
        @endif
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    @if ($canViewAll && $awaitingPayment > 0)
        <x-card>
            <p class="text-xs font-medium text-[#778599]">Approved and waiting for the next payroll</p>
            <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format((float) $awaitingPayment, 2) }}</p>
        </x-card>
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
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">What for</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Spent on</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Amount</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($queue as $claim)
                            <tr wire:key="q-{{ $claim->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $claim->employee->fullName() ?: $claim->employee->employee_id }}</td>
                                <td class="px-4 py-3 font-medium text-[#778599]">
                                    {{ $claim->categoryLabel() }}
                                    <span class="block max-w-md truncate text-xs font-medium text-[#778599]">{{ $claim->description }}</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $claim->expense_date->format('M j, Y') }}</td>
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
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">{{ $canViewAll ? 'All Claims' : 'My Claims' }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        @if ($canViewAll)
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                        @endif
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">What for</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Spent on</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Claimed</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Approved</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse (($canViewAll ? $allClaims : $myClaims) as $claim)
                        <tr wire:key="c-{{ $claim->id }}">
                            @if ($canViewAll)
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $claim->employee->fullName() ?: $claim->employee->employee_id }}</td>
                            @endif
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
                            <td class="px-4 py-3 text-right">
                                @if ($claim->isPending() && $claim->employee_id === auth()->user()->employee?->id)
                                    <button wire:click="cancel({{ $claim->id }})" wire:confirm="Withdraw this claim?"
                                            class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Withdraw</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canViewAll ? 7 : 6 }}" class="px-4 py-10 text-center font-medium text-[#778599]">No claims yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Claim an Expense</h2>
        <p class="mb-5 text-sm font-medium text-[#778599]">For money you paid out of pocket that the company should cover.</p>

        <form wire:submit="submit" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Amount</x-label>
                    <x-input wire:model="amount" type="number" step="0.01" min="0.01" />
                    @error('amount') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Date you spent it</x-label>
                    <x-input wire:model="expenseDate" type="date" :max="now()->toDateString()" />
                    @error('expenseDate') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>What was it for?</x-label>
                <x-select wire:model="category">
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
                @error('category') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Details</x-label>
                <x-textarea wire:model="description" rows="3" placeholder="e.g. Grab to the client office in Makati and back, for the Tuesday handover" />
                <p class="mt-1 text-xs font-medium text-[#778599]">Whoever approves this needs to know why the company should pay it.</p>
                @error('description') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Receipt <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <input type="file" wire:model="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf"
                       class="block w-full text-sm font-medium text-[#65758c] file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 dark:text-neutral-300">
                <p class="mt-1 text-xs font-medium text-[#778599]">A photo or PDF. Only you and whoever approves it can open it.</p>
                <div wire:loading wire:target="receipt" class="mt-1 text-xs font-medium text-[#778599]">Uploading…</div>
                @error('receipt') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Submit Claim</x-button>
                <x-button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-button>
            </div>
        </form>
    </x-modal>

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
                    <x-textarea wire:model="decisionNote" rows="2" />
                </div>

                <div class="flex flex-wrap gap-2 pt-2">
                    <x-button wire:click="decide(true)">Approve</x-button>
                    <x-button variant="danger" wire:click="decide(false)">Decline</x-button>
                    <x-button variant="secondary" wire:click="closeDecision">Cancel</x-button>
                </div>

                <p class="text-xs font-medium text-[#778599]">
                    Approving does not pay it. It goes onto the employee's next payslip.
                </p>
            </div>
        @endif
    </x-modal>
</div>
