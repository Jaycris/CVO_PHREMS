<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CommissionAdvance;
use App\Models\Employee;
use App\Services\Commission\CommissionAdvanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Commission paid to an agent before they have earned it.
 *
 * Collected out of their commission runs rather than their salary: an agent
 * can be repaying a payroll cash advance at the same time, and neither should
 * eat the other's instalment.
 *
 * Nothing here is deducted by hand. Record the advance, and every run takes
 * its instalment until the balance is clear — shrinking it on a thin month
 * rather than driving a slip negative.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public ?string $statusMessage = null;

    public ?int $employee_id = null;
    public string $principal_amount = '';
    public string $amount_per_run = '';
    public string $released_on = '';
    public string $note = '';

    public function mount(): void
    {
        $this->released_on = now()->toDateString();
    }

    protected function guard(): void
    {
        abort_unless(Auth::user()->can('commissions.runs.manage'), 403, 'You cannot record commission advances.');
    }

    public function create(): void
    {
        $this->guard();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();

        $advance = CommissionAdvance::findOrFail($id);

        $this->editingId = $advance->id;
        $this->employee_id = $advance->employee_id;
        $this->principal_amount = (string) (float) $advance->principal_amount;
        $this->amount_per_run = $advance->amount_per_run === null ? '' : (string) (float) $advance->amount_per_run;
        $this->released_on = $advance->released_on->toDateString();
        $this->note = (string) $advance->note;
        $this->showForm = true;
    }

    public function save(CommissionAdvanceService $service): void
    {
        $this->guard();

        $data = $this->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'principal_amount' => ['required', 'numeric', 'min:1'],
            // Blank means take whatever the run can bear until it is clear.
            'amount_per_run' => ['nullable', 'numeric', 'min:1'],
            'released_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $perRun = $data['amount_per_run'] === '' || $data['amount_per_run'] === null
            ? null
            : (float) $data['amount_per_run'];

        if ($this->editingId) {
            // The principal is not editable: it is what was handed over, and a
            // record of money that changes afterwards is worth nothing.
            $service->update(CommissionAdvance::findOrFail($this->editingId), $perRun, $data['note'] ?: null);
            $this->statusMessage = 'Advance updated.';
        } else {
            $service->open(
                Employee::findOrFail($data['employee_id']),
                (float) $data['principal_amount'],
                $perRun,
                $data['released_on'],
                $data['note'] ?: null,
                Auth::user(),
            );

            $this->statusMessage = 'Advance recorded. It will be collected from their next commission run.';
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function toggleHold(int $id, CommissionAdvanceService $service): void
    {
        $this->guard();

        $advance = CommissionAdvance::findOrFail($id);
        $onHold = $advance->status === CommissionAdvance::ON_HOLD;

        $service->setHold($advance, ! $onHold);

        $this->statusMessage = $onHold
            ? 'Collection resumed. The next run takes an instalment.'
            : 'Collection paused. The balance stands, but no run will take anything.';
    }

    public function cancel(int $id, CommissionAdvanceService $service): void
    {
        $this->guard();

        $service->cancel(CommissionAdvance::findOrFail($id));

        $this->statusMessage = 'Advance cancelled. What was already repaid stays repaid.';
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'employee_id', 'principal_amount', 'amount_per_run', 'note']);
        $this->released_on = now()->toDateString();
        $this->resetValidation();
    }

    public function with(): array
    {
        $advances = CommissionAdvance::with(['employee', 'payments'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'on_hold' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate($this->perPage());

        return [
            'advances' => $advances,
            'agents' => Employee::whereNull('separation_date')->orderBy('employee_id')->get(),
            'outstanding' => CommissionAdvance::with('payments')
                ->whereIn('status', [CommissionAdvance::ACTIVE, CommissionAdvance::ON_HOLD])
                ->get()
                ->sum(fn (CommissionAdvance $advance) => $advance->balance()),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Commission Advances</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Money paid to an agent before they earn it, collected from their commission runs.
                ₱{{ number_format($outstanding, 2) }} still owed.
            </p>
        </div>

        <x-button wire:click="create" pill>
            <x-icon name="plus" class="h-4 w-4" /> Record an Advance
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($showForm)
        <x-card>
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">{{ $editingId ? 'Edit advance' : 'Record an advance' }}</h2>

            <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <x-label>Agent</x-label>
                    <x-select wire:model="employee_id" :disabled="(bool) $editingId">
                        <option value="">Select agent</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->employee_id }} - {{ $agent->fullName() }}</option>
                        @endforeach
                    </x-select>
                    @error('employee_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Date handed over</x-label>
                    <x-input wire:model="released_on" type="date" :disabled="(bool) $editingId" />
                    @error('released_on') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Amount advanced</x-label>
                    <x-input wire:model="principal_amount" type="number" step="0.01" :disabled="(bool) $editingId" />
                    @if ($editingId)
                        <p class="mt-1 text-xs font-medium text-[#778599]">The amount handed over cannot be edited. Cancel it and record a new one if it was wrong.</p>
                    @endif
                    @error('principal_amount') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Taken each run <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="amount_per_run" type="number" step="0.01" placeholder="Leave blank to take it all at once" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">
                        Collected by the next run computed after the money was handed over, whichever month that run covers.
                        A thin month takes less and leaves the rest owing; a month with no commission takes nothing.
                    </p>
                    @error('amount_per_run') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="note" type="text" maxlength="500" placeholder="What it was for, and who agreed it." />
                    @error('note') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-2 border-t border-neutral-100 pt-5 dark:border-neutral-800">
                <x-button wire:click="save">{{ $editingId ? 'Save changes' : 'Record advance' }}</x-button>
                <x-button variant="secondary" wire:click="closeForm">Cancel</x-button>
            </div>
        </x-card>
    @endif

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Agent</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Reference</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Advanced</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Each run</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Repaid</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Balance</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($advances as $advance)
                        <tr wire:key="advance-{{ $advance->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $advance->employee?->fullName() ?: '—' }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $advance->employee?->employee_id }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">
                                {{ $advance->reference_no }}
                                <span class="block text-xs">{{ $advance->released_on->format('M j, Y') }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $advance->principal_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">
                                {{ $advance->amount_per_run === null ? 'All of it' : '₱' . number_format((float) $advance->amount_per_run, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format($advance->repaid(), 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format($advance->balance(), 2) }}</td>
                            <td class="px-4 py-3"><x-badge :color="$advance->statusColor()">{{ $advance->statusLabel() }}</x-badge></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-3">
                                    @if (! in_array($advance->status, ['paid', 'cancelled'], true))
                                        <button wire:click="edit({{ $advance->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Edit</button>

                                        <button wire:click="toggleHold({{ $advance->id }})"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">
                                            {{ $advance->status === 'on_hold' ? 'Resume' : 'Pause' }}
                                        </button>

                                        <button wire:click="cancel({{ $advance->id }})"
                                                wire:confirm="Cancel this advance? The balance is written off and nothing more is collected. What was already repaid stays repaid."
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Cancel</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center font-medium text-[#778599]">No commission advances recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($advances->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $advances->links('components.pagination', ['noun' => 'advances']) }}
            </div>
        @endif
    </x-card>
</div>
