<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CommissionScheme;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The commission plans an agent can be put on.
 *
 * This list belongs to the CRM, not to the HRIS. The CRM works out every
 * figure; all this does is record which plan somebody is on. Keeping the names
 * identical is what stops a slip describing an agent's pay by a name nobody
 * else in the business uses.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public string $name = '';
    public string $crm_key = '';
    public string $description = '';
    public bool $is_active = true;
    public string $sort_order = '0';

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'crm_key', 'description']);
        $this->is_active = true;
        $this->sort_order = (string) (CommissionScheme::max('sort_order') + 1);
        $this->resetValidation();
        $this->errorMessage = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $scheme = CommissionScheme::findOrFail($id);

        $this->editingId = $scheme->id;
        $this->name = $scheme->name;
        $this->crm_key = (string) $scheme->crm_key;
        $this->description = (string) $scheme->description;
        $this->is_active = (bool) $scheme->is_active;
        $this->sort_order = (string) $scheme->sort_order;

        $this->resetValidation();
        $this->errorMessage = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120', 'unique:commission_schemes,name,' . $this->editingId],
            'crm_key' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ], [], ['crm_key' => 'CRM name']);

        $previousName = $this->editingId ? CommissionScheme::find($this->editingId)?->name : null;

        $scheme = CommissionScheme::updateOrCreate(['id' => $this->editingId], $data);

        // Employees carry the scheme by name, so a rename has to follow through
        // or everyone on it silently falls off their plan.
        if ($previousName && $previousName !== $scheme->name) {
            $moved = \App\Models\Employee::where('commission_scheme', $previousName)
                ->update(['commission_scheme' => $scheme->name]);

            $this->statusMessage = "Scheme renamed. {$moved} employee record(s) moved with it.";
        } else {
            $this->statusMessage = $this->editingId ? 'Scheme updated.' : 'Scheme added.';
        }

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'crm_key', 'description']);
    }

    public function toggleActive(int $id): void
    {
        $scheme = CommissionScheme::findOrFail($id);
        $scheme->update(['is_active' => ! $scheme->is_active]);

        $this->statusMessage = $scheme->name . ($scheme->is_active
            ? ' can be assigned again.'
            : ' is hidden from the employee form. Anyone already on it stays on it.');
    }

    public function delete(int $id): void
    {
        $this->errorMessage = null;
        $scheme = CommissionScheme::withCount('employees')->findOrFail($id);

        if ($scheme->employees_count > 0) {
            $this->errorMessage = $scheme->name . ' has ' . $scheme->employees_count
                . ' employee(s) on it, so it cannot be deleted. Turn it off instead.';

            return;
        }

        $scheme->delete();
        $this->statusMessage = 'Scheme removed.';
    }

    public function with(): array
    {
        return [
            'schemes' => CommissionScheme::withCount('employees')->ordered()->paginate($this->perPage()),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Commission Schemes</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                The plans an agent can be put on, in the Payroll Details tab of their profile.
            </p>
        </div>

        <x-button wire:click="create" @click="$wire.showForm = true" pill>
            <x-icon name="plus" class="h-4 w-4" /> Add Scheme
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    <x-card>
        <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">These names have to match the CRM</h2>
        <p class="mt-2 text-sm font-medium text-[#778599]">
            The CRM works out every commission figure. PHREMS only records which plan someone is on and prints
            what the CRM sends back — so if the two lists drift apart, a slip ends up describing an agent's pay
            by a name nobody else in the business uses, and the first person to notice is the agent.
        </p>
        <p class="mt-2 text-sm font-medium text-[#778599]">
            In the CRM this is stored as the <span class="font-bold text-[#0f172a] dark:text-white">service profile</span>,
            even though its screen now says Commission Scheme. Where the two spellings differ, put the CRM's in
            <span class="font-bold text-[#0f172a] dark:text-white">Name in the CRM</span> and keep the plain one here
            for your own staff.
        </p>
    </x-card>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Scheme</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Name in the CRM</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Description</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Agents</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($schemes as $scheme)
                        <tr wire:key="cs-{{ $scheme->id }}" class="{{ $scheme->is_active ? '' : 'opacity-60' }}">
                            <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">{{ $scheme->name }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599]">
                                {{ $scheme->crm_key ?: 'Same as above' }}
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $scheme->description ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $scheme->employees_count }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$scheme->is_active ? 'green' : 'neutral'">
                                    {{ $scheme->is_active ? 'In use' : 'Off' }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-button wire:click="edit({{ $scheme->id }})" @click="$wire.showForm = true"
                                              variant="secondary" class="h-9 px-3 text-xs">Edit</x-button>
                                    <x-button wire:click="toggleActive({{ $scheme->id }})"
                                              variant="secondary" class="h-9 px-3 text-xs">
                                        {{ $scheme->is_active ? 'Turn off' : 'Turn on' }}
                                    </x-button>
                                    @if ($scheme->employees_count === 0)
                                        <x-button wire:click="delete({{ $scheme->id }})"
                                                  wire:confirm="Remove this scheme?"
                                                  variant="secondary" class="h-9 px-3 text-xs">Delete</x-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center">
                            <p class="font-medium text-[#778599]">No commission schemes yet.</p>
                            <p class="mt-1 text-sm text-[#778599]">
                                Add the ones your CRM uses, spelled the same way.
                            </p>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($schemes->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $schemes->links('components.pagination', ['noun' => 'schemes']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
            {{ $editingId ? 'Edit Commission Scheme' : 'Add Commission Scheme' }}
        </h2>

        <div class="mt-5 space-y-4">
            <div>
                <x-label>Scheme name</x-label>
                <x-input wire:model="name" type="text" placeholder="Spell it exactly as the CRM does" />
                @error('name') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Name in the CRM <span class="font-medium text-[#778599]">(only if different)</span></x-label>
                <x-input wire:model="crm_key" type="text" placeholder="The CRM's service profile name, if it differs" />
                @error('crm_key') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Description <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="description" type="text" placeholder="What this plan is for" />
                @error('description') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Order in the list</x-label>
                    <x-input wire:model="sort_order" type="number" min="0" />
                    @error('sort_order') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Can be assigned?</x-label>
                    <x-select wire:model="is_active">
                        <option value="1">Yes</option>
                        <option value="0">No — hide it from the employee form</option>
                    </x-select>
                </div>
            </div>

            @if ($editingId)
                <div class="rounded-lg bg-[#f8fafc] p-3 text-xs font-medium text-[#778599] dark:bg-neutral-800/50">
                    Renaming a scheme moves everyone on it to the new name, so nobody silently falls off their plan.
                </div>
            @endif
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="save">{{ $editingId ? 'Save Changes' : 'Add Scheme' }}</x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
