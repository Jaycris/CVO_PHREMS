<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Department;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public string $name = '';
    public string $description = '';
    public ?int $editingId = null;
    public string $search = '';

    public function create(): void
    {
        $this->reset(['name', 'description', 'editingId']);
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name,' . $this->editingId],
            'description' => ['nullable', 'string'],
        ]);

        Department::updateOrCreate(['id' => $this->editingId], $data);

        $this->reset(['name', 'description', 'editingId']);
        $this->showForm = false;
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        $department = Department::findOrFail($id);
        $this->editingId = $department->id;
        $this->name = $department->name;
        $this->description = (string) $department->description;
        $this->showForm = true;
    }

    public function editSelected(array $ids): void
    {
        if (count($ids) === 1) {
            $this->edit((int) $ids[0]);
        }
    }

    public function closeForm(): void
    {
        $this->reset(['name', 'description', 'editingId']);
        $this->showForm = false;
    }

    public function deleteSelected(array $ids): void
    {
        Department::whereIn('id', $ids)->delete();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'departments' => Department::search($this->search)->orderBy('name')->paginate($this->perPage()),
            'totalDepartments' => Department::count(),
        ];
    }
};
?>

<div class="space-y-7" x-data="{ selected: [], formOpen: $wire.entangle('showForm').live }">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Departments</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">Manage company departments and reporting structure.</p>
        </div>

        <x-button wire:click="create" @click="formOpen = true" class="h-12 rounded-xl px-5 text-sm">
            <x-icon name="plus" class="h-4 w-4" /> Add Department
        </x-button>
    </div>

    <div class="max-w-56">
        <div class="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold text-[#526783] dark:text-ink-400">Total Departments</p>
            <p class="mt-1.5 text-2xl font-bold text-ink-950 dark:text-white">{{ $totalDepartments }}</p>
            <p class="mt-1 text-xs font-medium text-brand-700 dark:text-brand-300">Active directory</p>
        </div>
    </div>

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-200 px-6 py-5 dark:border-white/10">
            <div>
                <h2 class="text-xl font-bold text-ink-950 dark:text-white">Department Directory</h2>
                <p class="mt-1 h-4 text-xs font-semibold text-amber-600 dark:text-amber-400">
                    <span x-show="selected.length > 0" x-text="selected.length + ' selected'"></span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <x-icon-button icon="pencil" variant="brand" wire:click="editSelected(selected)"
                    @click="if (selected.length === 1) formOpen = true"
                    class="h-10 w-10 rounded-xl border-ink-200 bg-ink-50 text-[#8094ad] shadow-sm hover:bg-ink-100 hover:text-[#526783] dark:border-white/10 dark:bg-white/5 dark:text-ink-300 dark:hover:bg-white/10 [&_svg]:h-4 [&_svg]:w-4"
                    x-bind:disabled="selected.length !== 1"
                    x-bind:class="selected.length !== 1 ? 'opacity-40 pointer-events-none' : ''"
                    title="Edit" />

                <x-icon-button icon="trash" variant="danger" wire:click="deleteSelected(selected)" wire:confirm="Delete the selected department(s)?"
                    class="h-10 w-10 rounded-xl border-red-200 bg-red-50 text-red-600 shadow-sm hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300 [&_svg]:h-4 [&_svg]:w-4"
                    x-bind:disabled="selected.length === 0"
                    x-bind:class="selected.length === 0 ? 'opacity-40 pointer-events-none' : ''"
                    title="Delete" />

                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-ink-500">
                        <x-icon name="search" class="h-5 w-5" />
                    </span>
                    <input type="text" wire:model.live.debounce.300ms="search" @input="selected = []" placeholder="Search departments..."
                        class="h-10 w-72 rounded-xl border border-ink-200 bg-white py-2 pl-11 pr-4 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-500 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-ink-200 dark:divide-white/10">
                <thead class="bg-ink-50 dark:bg-white/5">
                    <tr>
                        <th class="w-14 px-5 py-4">
                            <input type="checkbox"
                                x-bind:checked="selected.length === {{ $departments->count() }} && {{ $departments->count() }} > 0"
                                @click="selected = (selected.length === {{ $departments->count() }}) ? [] : [{{ $departments->getCollection()->pluck('id')->implode(',') }}].map(String)"
                                class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-800">
                        </th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Name</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                    @forelse ($departments as $department)
                        <tr wire:key="dept-{{ $department->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5" x-bind:class="selected.includes('{{ $department->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''">
                            <td class="px-5 py-4">
                                <input type="checkbox" x-model="selected" value="{{ $department->id }}"
                                    class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-800">
                            </td>
                            <td class="px-5 py-4 text-sm font-medium text-[#526783] dark:text-white">{{ $department->name }}</td>
                            <td class="px-5 py-4 text-sm font-medium text-[#64748b] dark:text-ink-400">{{ $department->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-5 py-10 text-center text-sm font-medium text-ink-500">No departments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($departments->hasPages())
            <div class="border-t border-ink-200 bg-white px-5 py-4 dark:border-white/10 dark:bg-ink-900/40" @click="selected = []">
                {{ $departments->links('components.pagination', ['noun' => 'departments']) }}
            </div>
        @endif
    </x-card>

    <div x-cloak x-show="formOpen" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm" @click="formOpen = false; $wire.closeForm()"></div>

        <section x-show="formOpen"
            x-transition:enter="ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95 translate-y-2"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 translate-y-2"
            class="professional-panel relative z-10 w-full max-w-xl overflow-hidden rounded-2xl shadow-2xl shadow-ink-950/20">
            <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Department Setup</p>
                        <h2 class="mt-1 text-2xl font-bold text-ink-950 dark:text-white">{{ $editingId ? 'Edit Department' : 'Add Department' }}</h2>
                        <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Keep department names clear for employee records and reports.</p>
                    </div>
                    <button type="button" @click="formOpen = false; $wire.closeForm()"
                        class="rounded-lg p-2 text-ink-400 transition hover:bg-white hover:text-ink-700 dark:hover:bg-white/10 dark:hover:text-white">
                        <x-icon name="x-mark" class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <form wire:submit="save" class="space-y-5 p-6">
                <div>
                    <x-label>Name</x-label>
                    <x-input wire:model="name" type="text" placeholder="e.g. Sales, Operations, Human Resources" />
                    @error('name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Description</x-label>
                    <x-textarea wire:model="description" rows="4" placeholder="Briefly describe the department's function." />
                    @error('description') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-ink-100 pt-5 dark:border-white/10 sm:flex-row sm:justify-end">
                    <x-button type="button" variant="secondary" wire:click="closeForm" @click="formOpen = false" class="sm:min-w-28">Cancel</x-button>
                    <x-button type="submit" class="sm:min-w-36">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update Department' : 'Add Department' }}</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </x-button>
                </div>
            </form>
        </section>
    </div>
</div>
