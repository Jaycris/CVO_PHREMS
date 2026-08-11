<?php

use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public int $perPage = 10;

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
                ->with(['department', 'position'])
                ->search($this->search)
                ->orderBy('employee_id')
                ->paginate($this->perPage),
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

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="flex flex-col gap-4 border-b border-ink-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
            <div>
                <h3 class="text-xl font-bold text-ink-950 dark:text-white">Employee Directory</h3>
                <p class="mt-1 h-4 text-sm font-semibold text-amber-600 dark:text-amber-300" x-text="selected.length ? `${selected.length} selected` : ''"></p>
            </div>

            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/5 dark:text-ink-300 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                        :disabled="selected.length !== 1"
                        @click="if (selected.length === 1) $wire.viewEmployee(selected[0])"
                        title="View selected employee"
                    >
                        <x-icon name="eye" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/5 dark:text-ink-300 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                        :disabled="selected.length !== 1"
                        @click="if (selected.length === 1) $wire.editEmployee(selected[0])"
                        title="Edit selected employee"
                    >
                        <x-icon name="pencil" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 shadow-sm transition hover:border-red-300 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300"
                        :disabled="selected.length !== 1"
                        @click="if (selected.length === 1 && confirm('Delete this employee record?')) { $wire.deleteEmployee(selected[0]); selected = []; }"
                        title="Delete selected employee"
                    >
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>
                <label class="relative block sm:w-80">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <x-input
                        wire:model.live.debounce.250ms="search"
                        placeholder="Search employees..."
                        class="h-10 pl-9"
                    />
                </label>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-ink-200 dark:divide-white/10">
                <thead class="bg-ink-50/80 dark:bg-white/[0.03]">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input
                                type="checkbox"
                                class="h-5 w-5 rounded border-ink-300 text-brand-700 focus:ring-brand-600 dark:border-white/20 dark:bg-ink-900"
                                @change="selected = $event.target.checked ? @js($employees->getCollection()->pluck('id')->values()) : []"
                                :checked="selected.length === @js($employees->count()) && @js($employees->count()) > 0"
                            >
                        </th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Employee ID</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Name</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Department</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Position</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Status</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Onboarding</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Login</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                    @forelse ($employees as $employee)
                        <tr
                            class="cursor-pointer transition hover:bg-brand-50/50 dark:hover:bg-white/[0.03]"
                            @click="selected.includes({{ $employee->id }}) ? selected = selected.filter((id) => id !== {{ $employee->id }}) : selected.push({{ $employee->id }})"
                        >
                            <td class="px-6 py-4" @click.stop>
                                <input
                                    type="checkbox"
                                    value="{{ $employee->id }}"
                                    x-model.number="selected"
                                    class="h-5 w-5 rounded border-ink-300 text-brand-700 focus:ring-brand-600 dark:border-white/20 dark:bg-ink-900"
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
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$employee->user_id ? 'brand' : 'neutral'">
                                    {{ $employee->user_id ? 'Enabled' : 'No login' }}
                                </x-badge>
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
        </div>

        @if ($employees->hasPages())
            <div class="border-t border-ink-200 px-6 py-4 dark:border-white/10">
                {{ $employees->links('components.pagination') }}
            </div>
        @endif
    </x-card>
</div>
