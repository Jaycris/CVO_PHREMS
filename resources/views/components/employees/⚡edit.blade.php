<?php

use App\Models\CommissionScheme;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Employee $employee;

    // System-assigned and never editable, same as on the create form.
    #[Locked]
    public string $employee_id = '';

    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $gender = '';
    public string $phone_name = '';
    public string $workplace_type = '';
    public string $employment_type = '';
    public string $company_email = '';
    public string $personal_email = '';
    public ?int $position_id = null;
    public ?int $department_id = null;
    public string $hire_date = '';
    public string $basic_salary = '';
    public string $allowance = '0';
    public string $commission_scheme = '';
    public string $commission_frequency = 'none';
    public string $quota = '';
    public string $employment_status = 'Probationary';
    public ?int $reports_to_id = null;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        $this->employee_id = $employee->employee_id;
        $this->first_name = (string) $employee->first_name;
        $this->middle_name = (string) $employee->middle_name;
        $this->last_name = (string) $employee->last_name;
        $this->gender = (string) $employee->gender;
        $this->phone_name = (string) $employee->phone_name;
        $this->workplace_type = (string) $employee->workplace_type;
        $this->employment_type = (string) $employee->employment_type;
        $this->company_email = (string) $employee->company_email;
        $this->personal_email = (string) $employee->personal_email;
        $this->position_id = $employee->position_id;
        $this->department_id = $employee->department_id;
        $this->hire_date = $employee->hire_date?->format('Y-m-d') ?? '';
        $this->basic_salary = (string) $employee->basic_salary;
        $this->allowance = (string) $employee->allowance;
        $this->commission_scheme = (string) $employee->commission_scheme;
        $this->commission_frequency = (string) ($employee->commission_frequency ?: 'none');
        $this->quota = $employee->quota !== null ? (string) $employee->quota : '';
        $this->employment_status = $employee->employment_status;
        $this->reports_to_id = $employee->reports_to_id;
    }

    public function isSalesDepartment(): bool
    {
        return $this->department_id
            ? Department::find($this->department_id)?->name === 'Sales'
            : false;
    }

    public function save(): void
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // Nullable here even though onboarding requires it: HR may not know
            // at the point of creating the record, and the employee fills it in.
            'gender' => ['nullable', 'in:Male,Female'],
            'phone_name' => ['nullable', 'string', 'max:255'],
            'workplace_type' => ['nullable', 'in:Onsite,Hybrid,Remote'],
            'employment_type' => ['nullable', 'in:Full-time,Part-time'],
            // Unique across everyone except this employee, otherwise saving an
            // unchanged form would collide with the record's own address.
            'company_email' => ['required', 'email', 'unique:employees,company_email,' . $this->employee->id],
            'personal_email' => ['required', 'email'],
            'position_id' => ['required', 'exists:positions,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'hire_date' => ['required', 'date'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'allowance' => ['nullable', 'numeric', 'min:0'],
            'employment_status' => ['required', 'in:Probationary,Regular,Contract,Training'],
            'reports_to_id' => ['nullable', 'exists:employees,id'],
            // How often their commission is worked out. Drives which
            // commission run pre-selects them.
            'commission_frequency' => ['required', 'in:none,monthly,biweekly'],
        ];

        if ($this->isSalesDepartment()) {
            $rules['commission_scheme'] = ['required', Rule::in(array_keys(CommissionScheme::options()))];
            $rules['quota'] = ['required', 'numeric', 'min:0'];
        }

        $data = $this->validate($rules);

        /*
         * An untouched dropdown submits an empty string, and gender is an ENUM
         * in MySQL — writing '' to it fails outright with "Data truncated"
         * rather than storing a blank. Empty means not set, so store null.
         */
        foreach (['gender', 'workplace_type', 'employment_type'] as $optional) {
            if (($data[$optional] ?? null) === '') {
                $data[$optional] = null;
            }
        }

        if (! $this->isSalesDepartment()) {
            $data['commission_scheme'] = null;
            $data['quota'] = null;
        }

        // An employee reporting to themselves would loop the leave-approval chain.
        if ((int) $this->reports_to_id === $this->employee->id) {
            $this->addError('reports_to_id', 'An employee cannot report to themselves.');

            return;
        }

        $this->employee->update($data);

        $this->redirect(route('employees.show', $this->employee), navigate: true);
    }

    public function with(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            // Exclude self so the Reports To list cannot create a self-reference.
            'potentialManagers' => Employee::supervisors()
                ->where('id', '!=', $this->employee->id)
                ->with('position')
                ->orderBy('employee_id')
                ->get(),
        ];
    }
};
?>

<div class="space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Edit Employee</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">Update the HR-managed profile for {{ $employee->fullName() ?: $employee->employee_id }}.</p>
        </div>

        <x-button as="a" variant="secondary" href="{{ route('employees.show', $employee) }}" wire:navigate class="h-10 px-4">
            Back to Profile
        </x-button>
    </div>

    <form wire:submit="save" class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <section class="professional-panel overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Employee Profile</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Basic Information</h2>
                </div>

                <div class="space-y-5 p-6">
                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-3">
                    <div class="min-w-0">
                        <x-label>Employee ID</x-label>
                        <x-input type="text" value="{{ $employee_id }}" disabled readonly class="cursor-not-allowed bg-ink-50 font-semibold opacity-100 dark:bg-white/5" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">Cannot be changed.</p>
                    </div>
                    <div class="min-w-0">
                        <x-label>First Name</x-label>
                        <x-input wire:model="first_name" type="text" />
                        @error('first_name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Middle Name <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="middle_name" type="text" />
                    </div>
                    </div>

                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-3">
                    <div class="min-w-0">
                        <x-label>Last Name</x-label>
                        <x-input wire:model="last_name" type="text" />
                        @error('last_name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Gender <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-select wire:model="gender">
                            <option value="">Not set</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </x-select>
                        @error('gender') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Phone Name <span class="font-medium text-[#778599]">(name used for CRM work)</span></x-label>
                        <x-input wire:model="phone_name" type="text" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">The CRM splits this into first and last name when creating their user.</p>
                    </div>
                    </div>

                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-2">
                    <div class="min-w-0">
                        <x-label>Company Email</x-label>
                        <x-input wire:model="company_email" type="email" />
                        @error('company_email') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Personal Email</x-label>
                        <x-input wire:model="personal_email" type="email" />
                        @error('personal_email') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>
                </div>
            </section>

            <section class="professional-panel overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Employment</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Assignment And Compensation</h2>
                </div>

                <div class="space-y-5 p-6">
                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-3">
                    <div class="min-w-0">
                        <x-label>Department</x-label>
                        <x-select wire:model.live="department_id">
                            <option value="">Select department</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </x-select>
                        @error('department_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Workplace Type <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-select wire:model="workplace_type">
                            <option value="">Not set</option>
                            <option value="Onsite">Onsite</option>
                            <option value="Hybrid">Hybrid</option>
                            <option value="Remote">Remote</option>
                        </x-select>
                        <p class="mt-1 text-xs font-medium text-[#778599]">Where they work. Sent to the CRM when creating their user.</p>
                        @error('workplace_type') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Job Title / Position</x-label>
                        <x-select wire:model="position_id">
                            <option value="">Select position</option>
                            @foreach ($positions as $position)
                                <option value="{{ $position->id }}">{{ $position->title }}</option>
                            @endforeach
                        </x-select>
                        @error('position_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>

                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-3">
                    <div class="min-w-0">
                        <x-label>Hire Date</x-label>
                        <x-input wire:model="hire_date" type="date" />
                        @error('hire_date') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Employment Status</x-label>
                        <x-select wire:model="employment_status">
                            <option value="Probationary">Probationary</option>
                            <option value="Regular">Regular</option>
                            <option value="Contract">Contract</option>
                            <option value="Training">Training</option>
                        </x-select>
                        <p class="mt-1 text-xs font-medium text-[#778599]">Their standing in the company.</p>
                        @error('employment_status') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Employment Type <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-select wire:model="employment_type">
                            <option value="">Not set</option>
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                        </x-select>
                        <p class="mt-1 text-xs font-medium text-[#778599]">How many hours they are engaged for.</p>
                        @error('employment_type') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>

                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-3">
                    <div class="min-w-0">
                        <x-label>Basic Salary (monthly)</x-label>
                        <x-input wire:model="basic_salary" type="number" step="0.01" />
                        @error('basic_salary') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Allowance</x-label>
                        <x-input wire:model="allowance" type="number" step="0.01" />
                    </div>
                    <div class="min-w-0">
                        <x-label>Reports To</x-label>
                        <x-select wire:model="reports_to_id">
                            <option value="">None</option>
                            @foreach ($potentialManagers as $manager)
                                <option value="{{ $manager->id }}">{{ $manager->employee_id }} - {{ $manager->fullName() ?: $manager->company_email }} ({{ $manager->position?->title }})</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs font-medium text-[#778599]">
                            @if ($potentialManagers->isEmpty())
                                No supervisory positions exist yet. Mark a position as supervisory under Positions to populate this list.
                            @else
                                Only employees in supervisory positions are listed. Leave approvals route here.
                            @endif
                        </p>
                        @error('reports_to_id') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>
                </div>
            </section>

            @if ($this->isSalesDepartment())
                <section class="professional-panel overflow-hidden rounded-2xl border-brand-200 bg-brand-50/60 dark:border-brand-500/20 dark:bg-brand-500/10">
                    <div class="border-b border-brand-100 px-6 py-5 dark:border-brand-500/20">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Sales Compensation</p>
                        <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Commission Setup</h2>
                    </div>
                    <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                        <div>
                            <x-label>Commission Scheme</x-label>
                            <x-select wire:model="commission_scheme">
                                <option value="">Select scheme</option>
                                @foreach (\App\Models\CommissionScheme::options() as $schemeName)
                                    <option value="{{ $schemeName }}">{{ $schemeName }}</option>
                                @endforeach
                            </x-select>
                            @error('commission_scheme') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-label>Commission Frequency</x-label>
                            <x-select wire:model="commission_frequency">
                                <option value="none">Not on commission</option>
                                <option value="monthly">Monthly</option>
                                <option value="biweekly">Bi-weekly</option>
                            </x-select>
                            <p class="mt-1 text-xs font-medium text-[#778599]">Pre-selects them on commission runs of that kind. They can still be added to any run by hand.</p>
                            @error('commission_frequency') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-label>Quota</x-label>
                            <x-input wire:model="quota" type="number" step="0.01" />
                            @error('quota') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-4">
            <div class="professional-panel rounded-2xl p-5">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-400">Profile ID</p>
                <p class="mt-2 text-2xl font-bold text-ink-950 dark:text-white">{{ $employee_id }}</p>
                <p class="mt-1 text-xs font-semibold text-brand-700 dark:text-brand-300">Cannot be changed</p>
            </div>

            <div class="professional-panel rounded-2xl p-5">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Note</p>
                <p class="mt-2 text-sm font-medium leading-6 text-ink-600 dark:text-ink-300">
                    Personal details the employee filled in during onboarding are edited by them, not here.
                    Payroll settings and leave entitlement live on the profile page.
                </p>
            </div>

            <div class="professional-panel sticky top-24 rounded-2xl p-5">
                <div class="flex flex-col gap-3">
                    <x-button type="submit" class="w-full">Save Changes</x-button>
                    <x-button as="a" variant="secondary" href="{{ route('employees.show', $employee) }}" wire:navigate class="w-full">Cancel</x-button>
                </div>
            </div>
        </aside>
    </form>
</div>
