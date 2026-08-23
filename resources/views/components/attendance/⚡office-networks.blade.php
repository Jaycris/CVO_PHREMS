<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\OfficeNetwork;
use App\Services\Attendance\PunchLocationPolicy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The addresses that count as being in the office.
 *
 * Stops an on-site employee clocking in from home. Hybrid and remote staff are
 * never checked — being elsewhere is the arrangement.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $enforced = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public string $label = '';
    public string $ip_address = '';
    public string $note = '';
    public bool $is_active = true;

    public function mount(): void
    {
        $this->enforced = AppSetting::flag(PunchLocationPolicy::SETTING, false);
    }

    public function toggleEnforcement(): void
    {
        $this->errorMessage = null;

        // Turning it on with nothing on file would stop nobody, because the
        // policy treats an empty list as "not set up yet". Saying so is kinder
        // than letting somebody believe the office is locked down when it is
        // not.
        if (! $this->enforced && ! OfficeNetwork::active()->exists()) {
            $this->errorMessage = 'Add at least one office address first, or this would apply to nobody.';

            return;
        }

        // Refusing to switch on from outside the office is the whole safety
        // net here: otherwise the person doing it is the first one locked out,
        // and the only way back in is the database.
        if (! $this->enforced && ! OfficeNetwork::contains(request()->ip())) {
            $this->errorMessage = 'You are on ' . request()->ip()
                . ', which is not on the list. Add it first — otherwise turning this on '
                . 'locks you out of your own attendance page along with everyone else.';

            return;
        }

        $this->enforced = ! $this->enforced;

        AppSetting::put(PunchLocationPolicy::SETTING, $this->enforced ? '1' : '0');

        $this->statusMessage = $this->enforced
            ? 'On-site employees can now only clock in and out from these addresses.'
            : 'Turned off. Everybody can clock in from anywhere again.';
    }

    public function create(): void
    {
        $this->reset(['editingId', 'label', 'note']);
        $this->is_active = true;

        // Pre-filled with wherever the person setting this up is sitting,
        // which in practice is the office. Reading your own public address off
        // a router is the step people get stuck on.
        $this->ip_address = (string) request()->ip();

        $this->resetValidation();
        $this->errorMessage = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $network = OfficeNetwork::findOrFail($id);

        $this->editingId = $network->id;
        $this->label = $network->label;
        $this->ip_address = $network->ip_address;
        $this->note = (string) $network->note;
        $this->is_active = (bool) $network->is_active;

        $this->resetValidation();
        $this->errorMessage = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'label' => ['required', 'string', 'max:120'],
            'ip_address' => ['required', 'string', 'max:64', 'unique:office_networks,ip_address,' . $this->editingId],
            'note' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
        ], [], ['ip_address' => 'address']);

        $data['ip_address'] = trim($data['ip_address']);

        if (! OfficeNetwork::isValidAddress($data['ip_address'])) {
            $this->addError('ip_address', 'That is not a valid IP address or range. Use 203.0.113.5 for one address, or 203.0.113.0/24 for a range.');

            return;
        }

        OfficeNetwork::updateOrCreate(['id' => $this->editingId], $data);

        $this->showForm = false;
        $this->statusMessage = $this->editingId ? 'Address updated.' : 'Address added.';
        $this->reset(['editingId', 'label', 'note']);
    }

    public function toggleActive(int $id): void
    {
        $network = OfficeNetwork::findOrFail($id);
        $network->update(['is_active' => ! $network->is_active]);

        $this->statusMessage = $network->label . ($network->is_active ? ' is back in use.' : ' is switched off.');
    }

    public function delete(int $id): void
    {
        OfficeNetwork::findOrFail($id)->delete();

        $this->statusMessage = 'Address removed.';
    }

    public function with(): array
    {
        $ip = request()->ip();

        return [
            'yourIp' => $ip,
            'yourIpAllowed' => OfficeNetwork::contains($ip),
            'networks' => OfficeNetwork::orderBy('label')->paginate($this->perPage()),
            'onsiteCount' => Employee::whereRaw("REPLACE(REPLACE(LOWER(COALESCE(workplace_type, '')), '-', ''), ' ', '') = 'onsite'")->count(),
            'unsetCount' => Employee::whereNull('workplace_type')->orWhere('workplace_type', '')->count(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Office Networks</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Where on-site staff are allowed to clock in and out from. Hybrid and remote employees are never
                checked — being elsewhere is the arrangement.
            </p>
        </div>

        <x-button wire:click="create" @click="$wire.showForm = true" pill>
            <x-icon name="plus" class="h-4 w-4" /> Add Address
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    {{-- Your own address, front and centre. Finding it is the step people get
         stuck on, and it is also the number that decides whether the person
         setting this up is about to lock themselves out. --}}
    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">This device is on</p>
                <p class="mt-1 font-mono text-2xl font-bold text-[#0f172a] dark:text-white">{{ $yourIp ?: 'unknown' }}</p>
                <p class="mt-1 text-sm font-medium {{ $yourIpAllowed ? 'text-emerald-600 dark:text-emerald-400' : 'text-[#778599]' }}">
                    {{ $yourIpAllowed ? 'On the list — you count as being in the office.' : 'Not on the list yet.' }}
                </p>
            </div>

            <div class="text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">Checking is</p>
                <p class="mt-1 text-lg font-bold {{ $enforced ? 'text-emerald-600 dark:text-emerald-400' : 'text-[#778599]' }}">
                    {{ $enforced ? 'On' : 'Off' }}
                </p>
                <x-button wire:click="toggleEnforcement"
                          wire:confirm="{{ $enforced
                              ? 'Turn checking off? On-site staff will be able to clock in from anywhere.'
                              : 'Turn checking on? On-site staff will only be able to clock in from the addresses below.' }}"
                          variant="secondary" class="mt-2 h-9 px-3 text-xs">
                    {{ $enforced ? 'Turn off' : 'Turn on' }}
                </x-button>
            </div>
        </div>
    </x-card>

    <x-card>
        <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">Who this affects</h2>
        <p class="mt-2 text-sm font-medium text-[#778599]">
            <span class="font-bold text-[#0f172a] dark:text-white">{{ $onsiteCount }}</span>
            employee(s) are set to <span class="font-bold text-[#0f172a] dark:text-white">Onsite</span> and would be
            checked. Everyone else clocks in from anywhere.
        </p>

        @if ($unsetCount > 0)
            <p class="mt-2 text-sm font-medium text-amber-700 dark:text-amber-400">
                {{ $unsetCount }} employee(s) have no Workplace Type set, so they are treated as not on-site and are
                not checked. Set it on their profile if they should be.
            </p>
        @endif

        <p class="mt-3 text-xs font-medium text-[#778599]">
            Worth knowing: most office internet lines get a new address from time to time. If staff suddenly cannot
            clock in, this list is the first thing to check. Use a range like
            <span class="font-mono">203.0.113.0/24</span> if your provider gives you one.
        </p>
    </x-card>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Label</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Address or range</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Note</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($networks as $network)
                        <tr wire:key="net-{{ $network->id }}" class="{{ $network->is_active ? '' : 'opacity-60' }}">
                            <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">{{ $network->label }}</td>
                            <td class="px-4 py-3 font-mono font-medium text-[#0f172a] dark:text-white">
                                {{ $network->ip_address }}
                                @if ($network->matches($yourIp))
                                    <x-badge color="green" class="ml-1">you</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $network->note ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$network->is_active ? 'green' : 'neutral'">
                                    {{ $network->is_active ? 'In use' : 'Off' }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-button wire:click="edit({{ $network->id }})" @click="$wire.showForm = true"
                                              variant="secondary" class="h-9 px-3 text-xs">Edit</x-button>
                                    <x-button wire:click="toggleActive({{ $network->id }})"
                                              variant="secondary" class="h-9 px-3 text-xs">
                                        {{ $network->is_active ? 'Turn off' : 'Turn on' }}
                                    </x-button>
                                    <x-button wire:click="delete({{ $network->id }})"
                                              wire:confirm="Remove {{ $network->label }}? On-site staff on that connection will no longer be able to clock in."
                                              variant="secondary" class="h-9 px-3 text-xs">Delete</x-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center">
                            <p class="font-medium text-[#778599]">No office addresses yet.</p>
                            <p class="mt-1 text-sm text-[#778599]">
                                Add this one to start: <span class="font-mono font-semibold">{{ $yourIp }}</span>
                            </p>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($networks->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $networks->links('components.pagination', ['noun' => 'addresses']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
            {{ $editingId ? 'Edit Office Address' : 'Add Office Address' }}
        </h2>

        <div class="mt-5 space-y-4">
            <div>
                <x-label>Label</x-label>
                <x-input wire:model="label" type="text" placeholder="e.g. Main office — PLDT line" />
                @error('label') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Address or range</x-label>
                <x-input wire:model="ip_address" type="text" class="font-mono" placeholder="203.0.113.5" />
                <p class="mt-1 text-xs font-medium text-[#778599]">
                    One address, or a range like <span class="font-mono">203.0.113.0/24</span>.
                    This device is on <span class="font-mono font-semibold">{{ $yourIp }}</span>.
                </p>
                @error('ip_address') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="note" type="text" placeholder="Who to call if this line changes" />
                @error('note') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>In use?</x-label>
                <x-select wire:model="is_active">
                    <option value="1">Yes</option>
                    <option value="0">No — keep the record but do not allow it</option>
                </x-select>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="save">{{ $editingId ? 'Save Changes' : 'Add Address' }}</x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
