<?php

use App\Models\CommissionScheme;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\Commission\CommissionProfileMirror;
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
    public string $birthdate = '';
    public string $phone_name = '';

    /*
     * What the employee filled in at onboarding.
     *
     * They cannot change any of it afterwards — the onboarding form refuses a
     * second submission — so this screen is the only place a wrong birthdate or
     * a new address can be put right.
     *
     * Until they have submitted that form, though, none of it is editable here
     * either. These are the employee's own answers, and HR filling them in from
     * memory ahead of time produces a record that looks complete and answered
     * while nobody has actually been asked. Send them the onboarding link
     * instead; corrections come afterwards.
     */
    public string $civil_status = '';
    public string $address = '';
    public string $personal_contact_number = '';
    public string $emergency_contact_name = '';
    public string $emergency_contact_number = '';
    public string $tin_number = '';
    public string $sss_number = '';
    public string $philhealth_number = '';
    public string $pagibig_number = '';
    public string $workplace_type = '';
    public bool $tracks_attendance = true;
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

    public function mount(Employee $employee, CommissionProfileMirror $mirror): void
    {
        // Asked before the fields are filled in, for the same reason the
        // profile does it: the CRM owns the commission setup. Without this the
        // form shows whatever was last written down, and saving would push that
        // stale answer back over the CRM's — which is how somebody ends up
        // switched off in PHREMS while the CRM says they earn commission.
        $mirror->refresh($employee);
        $employee->refresh();

        $this->employee = $employee;
        $this->employee_id = $employee->employee_id;
        $this->first_name = (string) $employee->first_name;
        $this->middle_name = (string) $employee->middle_name;
        $this->last_name = (string) $employee->last_name;
        $this->gender = (string) $employee->gender;
        $this->birthdate = $employee->birthdate?->format('Y-m-d') ?? '';
        $this->phone_name = (string) $employee->phone_name;
        $this->civil_status = (string) $employee->civil_status;
        $this->address = (string) $employee->address;
        $this->personal_contact_number = (string) $employee->personal_contact_number;
        $this->emergency_contact_name = (string) $employee->emergency_contact_name;
        $this->emergency_contact_number = (string) $employee->emergency_contact_number;
        $this->tin_number = (string) $employee->tin_number;
        $this->sss_number = (string) $employee->sss_number;
        $this->philhealth_number = (string) $employee->philhealth_number;
        $this->pagibig_number = (string) $employee->pagibig_number;
        $this->workplace_type = (string) $employee->workplace_type;
        $this->tracks_attendance = (bool) $employee->tracks_attendance;
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

    /**
     * Whether this person earns commission.
     *
     * Per person, not per department. Somebody in Admin may well sell,
     * and somebody in Sales may not — this used to check that the
     * department was literally named "Sales", which hid the whole
     * section from the first and would have stripped every agent's setup
     * the day that department was renamed.
     */
    public function earnsCommission(): bool
    {
        return $this->commission_frequency !== 'none';
    }

    /**
     * The employee's own onboarding answers, editable only once they exist.
     *
     * @return list<string>
     */
    public function onboardingFields(): array
    {
        return [
            'birthdate', 'civil_status', 'address', 'personal_contact_number',
            'emergency_contact_name', 'emergency_contact_number',
            'tin_number', 'sss_number', 'philhealth_number', 'pagibig_number',
        ];
    }

    public function onboardingDone(): bool
    {
        return $this->employee->onboarding_completed_at !== null;
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
            /*
             * Nullable for the same reason as gender — HR often creates the
             * record before the employee has filled anything in.
             *
             * before:today rules out a birthdate in the future, which is always
             * a typo. There is no lower bound: a wrong century is obvious on
             * screen, and a rule guessing at a maximum age would eventually
             * refuse somebody real.
             */
            'birthdate' => ['nullable', 'date', 'before:today'],
            'phone_name' => ['nullable', 'string', 'max:255'],
            /*
             * Onboarding requires most of these; here they are all optional.
             * HR often has a record open before the employee has filled
             * anything in, and a form that refuses to save until nine unknown
             * fields are supplied is a form nobody can use.
             */
            'civil_status' => ['nullable', 'in:Single,Married,Widowed,Separated,Divorced'],
            'address' => ['nullable', 'string'],
            'personal_contact_number' => ['nullable', 'string', 'max:50'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:50'],
            'tin_number' => ['nullable', 'string', 'max:50'],
            'sss_number' => ['nullable', 'string', 'max:50'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'pagibig_number' => ['nullable', 'string', 'max:50'],
            'tracks_attendance' => ['boolean'],
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

        if ($this->earnsCommission()) {
            $rules['commission_scheme'] = ['required', Rule::in(array_keys(CommissionScheme::options()))];
            $rules['quota'] = ['required', 'numeric', 'min:0'];
        }

        $data = $this->validate($rules);

        /*
         * An untouched dropdown submits an empty string, and gender is an ENUM
         * in MySQL — writing '' to it fails outright with "Data truncated"
         * rather than storing a blank. Empty means not set, so store null.
         *
         * An empty date field is the same trap for a different reason: '' in a
         * DATE column is not a blank, it is an invalid date.
         */
        foreach (['gender', 'birthdate', 'civil_status', 'workplace_type', 'employment_type'] as $optional) {
            if (($data[$optional] ?? null) === '') {
                $data[$optional] = null;
            }
        }

        /*
         * Dropped rather than merely hidden. The inputs are not rendered before
         * onboarding is submitted, but a crafted request could still post them,
         * and this is where somebody's TIN would land.
         */
        if (! $this->onboardingDone()) {
            $data = collect($data)->except($this->onboardingFields())->all();
        }

        if (! $this->earnsCommission()) {
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
                        <x-label>Phone Name <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="phone_name" type="text" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">The name they use for CRM work, if it differs from their own. The CRM splits it into a first and last name. Leave blank and their real name is used.</p>
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
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Personal</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Details And Government Numbers</h2>
                    <p class="mt-1 text-sm font-medium text-ink-600 dark:text-ink-300">
                        Filled in by the employee at onboarding. They cannot change any of it afterwards, so this is where a correction is made.
                    </p>
                </div>

                @if (! $this->onboardingDone())
                    {{--
                        Nothing to correct yet. These are the employee's own
                        answers, and filling them in for them would leave a
                        record that reads as answered when nobody was asked.
                    --}}
                    <div class="p-6">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-400/20 dark:bg-amber-400/10">
                            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">Waiting on {{ $first_name ?: 'the employee' }}</p>
                            <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-300">
                                These details are filled in by the employee on their onboarding form, which has not been submitted yet.
                                They become editable here once it is, so a wrong birthdate or a change of address can be corrected.
                            </p>
                            <a href="{{ route('employees.show', $employee) }}" wire:navigate
                               class="mt-3 inline-block text-sm font-bold text-amber-900 underline dark:text-amber-200">
                                Send the onboarding link
                            </a>
                        </div>
                    </div>
                @else
                <div class="space-y-5 p-6">
                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-2">
                    <div class="min-w-0">
                        <x-label>Birthdate <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="birthdate" type="date" max="{{ now()->toDateString() }}" />
                        @error('birthdate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Civil Status <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-select wire:model="civil_status">
                            <option value="">Not set</option>
                            @foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced'] as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </x-select>
                        @error('civil_status') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Personal Contact Number <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="personal_contact_number" type="text" />
                        @error('personal_contact_number') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>

                    <div class="min-w-0">
                        <x-label>Address <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="address" type="text" />
                        @error('address') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-2">
                    <div class="min-w-0">
                        <x-label>Emergency Contact Name <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="emergency_contact_name" type="text" />
                        @error('emergency_contact_name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-0">
                        <x-label>Emergency Contact Number <span class="font-medium text-[#778599]">(optional)</span></x-label>
                        <x-input wire:model="emergency_contact_number" type="text" />
                        @error('emergency_contact_number') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>

                    <div class="grid grid-cols-1 items-start gap-5 sm:grid-cols-4">
                    @foreach ([
                        'tin_number' => 'TIN',
                        'sss_number' => 'SSS',
                        'philhealth_number' => 'PhilHealth',
                        'pagibig_number' => 'Pag-IBIG',
                    ] as $field => $label)
                        <div class="min-w-0">
                            <x-label>{{ $label }} <span class="font-medium text-[#778599]">(optional)</span></x-label>
                            <x-input wire:model="{{ $field }}" type="text" />
                            @error($field) <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    </div>

                    {{--
                        Deliberately not here: the payroll bank account. An
                        employee changes that themselves from My Profile and it
                        goes to the CEO or COO for approval, which is the whole
                        point — letting it be edited quietly here would walk
                        straight around that.
                    --}}
                </div>
                @endif
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
                        <x-label>Clocks in and out?</x-label>
                        <x-select wire:model="tracks_attendance">
                            <option value="1">Yes — uses the punch clock</option>
                            <option value="0">No — fixed work, no punching</option>
                        </x-select>
                        <p class="mt-1 text-xs font-medium text-[#778599]">Set per person, whatever their department. Choose No and payroll counts every scheduled day as worked, so no absence is ever deducted.</p>
                        @error('tracks_attendance') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
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

            <section class="professional-panel overflow-hidden rounded-2xl border-brand-200 bg-brand-50/60 dark:border-brand-500/20 dark:bg-brand-500/10">
                <div class="border-b border-brand-100 px-6 py-5 dark:border-brand-500/20">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Sales Compensation</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Commission Setup</h2>
                </div>
                <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                    <div>
                        <x-label>Earns commission?</x-label>
                        <x-select wire:model.live="commission_frequency">
                            <option value="none">No</option>
                            <option value="monthly">Yes — monthly</option>
                            <option value="biweekly">Yes — bi-weekly</option>
                        </x-select>
                        <p class="mt-1 text-xs font-medium text-[#778599]">Set per person, whatever their department. Pre-selects them on commission runs of that kind; they can still be added to any run by hand.</p>
                        @error('commission_frequency') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if ($this->earnsCommission())
                        <div>
                            <x-label>Commission Scheme</x-label>
                            <x-select wire:model="commission_scheme">
                                <option value="">Select scheme</option>
                                @foreach (\App\Models\CommissionScheme::options() as $schemeName)
                                    <option value="{{ $schemeName }}">{{ $schemeName }}</option>
                                @endforeach
                            </x-select>
                            <p class="mt-1 text-xs font-medium text-[#778599]">Kept in step with the CRM whenever this profile is opened.</p>
                            @error('commission_scheme') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-label>Agent Target</x-label>
                            <x-input wire:model="quota" type="number" step="0.01" />
                            <p class="mt-1 text-xs font-medium text-[#778599]">In US dollars. Must match Agent Target in the CRM commission profile &mdash; the CRM measures every agent against its own figure, not this one.</p>
                            @error('quota') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            </section>
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
