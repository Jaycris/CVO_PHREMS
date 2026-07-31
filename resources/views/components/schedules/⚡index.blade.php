<?php

use App\Models\WorkSchedule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showForm = false;
    public string $name = '';
    public string $start_time = '';
    public string $end_time = '';
    public string $lunch_break_minutes = '60';
    public string $coffee_break_minutes = '30';
    public ?int $editingId = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:work_schedules,name,' . $this->editingId],
            'start_time' => ['required'],
            'end_time' => ['required'],
            'lunch_break_minutes' => ['required', 'integer', 'min:0'],
            'coffee_break_minutes' => ['required', 'integer', 'min:0'],
        ]);

        WorkSchedule::updateOrCreate(['id' => $this->editingId], $data);

        $this->resetForm();
        $this->showForm = false;
    }

    public function edit(int $id): void
    {
        $schedule = WorkSchedule::findOrFail($id);
        $this->editingId = $schedule->id;
        $this->name = $schedule->name;
        $this->start_time = $schedule->start_time->format('H:i');
        $this->end_time = $schedule->end_time->format('H:i');
        $this->lunch_break_minutes = (string) $schedule->lunch_break_minutes;
        $this->coffee_break_minutes = (string) $schedule->coffee_break_minutes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'start_time', 'end_time', 'editingId']);
        $this->lunch_break_minutes = '60';
        $this->coffee_break_minutes = '30';
    }

    public function delete(int $id): void
    {
        WorkSchedule::findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'schedules' => WorkSchedule::orderBy('name')->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Work Schedules</h1>
        <x-button wire:click="create">
            <x-icon name="plus" class="h-4 w-4" /> Add Schedule
        </x-button>
    </div>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Hours</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Lunch</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Coffee</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($schedules as $schedule)
                        <tr wire:key="sched-{{ $schedule->id }}">
                            <td class="px-4 py-3 text-sm font-medium text-[#65758c] dark:text-white">{{ $schedule->name }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-[#778599] dark:text-neutral-400">{{ $schedule->start_time->format('g:i A') }} – {{ $schedule->end_time->format('g:i A') }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-[#778599] dark:text-neutral-400">{{ $schedule->lunch_break_minutes }} min</td>
                            <td class="px-4 py-3 text-sm font-medium text-[#778599] dark:text-neutral-400">{{ $schedule->coffee_break_minutes }} min</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <x-icon-button icon="pencil" variant="brand" wire:click="edit({{ $schedule->id }})" title="Edit" />
                                    <x-icon-button icon="trash" variant="danger" wire:click="delete({{ $schedule->id }})" wire:confirm="Delete this schedule?" title="Delete" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-sm font-medium text-[#778599]">No schedules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="closeForm">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">{{ $editingId ? 'Edit Schedule' : 'Add Schedule' }}</h2>
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-label>Name</x-label>
                <x-input wire:model="name" type="text" placeholder="e.g. Graveyard" />
                @error('name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-label>Start Time</x-label>
                    <x-input wire:model="start_time" type="time" />
                    @error('start_time') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>End Time</x-label>
                    <x-input wire:model="end_time" type="time" />
                    @error('end_time') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-label>Lunch Break (min)</x-label>
                    <x-input wire:model="lunch_break_minutes" type="number" />
                </div>
                <div>
                    <x-label>Coffee Break (min)</x-label>
                    <x-input wire:model="coffee_break_minutes" type="number" />
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <x-button type="submit">{{ $editingId ? 'Update' : 'Add' }}</x-button>
                <x-button type="button" variant="secondary" wire:click="closeForm">Cancel</x-button>
            </div>
        </form>
    </x-modal>
</div>
