<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\AgentPayment;
use App\Models\Employee;
use App\Services\Payroll\AgentPayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Pay for sales agents who are kept out of payroll runs.
 *
 * CEO/COO only. Recording a payment writes a Money Out entry naming the agent
 * and sends the agent a pay slip. Nothing here touches a payroll run.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;

    public bool $showDelete = false;

    public ?int $editingId = null;

    public ?int $deleteId = null;

    public ?string $statusMessage = null;

    public string $filterMonth = '';

    public ?int $employeeId = null;

    public string $month = '';

    public string $description = 'Basic salary — sales target reached';

    public string $amount = '';

    public string $mtdUsd = '';

    public string $paidOn = '';

    public string $reference = '';

    public string $note = '';

    public function mount(): void
    {
        $this->guard();
    }

    protected function guard(): void
    {
        abort_unless(Auth::user()?->can('payroll.agent_pay.manage'), 403);
    }

    public function updatedFilterMonth(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->guard();

        $this->reset(['editingId', 'employeeId', 'amount', 'mtdUsd', 'reference', 'note']);
        $this->description = 'Basic salary — sales target reached';
        $this->month = now('Asia/Manila')->format('Y-m');
        $this->paidOn = now('Asia/Manila')->toDateString();

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();

        $payment = AgentPayment::findOrFail($id);

        $this->editingId = $payment->id;
        $this->employeeId = $payment->employee_id;
        $this->month = $payment->month;
        $this->description = $payment->description;
        $this->amount = (string) (float) $payment->amount;
        $this->mtdUsd = $payment->mtd_usd !== null ? (string) (float) $payment->mtd_usd : '';
        $this->paidOn = $payment->paid_on->toDateString();
        $this->reference = (string) $payment->reference;
        $this->note = (string) $payment->note;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AgentPayService $service): void
    {
        $this->guard();

        $this->validate([
            'employeeId' => ['required', 'exists:employees,id'],
            'month' => ['required', 'date_format:Y-m'],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'mtdUsd' => ['nullable', 'numeric', 'min:0'],
            'paidOn' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'employeeId' => 'agent',
            'mtdUsd' => 'MTD sales',
            'paidOn' => 'date paid',
        ]);

        $data = [
            'employee_id' => $this->employeeId,
            'month' => $this->month,
            'description' => $this->description,
            'amount' => $this->amount,
            'mtd_usd' => $this->mtdUsd,
            'paid_on' => $this->paidOn,
            'reference' => $this->reference,
            'note' => $this->note,
        ];

        if ($this->editingId) {
            $payment = $service->update(AgentPayment::findOrFail($this->editingId), $data);

            $this->statusMessage = 'Payment updated, and its Money Out entry with it. The slip was not sent again — use Send Slip if the agent needs the corrected one.';
        } else {
            $result = $service->record($data, Auth::user());
            $payment = $result['payment'];

            $this->statusMessage = $result['sent']
                ? 'Payment recorded, added to Money In & Out, and the pay slip sent to ' . $this->agentName($payment) . '.'
                : 'Payment recorded and added to Money In & Out. No slip was sent — ' . $this->agentName($payment) . ' has no active PHREMS login.';
        }

        $this->showForm = false;
        $this->reset(['editingId', 'employeeId', 'amount', 'mtdUsd', 'reference', 'note']);
    }

    public function resend(int $id, AgentPayService $service): void
    {
        $this->guard();

        $payment = AgentPayment::with('employee.user')->findOrFail($id);

        $this->statusMessage = $service->sendSlip($payment)
            ? 'Pay slip sent again to ' . $this->agentName($payment) . '.'
            : 'Could not send — ' . $this->agentName($payment) . ' has no active PHREMS login.';
    }

    public function prepareDelete(int $id): void
    {
        $this->guard();

        $this->deleteId = $id;
        $this->showDelete = true;
    }

    public function deleteConfirmed(AgentPayService $service): void
    {
        $this->guard();

        $service->delete(AgentPayment::findOrFail($this->deleteId));

        $this->deleteId = null;
        $this->showDelete = false;
        $this->statusMessage = 'Payment deleted, and its Money Out entry removed.';
    }

    protected function agentName(AgentPayment $payment): string
    {
        $employee = $payment->employee;

        return $employee?->fullName() ?: ($employee?->employee_id ?? 'the agent');
    }

    public function with(): array
    {
        $payments = AgentPayment::with(['employee', 'recordedBy'])
            ->when($this->filterMonth !== '', fn ($q) => $q->where('month', $this->filterMonth))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->paginate($this->perPage());

        return [
            'payments' => $payments,
            'employees' => Employee::query()
                ->whereNull('separation_date')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
            'monthTotal' => $this->filterMonth !== ''
                ? (float) AgentPayment::where('month', $this->filterMonth)->sum('amount')
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-3xl">
            <h1 class="text-xl font-bold text-ink-950 dark:text-white">Agent Pay</h1>
            <p class="mt-1 text-sm font-medium leading-6 text-ink-500 dark:text-ink-400">
                Pay for sales agents who are not in payroll runs. Each payment is added to Money In &amp; Out and the agent is sent a pay slip.
            </p>
        </div>

        <x-button type="button" wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')">
            <x-icon name="plus" class="h-4 w-4" /> Record Payment
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 px-6 py-4 dark:border-white/10">
            <h2 class="text-base font-bold text-ink-950 dark:text-white">Payments</h2>

            <div class="flex flex-wrap items-center gap-3">
                @if ($monthTotal !== null)
                    <span class="text-sm font-semibold text-ink-600 dark:text-ink-300">Total for the month: ₱{{ number_format($monthTotal, 2) }}</span>
                @endif
                <x-input wire:model.live="filterMonth" type="month" class="!w-44" title="Show one month" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="directory-table">
                <thead class="directory-table-head">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Agent</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">For</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Description</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Paid On</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Amount</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Slip</th>
                        <th class="px-6 py-4"></th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($payments as $payment)
                        <tr wire:key="agent-pay-{{ $payment->id }}" class="directory-row">
                            <td class="whitespace-nowrap px-6 py-4">
                                <p class="font-bold text-ink-800 dark:text-white">{{ $payment->employee?->fullName() ?: $payment->employee?->employee_id }}</p>
                                <p class="text-xs font-medium text-ink-500">{{ $payment->employee?->employee_id }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $payment->monthLabel() }}</td>
                            <td class="px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $payment->description }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 tabular-nums dark:text-ink-300">{{ $payment->paid_on->format('M j, Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-bold text-ink-950 tabular-nums dark:text-white">₱{{ number_format((float) $payment->amount, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-4">
                                @if ($payment->notified_at)
                                    <x-badge color="green">Sent {{ $payment->notified_at->format('M j') }}</x-badge>
                                @else
                                    <x-badge color="amber">Not sent</x-badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" wire:click="resend({{ $payment->id }})" title="Send the pay slip again"
                                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 text-xs font-bold text-ink-600 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10">
                                        <x-icon name="mail" class="h-4 w-4" /> Send Slip
                                    </button>
                                    <button type="button" wire:click="edit({{ $payment->id }})" @click="$dispatch('open-phrems-modal', 'showForm')" title="Edit"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-400 dark:hover:bg-white/10">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </button>
                                    <button type="button" wire:click="prepareDelete({{ $payment->id }})" @click="$dispatch('open-phrems-modal', 'showDelete')" title="Delete"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 shadow-sm transition hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <p class="text-sm font-bold text-ink-800 dark:text-white">No agent payments recorded{{ $filterMonth !== '' ? ' for that month' : '' }}.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($payments->hasPages())
            <div class="border-t border-ink-200 px-6 py-4 dark:border-white/10">
                {{ $payments->links() }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-ink-950 dark:text-white">{{ $editingId ? 'Edit Payment' : 'Record Payment' }}</h2>

        <div class="mt-5 space-y-4">
            <div>
                <x-label>Agent</x-label>
                <x-select wire:model="employeeId">
                    <option value="">Choose an agent</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->employee_id }} — {{ $employee->fullName() ?: $employee->company_email }}</option>
                    @endforeach
                </x-select>
                @error('employeeId') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Pay for (month)</x-label>
                    <x-input wire:model="month" type="month" />
                    @error('month') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Date paid</x-label>
                    <x-input wire:model="paidOn" type="date" />
                    @error('paidOn') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>What it's for</x-label>
                <x-input wire:model="description" type="text" />
                <p class="mt-1 text-xs font-medium text-ink-500 dark:text-ink-400">Printed on the agent's pay slip.</p>
                @error('description') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Amount (₱)</x-label>
                    <x-input wire:model="amount" type="number" min="0.01" step="0.01" placeholder="20000" />
                    @error('amount') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>MTD sales (USD) <span class="font-medium text-ink-500">(optional)</span></x-label>
                    <x-input wire:model="mtdUsd" type="number" min="0" step="0.01" placeholder="5000" />
                    @error('mtdUsd') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Payment reference <span class="font-medium text-ink-500">(optional)</span></x-label>
                    <x-input wire:model="reference" type="text" placeholder="e.g. bank transfer no." />
                    @error('reference') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Note <span class="font-medium text-ink-500">(optional)</span></x-label>
                    <x-input wire:model="note" type="text" />
                    @error('note') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            @unless ($editingId)
                <p class="text-xs font-medium text-ink-500 dark:text-ink-400">
                    Saving adds a Money Out entry naming the agent, and emails them their pay slip.
                </p>
            @endunless
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-button>
            <x-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                {{ $editingId ? 'Save Changes' : 'Record and Send Slip' }}
            </x-button>
        </div>
    </x-modal>

    <x-modal wire="showDelete" onClose="$set('showDelete', false)" maxWidth="sm">
        <h2 class="text-lg font-bold text-ink-950 dark:text-white">Delete this payment?</h2>
        <p class="mt-2 text-sm font-medium text-ink-500 dark:text-ink-400">
            Its Money Out entry is removed too. The agent keeps the slip they were already sent.
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <x-button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-button>
            <x-button type="button" variant="danger" wire:click="deleteConfirmed">Delete</x-button>
        </div>
    </x-modal>
</div>
