<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\RequestType;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The kinds of request employees can file.
 *
 * Rows rather than code, so HR adds "Uniform" or "Overtime pre-approval" the
 * day they need it. Deactivating a type hides it from the form without
 * touching what has already been filed under it.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public string $name = '';
    public string $description = '';
    public string $instructions = '';
    public bool $needs_dates = false;
    public bool $is_active = true;
    public string $sort_order = '0';

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'instructions']);
        $this->needs_dates = false;
        $this->is_active = true;
        $this->sort_order = (string) (RequestType::max('sort_order') + 1);
        $this->resetValidation();
        $this->errorMessage = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $type = RequestType::findOrFail($id);

        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->description = (string) $type->description;
        $this->instructions = (string) $type->instructions;
        $this->needs_dates = (bool) $type->needs_dates;
        $this->is_active = (bool) $type->is_active;
        $this->sort_order = (string) $type->sort_order;

        $this->resetValidation();
        $this->errorMessage = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120', 'unique:request_types,name,' . $this->editingId],
            'description' => ['nullable', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:200'],
            'needs_dates' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        RequestType::updateOrCreate(['id' => $this->editingId], $data);

        $this->showForm = false;
        $this->statusMessage = $this->editingId ? 'Request type updated.' : 'Request type added.';
        $this->reset(['editingId', 'name', 'description', 'instructions']);
    }

    public function toggleActive(int $id): void
    {
        $type = RequestType::findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);

        $this->statusMessage = $type->name . ($type->is_active
            ? ' is accepting requests again.'
            : ' is hidden from the form. Requests already filed under it are untouched.');
    }

    public function delete(int $id): void
    {
        $this->errorMessage = null;
        $type = RequestType::withCount('requests')->findOrFail($id);

        // Deleting would cascade and take the history with it. Hiding it does
        // the job the person actually wanted.
        if ($type->requests_count > 0) {
            $this->errorMessage = $type->name . ' has ' . $type->requests_count
                . ' request(s) filed under it, so it cannot be deleted. Turn it off instead.';

            return;
        }

        $type->delete();
        $this->statusMessage = 'Request type removed.';
    }

    public function with(): array
    {
        return [
            'types' => RequestType::withCount('requests')->ordered()->paginate($this->perPage()),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Request Types</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                What employees can file on the Requests page. Add a type here and it appears there straight away.
            </p>
        </div>

        <x-button wire:click="create" @click="$wire.showForm = true" pill>
            <x-icon name="plus" class="h-4 w-4" /> Add Request Type
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Name</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Description</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Asks for dates</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Filed</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($types as $type)
                        <tr wire:key="rt-{{ $type->id }}" class="{{ $type->is_active ? '' : 'opacity-60' }}">
                            <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">{{ $type->name }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $type->description ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$type->needs_dates ? 'blue' : 'neutral'">
                                    {{ $type->needs_dates ? 'Yes' : 'No' }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $type->requests_count }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$type->is_active ? 'green' : 'neutral'">
                                    {{ $type->is_active ? 'Accepting' : 'Off' }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-button wire:click="edit({{ $type->id }})" @click="$wire.showForm = true"
                                              variant="secondary" class="h-9 px-3 text-xs">Edit</x-button>
                                    <x-button wire:click="toggleActive({{ $type->id }})"
                                              variant="secondary" class="h-9 px-3 text-xs">
                                        {{ $type->is_active ? 'Turn off' : 'Turn on' }}
                                    </x-button>
                                    @if ($type->requests_count === 0)
                                        <x-button wire:click="delete({{ $type->id }})"
                                                  wire:confirm="Remove this request type?"
                                                  variant="secondary" class="h-9 px-3 text-xs">Delete</x-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center font-medium text-[#778599]">
                            No request types yet.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($types->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $types->links('components.pagination', ['noun' => 'request types']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
            {{ $editingId ? 'Edit Request Type' : 'Add Request Type' }}
        </h2>

        <div class="mt-5 space-y-4">
            <div>
                <x-label>Name</x-label>
                <x-input wire:model="name" type="text" placeholder="e.g. Uniform Request" />
                @error('name') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Description <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="description" type="text" placeholder="Shown under the name when picking a type" />
                @error('description') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>What to tell the employee <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="instructions" type="text" placeholder="e.g. Say what size you need" />
                <p class="mt-1 text-xs font-medium text-[#778599]">Appears under the Details box on the form.</p>
                @error('instructions') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Asks for dates?</x-label>
                    <x-select wire:model="needs_dates">
                        <option value="0">No — just details</option>
                        <option value="1">Yes — pick specific days</option>
                    </x-select>
                    <p class="mt-1 text-xs font-medium text-[#778599]">
                        Yes for work from home or a shift change. No for a certificate or equipment.
                    </p>
                </div>
                <div>
                    <x-label>Order in the list</x-label>
                    <x-input wire:model="sort_order" type="number" min="0" />
                    @error('sort_order') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>Accepting requests?</x-label>
                <x-select wire:model="is_active">
                    <option value="1">Yes</option>
                    <option value="0">No — hide it from the form</option>
                </x-select>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="save">{{ $editingId ? 'Save Changes' : 'Add Type' }}</x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
