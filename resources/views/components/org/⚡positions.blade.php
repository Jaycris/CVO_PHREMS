<?php

use App\Models\Position;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public string $title = '';
    public string $description = '';
    public bool $is_supervisory = false;
    /** @var list<string> */
    public array $permissions = [];
    public ?int $editingId = null;
    public string $search = '';
    public int $perPage = 10;

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
            'positions' => Position::with('permissions')->search($this->search)->orderBy('title')->paginate($this->perPage),
            'totalPositions' => Position::count(),
            'permissionGroups' => config('permissions.groups'),
        ];
    }
};
?>

<div class="space-y-7" x-data="{ selected: [], formOpen: $wire.entangle('showForm').live }">
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

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-200 px-6 py-5 dark:border-white/10">
            <div>
                <h2 class="text-xl font-bold text-ink-950 dark:text-white">Position Directory</h2>
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

                <x-icon-button icon="trash" variant="danger" wire:click="deleteSelected(selected)" wire:confirm="Delete the selected position(s)?"
                    class="h-10 w-10 rounded-xl border-red-200 bg-red-50 text-red-600 shadow-sm hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300 [&_svg]:h-4 [&_svg]:w-4"
                    x-bind:disabled="selected.length === 0"
                    x-bind:class="selected.length === 0 ? 'opacity-40 pointer-events-none' : ''"
                    title="Delete" />

                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-ink-500">
                        <x-icon name="search" class="h-5 w-5" />
                    </span>
                    <input type="text" wire:model.live.debounce.300ms="search" @input="selected = []" placeholder="Search positions..."
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
                                x-bind:checked="selected.length === {{ $positions->count() }} && {{ $positions->count() }} > 0"
                                @click="selected = (selected.length === {{ $positions->count() }}) ? [] : [{{ $positions->getCollection()->pluck('id')->implode(',') }}].map(String)"
                                class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-800">
                        </th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Title</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Description</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Supervisory</th>
                        <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Access</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                    @forelse ($positions as $position)
                        <tr wire:key="pos-{{ $position->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5" x-bind:class="selected.includes('{{ $position->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''">
                            <td class="px-5 py-4">
                                <input type="checkbox" x-model="selected" value="{{ $position->id }}"
                                    class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-800">
                            </td>
                            <td class="px-5 py-4 text-sm font-medium text-[#526783] dark:text-white">{{ $position->title }}</td>
                            <td class="px-5 py-4 text-sm font-medium text-[#64748b] dark:text-ink-400">{{ $position->description }}</td>
                            <td class="px-5 py-4 text-sm">
                                <x-badge :color="$position->is_supervisory ? 'brand' : 'neutral'">{{ $position->is_supervisory ? 'Yes' : 'No' }}</x-badge>
                            </td>
                            <td class="px-5 py-4 text-sm">
                                @if ($position->permissions->isEmpty())
                                    <span class="text-sm font-medium text-ink-500">Self-service only</span>
                                @else
                                    <x-badge color="brand">{{ $position->permissions->count() }} {{ Str::plural('permission', $position->permissions->count()) }}</x-badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-sm font-medium text-ink-500">No positions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($positions->hasPages())
            <div class="border-t border-ink-200 bg-white px-5 py-4 dark:border-white/10 dark:bg-ink-900/40" @click="selected = []">
                {{ $positions->links('components.pagination') }}
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

            <form wire:submit="save" class="space-y-5 p-6">
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

                <div class="flex flex-col-reverse gap-3 border-t border-ink-100 pt-5 dark:border-white/10 sm:flex-row sm:justify-end">
                    <x-button type="button" variant="secondary" wire:click="closeForm" @click="formOpen = false" class="sm:min-w-28">Cancel</x-button>
                    <x-button type="submit" class="sm:min-w-36">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update Position' : 'Add Position' }}</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </x-button>
                </div>
            </form>
        </section>
    </div>
</div>
