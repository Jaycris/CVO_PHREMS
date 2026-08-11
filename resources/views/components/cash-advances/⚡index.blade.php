<?php

use App\Models\CashAdvance;
use App\Models\Employee;
use App\Services\CashAdvanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;
    public ?string $statusMessage = null;
    public string $search = '';

    public ?int $employeeId = null;
    public string $principal = '';
    public string $perCutoff = '';
    public string $startDate = '';
    public string $note = '';

    public function create(): void
    {
        $this->reset(['employeeId', 'principal', 'perCutoff', 'note', 'editingId']);
        $this->startDate = now()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $advance = CashAdvance::findOrFail($id);

        $this->editingId = $advance->id;
        $this->employeeId = $advance->employee_id;
        $this->principal = (string) $advance->principal_amount;
        $this->perCutoff = (string) $advance->amount_per_cutoff;
        $this->startDate = $advance->start_date->toDateString();
        $this->note = (string) $advance->note;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(CashAdvanceService $service): void
    {
        // The principal is fixed once opened, so editing only validates the
        // fields that can actually change.
        $rules = $this->editingId
            ? ['perCutoff' => ['required', 'numeric', 'min:0.01'], 'note' => ['nullable', 'string', 'max:500']]
            : [
                'employeeId' => ['required', 'exists:employees,id'],
                'principal' => ['required', 'numeric', 'min:0.01'],
                'perCutoff' => ['required', 'numeric', 'min:0.01'],
                'startDate' => ['required', 'date'],
                'note' => ['nullable', 'string', 'max:500'],
            ];

        $data = $this->validate($rules);

        if ($this->editingId) {
            $service->update(CashAdvance::findOrFail($this->editingId), (float) $data['perCutoff'], $data['note'] ?: null);
            $this->statusMessage = 'Cash advance updated.';
        } else {
            $service->open(
                Employee::findOrFail($data['employeeId']),
                (float) $data['principal'],
                (float) $data['perCutoff'],
                $data['startDate'],
                $data['note'] ?: null,
                Auth::user(),
            );
            $this->statusMessage = 'Cash advance recorded.';
        }

        $this->closeForm();
    }

    public function closeForm(): void
    {
        $this->reset(['employeeId', 'principal', 'perCutoff', 'note', 'editingId']);
        $this->resetValidation();
        $this->showForm = false;
    }

    public function toggleHold(int $id, CashAdvanceService $service): void
    {
        $advance = CashAdvance::findOrFail($id);
        $service->setHold($advance, $advance->status !== 'on_hold');
        $this->statusMessage = $advance->fresh()->status === 'on_hold'
            ? 'Deductions paused for this advance.'
            : 'Deductions resumed for this advance.';
    }

    public function cancel(int $id, CashAdvanceService $service): void
    {
        $service->cancel(CashAdvance::findOrFail($id));
        $this->statusMessage = 'Cash advance cancelled.';
    }

    public function with(): array
    {
        return [
            'advances' => CashAdvance::with(['employee', 'payments'])
                ->when($this->search !== '', fn ($q) => $q
                    ->where('reference_no', 'like', "%{$this->search}%")
                    ->orWhereHas('employee', fn ($e) => $e
                        ->where('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")
                        ->orWhere('employee_id', 'like', "%{$this->search}%")))
                ->latest('start_date')
                ->get(),
            'employees' => Employee::orderBy('employee_id')->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Cash Advances</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Repaid automatically each cutoff until the balance clears.</p>
        </div>
        <x-button wire:click="create" pill>
            <x-icon name="plus" class="h-4 w-4" /> Record Advance
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Advance Register</h2>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employee or reference…"
                   class="w-64 rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-[#65758c] shadow-sm placeholder:text-[#778599] focus:border-brand-500 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Reference</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Principal</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Per Cutoff</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Paid</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Balance</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($advances as $advance)
                        <tr wire:key="ca-{{ $advance->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $advance->reference_no }}</td>
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $advance->employee->fullName() ?: $advance->employee->employee_id }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $advance->employee->employee_id }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">₱{{ number_format((float) $advance->principal_amount, 2) }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">₱{{ number_format((float) $advance->amount_per_cutoff, 2) }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">₱{{ number_format($advance->totalPaid(), 2) }}</td>
                            <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">₱{{ number_format($advance->remainingBalance(), 2) }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$advance->statusColor()">{{ $advance->statusLabel() }}</x-badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-3">
                                    @if (! in_array($advance->status, ['paid', 'cancelled'], true))
                                        <button wire:click="edit({{ $advance->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Edit</button>
                                        <button wire:click="toggleHold({{ $advance->id }})" class="font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400">
                                            {{ $advance->status === 'on_hold' ? 'Resume' : 'Hold' }}
                                        </button>
                                        @unless ($advance->payments->isNotEmpty())
                                            <button wire:click="cancel({{ $advance->id }})" wire:confirm="Cancel this advance? Nothing has been repaid yet."
                                                    class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Cancel</button>
                                        @endunless
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center font-medium text-[#778599]">No cash advances recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="closeForm" maxWidth="lg">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">{{ $editingId ? 'Edit Cash Advance' : 'Record Cash Advance' }}</h2>

        <form wire:submit="save" class="space-y-4">
            <div>
                <x-label>Employee</x-label>
                <x-select wire:model="employeeId" :disabled="$editingId !== null">
                    <option value="">Select employee</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->employee_id }} — {{ $employee->fullName() ?: $employee->company_email }}</option>
                    @endforeach
                </x-select>
                @error('employeeId') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Advance Amount</x-label>
                    <x-input wire:model="principal" type="number" step="0.01" min="0.01" :disabled="$editingId !== null" />
                    @if ($editingId)
                        <p class="mt-1 text-xs font-medium text-[#778599]">Fixed once the advance is released.</p>
                    @endif
                    @error('principal') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Deduct Per Cutoff</x-label>
                    <x-input wire:model="perCutoff" type="number" step="0.01" min="0.01" />
                    @error('perCutoff') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>Start Deducting From</x-label>
                <x-input wire:model="startDate" type="date" :disabled="$editingId !== null" />
                <p class="mt-1 text-xs font-medium text-[#778599]">The first payroll period ending on or after this date.</p>
                @error('startDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-textarea wire:model="note" rows="2" placeholder="e.g. Medical emergency" />
                @error('note') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">{{ $editingId ? 'Save Changes' : 'Record Advance' }}</x-button>
                <x-button type="button" variant="secondary" wire:click="closeForm">Cancel</x-button>
            </div>
        </form>
    </x-modal>
</div>
