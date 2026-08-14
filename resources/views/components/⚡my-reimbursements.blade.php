<?php

use App\Models\ReimbursementRequest;
use App\Services\ReimbursementService;
use App\Support\StoresReceipt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The employee's own claims. Filing and following one's own money back.
 *
 * Deliberately separate from the approval screen: an employee should never be
 * shown a queue of other people's spending, and whoever approves should not
 * have to scroll past their own claims to find it.
 */
new #[Layout('layouts.app')] class extends Component
{
    use StoresReceipt, WithFileUploads;

    public bool $showForm = false;
    public ?string $statusMessage = null;

    public string $amount = '';
    public string $expenseDate = '';
    public string $category = 'travel';
    public string $description = '';
    public $receipt = null;

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
        $this->statusMessage = 'Claim submitted. Once approved it is paid on your next payslip.';
    }

    public function cancel(int $id, ReimbursementService $service): void
    {
        $service->cancel(ReimbursementRequest::findOrFail($id), Auth::user()->employee);
        $this->statusMessage = 'Claim withdrawn.';
    }

    public function with(): array
    {
        $employee = Auth::user()?->employee;

        $claims = $employee
            ? ReimbursementRequest::where('employee_id', $employee->id)->latest()->get()
            : collect();

        return [
            'claims' => $claims,
            'categories' => ReimbursementRequest::categories(),
            'awaiting' => $claims->where('status', 'approved')->whereNull('payslip_id')->sum(fn ($c) => $c->effectiveAmount()),
            'hasEmployee' => $employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Reimbursement</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Money you spent for the company, paid back on your next payslip. It is not taxed — it is your own money returning.
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

    @unless ($hasEmployee)
        <x-card>
            <p class="text-sm font-medium text-[#778599]">Your account is not linked to an employee record yet. Ask HR to sort it out.</p>
        </x-card>
    @else
        @if ($awaiting > 0)
            <x-card>
                <p class="text-xs font-medium text-[#778599]">Approved and coming on your next payslip</p>
                <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format((float) $awaiting, 2) }}</p>
            </x-card>
        @endif

        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">My Claims</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">What for</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Spent on</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Claimed</th>
                            <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Approved</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($claims as $claim)
                            <tr wire:key="mine-{{ $claim->id }}">
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
                                    @elseif ($claim->decision_note)
                                        <span class="block max-w-xs truncate text-xs font-medium text-[#778599]">{{ $claim->decision_note }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($claim->isPending())
                                        <button wire:click="cancel({{ $claim->id }})" wire:confirm="Withdraw this claim?"
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Withdraw</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center font-medium text-[#778599]">You have not claimed anything yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endunless

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
</div>
