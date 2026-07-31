<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $employee_id = '';
    public string $phone_name = '';
    public string $company_email = '';
    public ?int $position_id = null;
    public ?int $department_id = null;
    public string $hire_date = '';
    public string $basic_salary = '';
    public string $allowance = '0';
    public string $commission_scheme = '';
    public string $quota = '';
    public string $employment_status = 'Probationary';
    public ?int $reports_to_id = null;

    public function mount(): void
    {
        $next = Employee::count() + 1;
        $this->employee_id = 'EMP-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function isSalesDepartment(): bool
    {
        if (! $this->department_id) {
            return false;
        }

        return Department::find($this->department_id)?->name === 'Sales';
    }

    public function save(): void
    {
        $rules = [
            'employee_id' => ['required', 'string', 'max:50', 'unique:employees,employee_id'],
            'phone_name' => ['nullable', 'string', 'max:255'],
            'company_email' => ['required', 'email', 'unique:employees,company_email'],
            'position_id' => ['required', 'exists:positions,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'hire_date' => ['required', 'date'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'allowance' => ['nullable', 'numeric', 'min:0'],
            'employment_status' => ['required', 'in:Probationary,Training,Regular'],
            'reports_to_id' => ['nullable', 'exists:employees,id'],
        ];

        if ($this->isSalesDepartment()) {
            $rules['commission_scheme'] = ['required', 'in:Tier 1,Tier 2,Tier 3'];
            $rules['quota'] = ['required', 'numeric', 'min:0'];
        }

        $data = $this->validate($rules);

        if (! $this->isSalesDepartment()) {
            $data['commission_scheme'] = null;
            $data['quota'] = null;
        }

        $employee = Employee::create($data);

        $this->redirect(route('employees.show', $employee), navigate: true);
    }

    public function with(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            'potentialManagers' => Employee::orderBy('employee_id')->get(),
        ];
    }
};
?>

<div class="max-w-4xl space-y-2">
    <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Add Employee</h1>
    <p class="mb-4 text-sm font-medium text-[#778599] dark:text-neutral-400">These fields are set by HR. The employee will fill in their personal details via a secure onboarding link.</p>

    <x-card>
        <form wire:submit="save" class="space-y-5">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <x-label>Employee ID</x-label>
                    <x-input wire:model="employee_id" type="text" />
                    @error('employee_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Phone Name <span class="font-medium text-[#778599]">(alias for calls)</span></x-label>
                    <x-input wire:model="phone_name" type="text" />
                </div>
                <div>
                    <x-label>Company Email</x-label>
                    <x-input wire:model="company_email" type="email" />
                    @error('company_email') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <x-label>Department</x-label>
                    <x-select wire:model.live="department_id">
                        <option value="">Select department</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </x-select>
                    @error('department_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Job Title / Position</x-label>
                    <x-select wire:model="position_id">
                        <option value="">Select position</option>
                        @foreach ($positions as $position)
                            <option value="{{ $position->id }}">{{ $position->title }}</option>
                        @endforeach
                    </x-select>
                    @error('position_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Hire Date</x-label>
                    <x-input wire:model="hire_date" type="date" />
                    @error('hire_date') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <x-label>Employment Status</x-label>
                    <x-select wire:model="employment_status">
                        <option value="Probationary">Probationary</option>
                        <option value="Training">Training</option>
                        <option value="Regular">Regular</option>
                    </x-select>
                </div>
                <div>
                    <x-label>Basic Salary (monthly)</x-label>
                    <x-input wire:model="basic_salary" type="number" step="0.01" />
                    @error('basic_salary') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Allowance</x-label>
                    <x-input wire:model="allowance" type="number" step="0.01" />
                </div>
            </div>

            @if ($this->isSalesDepartment())
                <div class="grid grid-cols-1 gap-4 rounded-lg bg-brand-50 p-4 sm:grid-cols-2 dark:bg-brand-900/20">
                    <div>
                        <x-label>Commission Scheme (Sales only)</x-label>
                        <x-select wire:model="commission_scheme">
                            <option value="">Select tier</option>
                            <option value="Tier 1">Tier 1</option>
                            <option value="Tier 2">Tier 2</option>
                            <option value="Tier 3">Tier 3</option>
                        </x-select>
                        @error('commission_scheme') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Quota (Sales only)</x-label>
                        <x-input wire:model="quota" type="number" step="0.01" />
                        @error('quota') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div class="sm:w-1/3">
                <x-label>Reports To</x-label>
                <x-select wire:model="reports_to_id">
                    <option value="">None</option>
                    @foreach ($potentialManagers as $manager)
                        <option value="{{ $manager->id }}">{{ $manager->employee_id }} — {{ $manager->fullName() ?: $manager->company_email }}</option>
                    @endforeach
                </x-select>
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Save Employee</x-button>
                <x-button as="a" variant="secondary" href="{{ route('employees.index') }}" wire:navigate>Cancel</x-button>
            </div>
        </form>
    </x-card>
</div>