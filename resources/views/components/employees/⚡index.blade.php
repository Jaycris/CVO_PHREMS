<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public string $search = '';

    public ?string $statusMessage = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function viewEmployee(int $id): void
    {
        $this->redirect(route('employees.show', Employee::findOrFail($id)), navigate: true);
    }

    public function editEmployee(int $id): void
    {
        $this->redirect(route('employees.edit', Employee::findOrFail($id)), navigate: true);
    }

    public function deleteEmployee(int $id): void
    {
        try {
            Employee::findOrFail($id)->delete();
            $this->statusMessage = 'Employee record deleted.';
            $this->resetPage();
        } catch (\Throwable) {
            $this->statusMessage = 'This employee cannot be deleted because it is already connected to HR records.';
        }
    }

    public function with(): array
    {
        return [
            'employees' => Employee::query()
                // user is loaded because the Login column reads is_active from
                // it; without this the list runs a query per row.
                ->with(['department', 'position', 'user'])
                ->search($this->search)
                ->orderBy('employee_id')
                ->paginate($this->perPage()),
            'totalEmployees' => Employee::count(),
            'regularEmployees' => Employee::where('employment_status', 'Regular')->count(),
            'pendingOnboarding' => Employee::whereNull('onboarding_completed_at')->count(),
        ];
    }
};
?>

<div class="space-y-7" x-data="{ selected: [] }">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Employees</h2>
            <p class="mt-1 text-base font-medium text-ink-600 dark:text-ink-300">Manage employee records, onboarding, and HR access.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-button as="a" variant="secondary" href="{{ route('reports.employees.export') }}" class="h-10 px-4">
                Export Master List
            </x-button>
            <x-button as="a" href="{{ route('employees.create') }}" class="h-10 px-4">
                <x-icon name="plus" class="h-4 w-4" />
                Add Employee
            </x-button>
        </div>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm font-semibold text-brand-800 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-300">
            {{ $statusMessage }}
        </div>
    @endif

    <div class="flex flex-wrap gap-4">
        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Total Employees</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $totalEmployees }}</p>
            <p class="mt-1 text-sm font-semibold text-brand-700 dark:text-brand-300">Active roster</p>
        </div>

        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Regular</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $regularEmployees }}</p>
            <p class="mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300">Confirmed staff</p>
        </div>

        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Pending Onboarding</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $pendingOnboarding }}</p>
            <p class="mt-1 text-sm font-semibold text-amber-600 dark:text-amber-300">Profile setup</p>
        </div>
    </div>

    <x-card :padding="false" class="directory-panel">
        <div class="directory-toolbar">
            <div>
                <h3 class="directory-title">Employee Directory</h3>
                <p class="directory-selection" x-text="selected.length ? `${selected.length} selected` : ''"></p>
            </div>

            <div class="directory-toolbar-actions">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/5 dark:text-ink-300 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                        :disabled="selected.length !== 1"
                        @click="if (selected.length === 1) $wire.viewEmployee(selected[0])"
                        title="View selected employee"
                    >
                        <x-icon name="eye" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/5 dark:text-ink-300 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                        :disabled="selected.length !== 1"
                        @click="if (selected.length === 1) $wire.editEmployee(selected[0])"
                        title="Edit selected employee"
                    >
                        <x-icon name="pencil" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 shadow-sm transition hover:border-red-300 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300"
                        :disabled="selected.length !== 1"
                        @click="if (selected.length === 1 && confirm('Delete this employee record?')) { $wire.deleteEmployee(selected[0]); selected = []; }"
                        title="Delete selected employee"
                    >
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>
                <label class="directory-search">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <x-input
                        wire:model.live.debounce.250ms="search"
                        placeholder="Search employees..."
                        class="h-10 pl-9"
                    />
                </label>
            </div>
        </div>

        <x-directory-scroll>
            <table class="directory-table">
                <thead class="directory-table-head">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input
                                type="checkbox"
                                class="directory-checkbox"
                                @change="selected = $event.target.checked ? @js($employees->getCollection()->pluck('id')->values()) : []"
                                :checked="selected.length === @js($employees->count()) && @js($employees->count()) > 0"
                            >
                        </th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Employee ID</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Name</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Department</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Position</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Status</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Employment</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Onboarding</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Login</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($employees as $employee)
                        <tr
                            wire:key="employee-{{ $employee->id }}"
                            class="directory-row cursor-pointer"
                            x-bind:class="selected.includes({{ $employee->id }}) ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''"
                            wire:click="viewEmployee({{ $employee->id }})"
                        >
                            <td class="px-6 py-4" @click.stop>
                                <input
                                    type="checkbox"
                                    value="{{ $employee->id }}"
                                    x-model.number="selected"
                                    class="directory-checkbox"
                                >
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-bold text-ink-800 dark:text-ink-100">{{ $employee->employee_id }}</td>
                            <td class="min-w-56 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <x-avatar :employee="$employee" size="md" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-brand-800 dark:text-brand-300">{{ $employee->fullName() ?: $employee->phone_name }}</p>
                                        <p class="mt-1 max-w-56 truncate text-xs font-medium text-ink-500 dark:text-ink-400">{{ $employee->company_email ?: 'No company email' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-ink-700 dark:text-ink-200">{{ $employee->department?->name ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-ink-700 dark:text-ink-200">{{ $employee->position?->title ?? '-' }}</td>
                            {{-- Two different facts, and they were being shown as
                                 one. Regular says what kind of employee somebody
                                 is; it stays Regular on the way out, so the
                                 directory was showing a green badge for people
                                 who left months ago. --}}
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$employee->statusColor()">
                                    {{ $employee->statusLabel() }}
                                </x-badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$employee->employment_status === 'Regular' ? 'green' : 'amber'">
                                    {{ $employee->employment_status }}
                                </x-badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$employee->onboarding_completed_at ? 'green' : 'amber'">
                                    {{ $employee->onboarding_completed_at ? 'Complete' : 'Pending' }}
                                </x-badge>
                            </td>
                            {{-- Three states, not two. Having an account and
                                 being able to sign in are different facts, and
                                 showing "Enabled" for a disabled account is the
                                 sort of thing HR only finds out about when the
                                 person cannot log in. --}}
                            <td class="whitespace-nowrap px-4 py-4">
                                @if (! $employee->user_id)
                                    <x-badge color="neutral">No login</x-badge>
                                @elseif ($employee->user?->is_active)
                                    <x-badge color="brand">Enabled</x-badge>
                                @else
                                    <x-badge color="red">Disabled</x-badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="people-group" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No employees found</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Add the first employee record or adjust your search.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-directory-scroll>

        @if ($employees->hasPages())
            <div class="directory-pagination" @click="selected = []">
                {{ $employees->links('components.pagination', ['noun' => 'employees']) }}
            </div>
        @endif
    </x-card>
</div>
