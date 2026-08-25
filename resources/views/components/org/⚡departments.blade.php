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

<div class="space-y-7" x-data="{ selected: [], formOpen: $wire.entangle('showForm').live, deleteOpen: false }">
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

    <x-card :padding="false" class="directory-panel">
        <div class="directory-toolbar">
            <div>
                <h2 class="directory-title">Department Directory</h2>
                <p class="directory-selection">
                    <span x-show="selected.length > 0" x-text="selected.length + ' selected'"></span>
                </p>
            </div>

            <div class="directory-toolbar-actions">
                <x-icon-button icon="pencil" variant="brand" wire:click="editSelected(selected)"
                    @click="if (selected.length === 1) formOpen = true"

                    x-bind:disabled="selected.length !== 1"
                    x-bind:class="selected.length !== 1 ? 'opacity-40 pointer-events-none' : ''"
                    title="Edit" />

                <x-icon-button icon="trash" variant="danger"
                    @click="if (selected.length > 0) deleteOpen = true"
                    x-bind:disabled="selected.length === 0"
                    x-bind:class="selected.length === 0 ? 'opacity-40 pointer-events-none' : ''"
                    title="Delete" />

                <label class="directory-search">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400">
                        <x-icon name="search" class="h-4 w-4" />
                    </span>
                    <input type="text" wire:model.live.debounce.300ms="search" @input="selected = []" placeholder="Search departments..."
                        class="block h-10 w-full rounded-lg border border-ink-200 bg-white pl-9 pr-3.5 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-400 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                </label>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="directory-table">
                <thead class="directory-table-head">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input type="checkbox"
                                x-bind:checked="selected.length === {{ $departments->count() }} && {{ $departments->count() }} > 0"
                                @click="selected = (selected.length === {{ $departments->count() }}) ? [] : [{{ $departments->getCollection()->pluck('id')->implode(',') }}].map(String)"
                                class="directory-checkbox">
                        </th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Name</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Description</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($departments as $department)
                        <tr wire:key="dept-{{ $department->id }}" class="directory-row cursor-pointer" @click="selected.includes('{{ $department->id }}') ? selected = selected.filter((id) => id !== '{{ $department->id }}') : selected.push('{{ $department->id }}')" x-bind:class="selected.includes('{{ $department->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''">
                            <td class="px-6 py-4" @click.stop>
                                <input type="checkbox" x-model="selected" value="{{ $department->id }}"
                                    class="directory-checkbox">
                            </td>
                            <td class="px-4 py-4 font-bold text-ink-800 dark:text-white">{{ $department->name }}</td>
                            <td class="px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $department->description }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="building" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No departments found</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Add the first department or adjust your search.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($departments->hasPages())
            <div class="directory-pagination" @click="selected = []">
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

    <div x-cloak x-show="deleteOpen" @keydown.escape.window="deleteOpen = false"
        class="fixed inset-0 z-[110] flex h-[100dvh] w-screen items-center justify-center overflow-hidden p-4">
        <div class="absolute inset-0 bg-ink-950/55 backdrop-blur-sm" @click="deleteOpen = false"></div>

        <section x-show="deleteOpen"
            x-transition:enter="ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-2 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-2 scale-95"
            class="professional-panel relative z-10 w-full max-w-md overflow-hidden rounded-xl shadow-2xl shadow-ink-950/20">
            <div class="p-6">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300">
                        <x-icon name="trash" class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600 dark:text-red-300">Confirm deletion</p>
                        <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Delete selected departments?</h2>
                        <p class="mt-2 text-sm font-medium leading-6 text-ink-500 dark:text-ink-400"
                            x-text="selected.length === 1 ? 'This department will be permanently removed.' : selected.length + ' departments will be permanently removed.'"></p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-ink-200 bg-ink-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                <x-button type="button" variant="secondary" @click="deleteOpen = false">Cancel</x-button>
                <x-button type="button" variant="danger"
                    @click="deleteOpen = false; $wire.deleteSelected(selected); selected = []">
                    Delete
                </x-button>
            </div>
        </section>
    </div></div>
