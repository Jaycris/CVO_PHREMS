<?php

use App\Models\LeaveType;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showForm = false;
    public string $name = '';
    public string $code = '';
    public string $accrual_mode = 'annual_upfront';
    public string $default_annual_credits = '0';
    public string $monthly_accrual_rate = '';
    public string $accrual_day_of_month = '';
    public bool $resets_annually = true;
    public bool $allow_carry_over = false;
    public bool $allow_cash_conversion = false;
    public bool $is_active = true;
    public ?int $editingId = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', 'unique:leave_types,name,' . $this->editingId],
            'code' => ['required', 'string', 'max:10', 'unique:leave_types,code,' . $this->editingId],
            'accrual_mode' => ['required', 'in:annual_upfront,monthly_accrual,event_based'],
            'default_annual_credits' => ['required', 'numeric', 'min:0'],
            'accrual_day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
        ];

        if ($this->accrual_mode === 'monthly_accrual') {
            $rules['monthly_accrual_rate'] = ['required', 'numeric', 'min:0'];
            $rules['accrual_day_of_month'] = ['required', 'integer', 'min:1', 'max:28'];
        }

        $data = $this->validate($rules);
        $data['resets_annually'] = $this->resets_annually;
        $data['allow_carry_over'] = $this->allow_carry_over;
        $data['allow_cash_conversion'] = $this->allow_cash_conversion;
        $data['is_active'] = $this->is_active;

        if ($this->accrual_mode !== 'monthly_accrual') {
            $data['monthly_accrual_rate'] = null;
        }

        LeaveType::updateOrCreate(['id' => $this->editingId], $data);

        $this->resetForm();
        $this->showForm = false;
    }

    public function edit(int $id): void
    {
        $type = LeaveType::findOrFail($id);
        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->code = $type->code;
        $this->accrual_mode = $type->accrual_mode;
        $this->default_annual_credits = (string) $type->default_annual_credits;
        $this->monthly_accrual_rate = (string) $type->monthly_accrual_rate;
        $this->accrual_day_of_month = (string) $type->accrual_day_of_month;
        $this->resets_annually = $type->resets_annually;
        $this->allow_carry_over = $type->allow_carry_over;
        $this->allow_cash_conversion = $type->allow_cash_conversion;
        $this->is_active = $type->is_active;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function resetForm(): void
    {
        $this->reset([
            'name', 'code', 'monthly_accrual_rate', 'accrual_day_of_month', 'editingId',
        ]);
        $this->accrual_mode = 'annual_upfront';
        $this->default_annual_credits = '0';
        $this->resets_annually = true;
        $this->allow_carry_over = false;
        $this->allow_cash_conversion = false;
        $this->is_active = true;
    }

    public function with(): array
    {
        return [
            'leaveTypes' => LeaveType::orderBy('name')->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Leave Types</h1>
        <x-button wire:click="create">
            <x-icon name="plus" class="h-4 w-4" /> Add Leave Type
        </x-button>
    </div>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Code</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Accrual</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Credits</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Rules</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($leaveTypes as $type)
                        <tr wire:key="lt-{{ $type->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">{{ $type->code }}</td>
                            <td class="px-4 py-3 text-[#65758c] dark:text-white">{{ $type->name }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">
                                {{ str($type->accrual_mode)->replace('_', ' ')->headline() }}
                                @if ($type->accrual_mode === 'monthly_accrual')
                                    ({{ $type->monthly_accrual_rate }}/mo on day {{ $type->accrual_day_of_month }})
                                @endif
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599] dark:text-neutral-400">{{ $type->default_annual_credits }} days</td>
                            <td class="px-4 py-3">
                                @if ($type->resets_annually) <x-badge class="mr-1">Resets</x-badge> @endif
                                @if ($type->allow_carry_over) <x-badge class="mr-1">Carry-over</x-badge> @endif
                                @if ($type->allow_cash_conversion) <x-badge class="mr-1">Cash-out</x-badge> @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <x-icon-button icon="pencil" variant="brand" wire:click="edit({{ $type->id }})" title="Edit" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center font-medium text-[#778599]">No leave types yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="closeForm" maxWidth="lg">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">{{ $editingId ? 'Edit Leave Type' : 'Add Leave Type' }}</h2>
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Name</x-label>
                    <x-input wire:model="name" type="text" />
                    @error('name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Code</x-label>
                    <x-input wire:model="code" type="text" />
                    @error('code') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Accrual Mode</x-label>
                    <x-select wire:model.live="accrual_mode">
                        <option value="annual_upfront">Annual (upfront grant)</option>
                        <option value="monthly_accrual">Monthly Accrual</option>
                        <option value="event_based">Event-based / One-time</option>
                    </x-select>
                </div>
                <div>
                    <x-label>Default Annual Credits</x-label>
                    <x-input wire:model="default_annual_credits" type="number" step="0.01" />
                    @error('default_annual_credits') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($accrual_mode === 'monthly_accrual')
                <div class="grid grid-cols-1 gap-4 rounded-lg bg-brand-50 p-4 sm:grid-cols-2 dark:bg-brand-900/20">
                    <div>
                        <x-label>Monthly Accrual Rate (days/month)</x-label>
                        <x-input wire:model="monthly_accrual_rate" type="number" step="0.001" />
                        @error('monthly_accrual_rate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Accrual Day of Month</x-label>
                        <x-input wire:model="accrual_day_of_month" type="number" min="1" max="28" />
                        @error('accrual_day_of_month') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap gap-4 text-sm font-medium text-[#778599] dark:text-neutral-400">
                <label class="flex items-center gap-2"><input wire:model="resets_annually" type="checkbox" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800"> Resets annually</label>
                <label class="flex items-center gap-2"><input wire:model="allow_carry_over" type="checkbox" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800"> Allow carry-over</label>
                <label class="flex items-center gap-2"><input wire:model="allow_cash_conversion" type="checkbox" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800"> Allow cash conversion</label>
                <label class="flex items-center gap-2"><input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800"> Active</label>
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">{{ $editingId ? 'Update' : 'Add' }} Leave Type</x-button>
                <x-button type="button" variant="secondary" wire:click="closeForm">Cancel</x-button>
            </div>
        </form>
    </x-modal>
</div>
