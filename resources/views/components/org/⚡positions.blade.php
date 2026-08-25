<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Position;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public string $title = '';
    public string $description = '';
    public bool $is_supervisory = false;
    /** @var list<string> */
    public array $permissions = [];
    public ?int $editingId = null;
    public string $search = '';

    public function create(): void
    {
        $this->reset(['title', 'description', 'is_supervisory', 'permissions', 'editingId']);
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255', 'unique:positions,title,' . $this->editingId],
            'description' => ['nullable', 'string'],
            'is_supervisory' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $position = Position::updateOrCreate(
            ['id' => $this->editingId],
            collect($data)->except('permissions')->all()
        );

        // The link is live rather than copied, so revising a position updates
        // everyone holding it on their next request.
        $position->syncPermissions($data['permissions'] ?? []);

        $this->reset(['title', 'description', 'is_supervisory', 'permissions', 'editingId']);
        $this->showForm = false;
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        $position = Position::with('permissions')->findOrFail($id);
        $this->editingId = $position->id;
        $this->title = $position->title;
        $this->description = (string) $position->description;
        $this->is_supervisory = (bool) $position->is_supervisory;
        $this->permissions = $position->permissions->pluck('name')->all();
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
        $this->reset(['title', 'description', 'is_supervisory', 'permissions', 'editingId']);
        $this->showForm = false;
    }

    public function deleteSelected(array $ids): void
    {
        Position::whereIn('id', $ids)->delete();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'positions' => Position::with('permissions')->search($this->search)->orderBy('title')->paginate($this->perPage()),
            'totalPositions' => Position::count(),
            'permissionGroups' => config('permissions.groups'),
        ];
    }
};
?>

<div class="space-y-7" x-data="{ selected: [], formOpen: $wire.entangle('showForm').live, deleteOpen: false }">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Positions</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">Manage job titles and role descriptions across the organization.</p>
        </div>

        <x-button wire:click="create" @click="formOpen = true" class="h-12 rounded-xl px-5 text-sm">
            <x-icon name="plus" class="h-4 w-4" /> Add Position
        </x-button>
    </div>

    <div class="max-w-56">
        <div class="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-ink-900">
            <p class="text-xs font-semibold text-[#526783] dark:text-ink-400">Total Positions</p>
            <p class="mt-1.5 text-2xl font-bold text-ink-950 dark:text-white">{{ $totalPositions }}</p>
            <p class="mt-1 text-xs font-medium text-brand-700 dark:text-brand-300">Active directory</p>
        </div>
    </div>

    <x-card :padding="false" class="directory-panel">
        <div class="directory-toolbar">
            <div>
                <h2 class="directory-title">Position Directory</h2>
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
                    <input type="text" wire:model.live.debounce.300ms="search" @input="selected = []" placeholder="Search positions..."
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
                                x-bind:checked="selected.length === {{ $positions->count() }} && {{ $positions->count() }} > 0"
                                @click="selected = (selected.length === {{ $positions->count() }}) ? [] : [{{ $positions->getCollection()->pluck('id')->implode(',') }}].map(String)"
                                class="directory-checkbox">
                        </th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Title</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Description</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Supervisory</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Access</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($positions as $position)
                        <tr wire:key="pos-{{ $position->id }}" class="directory-row cursor-pointer" @click="selected.includes('{{ $position->id }}') ? selected = selected.filter((id) => id !== '{{ $position->id }}') : selected.push('{{ $position->id }}')" x-bind:class="selected.includes('{{ $position->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''">
                            <td class="px-6 py-4" @click.stop>
                                <input type="checkbox" x-model="selected" value="{{ $position->id }}"
                                    class="directory-checkbox">
                            </td>
                            <td class="px-4 py-4 font-bold text-ink-800 dark:text-white">{{ $position->title }}</td>
                            <td class="px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $position->description }}</td>
                            <td class="px-4 py-4">
                                <x-badge :color="$position->is_supervisory ? 'brand' : 'neutral'">{{ $position->is_supervisory ? 'Yes' : 'No' }}</x-badge>
                            </td>
                            <td class="px-4 py-4">
                                @if ($position->permissions->isEmpty())
                                    <span class="text-sm font-medium text-ink-500">Self-service only</span>
                                @else
                                    <x-badge color="brand">{{ $position->permissions->count() }} {{ Str::plural('permission', $position->permissions->count()) }}</x-badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="tag" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No positions found</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Add the first position or adjust your search.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($positions->hasPages())
            <div class="directory-pagination" @click="selected = []">
                {{ $positions->links('components.pagination', ['noun' => 'positions']) }}
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
            {{-- Capped to the viewport: the permission list is long, and the Save
                 button has to stay reachable without scrolling the page behind. --}}
            class="professional-panel relative z-10 flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl shadow-2xl shadow-ink-950/20">
            <div class="shrink-0 border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Position Setup</p>
                        <h2 class="mt-1 text-2xl font-bold text-ink-950 dark:text-white">{{ $editingId ? 'Edit Position' : 'Add Position' }}</h2>
                        <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Keep job titles consistent for employee records and reporting.</p>
                    </div>
                    <button type="button" @click="formOpen = false; $wire.closeForm()"
                        class="rounded-lg p-2 text-ink-400 transition hover:bg-white hover:text-ink-700 dark:hover:bg-white/10 dark:hover:text-white">
                        <x-icon name="x-mark" class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
              <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-6">
                <div>
                    <x-label>Title</x-label>
                    <x-input wire:model="title" type="text" placeholder="e.g. Team Leader, QA Analyst, HR Specialist" />
                    @error('title') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Description</x-label>
                    <x-textarea wire:model="description" rows="4" placeholder="Briefly describe the position's responsibilities." />
                    @error('description') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="flex items-start gap-2 text-sm font-medium text-[#65758c] dark:text-ink-300">
                        <input type="checkbox" wire:model="is_supervisory" class="mt-0.5 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                        <span>
                            Supervisory position
                            <span class="block text-xs font-medium text-[#778599]">
                                Team Leader, Manager, COO, CEO. Only employees holding a supervisory
                                position appear in an employee's Reports To list, and leave approvals route to them.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="border-t border-ink-100 pt-5 dark:border-white/10">
                    <x-label>What this position may access</x-label>
                    <p class="mt-1 text-xs font-medium text-[#778599]">
                        Everyone holding this position gets these, provided their user account is on the
                        Admin tier. An Employee-tier account stays on self-service no matter what is ticked here.
                    </p>

                    <div class="mt-4 space-y-5">
                        @foreach ($permissionGroups as $group => $items)
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-ink-300">{{ $group }}</p>
                                <div class="mt-2 space-y-1.5">
                                    @foreach ($items as $name => $label)
                                        <label class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 text-sm font-medium text-[#65758c] transition hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-white/5">
                                            <input type="checkbox" wire:model="permissions" value="{{ $name }}"
                                                   class="mt-0.5 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @error('permissions.*') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
              </div>

              <div class="flex shrink-0 flex-col-reverse gap-3 border-t border-ink-200 bg-ink-50 px-6 py-4 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:justify-end">
                    <x-button type="button" variant="secondary" wire:click="closeForm" @click="formOpen = false" class="sm:min-w-28">Cancel</x-button>
                    <x-button type="submit" class="sm:min-w-36">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update Position' : 'Add Position' }}</span>
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
                        <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Delete selected positions?</h2>
                        <p class="mt-2 text-sm font-medium leading-6 text-ink-500 dark:text-ink-400"
                            x-text="selected.length === 1 ? 'This position will be permanently removed.' : selected.length + ' positions will be permanently removed.'"></p>
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
