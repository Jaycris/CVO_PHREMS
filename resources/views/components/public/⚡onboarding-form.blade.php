<?php

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeOnboardingCompleted;
use App\Support\StoresProfilePhoto;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.guest')] class extends Component
{
    use StoresProfilePhoto, WithFileUploads;

    public Employee $employee;

    public string $step = 'form';

    /** Temporary upload; written to disk only once the form is submitted. */
    public $photo = null;

    public string $birthdate = '';
    public string $address = '';
    public string $personal_contact_number = '';
    public string $gender = '';
    public string $civil_status = '';
    public string $emergency_contact_name = '';
    public string $emergency_contact_number = '';
    public string $tin_number = '';
    public string $sss_number = '';
    public string $philhealth_number = '';
    public string $pagibig_number = '';

    public function mount(Employee $employee): void
    {
        abort_unless(request()->hasValidSignature(), 403);
        abort_if($employee->onboarding_completed_at, 403, 'This onboarding form has already been submitted.');

        $this->employee = $employee->load(['department', 'position']);
        $this->birthdate = $employee->birthdate?->format('Y-m-d') ?? '';
        $this->address = (string) $employee->address;
        $this->personal_contact_number = (string) $employee->personal_contact_number;
        $this->gender = (string) $employee->gender;
        $this->civil_status = (string) $employee->civil_status;
        $this->emergency_contact_name = (string) $employee->emergency_contact_name;
        $this->emergency_contact_number = (string) $employee->emergency_contact_number;
        $this->tin_number = (string) $employee->tin_number;
        $this->sss_number = (string) $employee->sss_number;
        $this->philhealth_number = (string) $employee->philhealth_number;
        $this->pagibig_number = (string) $employee->pagibig_number;
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'photo' => $this->photoRules(),
            'birthdate' => ['required', 'date', 'before:today'],
            'address' => ['required', 'string'],
            'personal_contact_number' => ['required', 'string', 'max:50'],
            'gender' => ['required', 'in:Male,Female'],
            'civil_status' => ['required', 'in:Single,Married,Widowed,Separated,Divorced'],
            'emergency_contact_name' => ['required', 'string', 'max:255'],
            'emergency_contact_number' => ['required', 'string', 'max:50'],
            'tin_number' => ['nullable', 'string', 'max:50'],
            'sss_number' => ['nullable', 'string', 'max:50'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'pagibig_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function next(): void
    {
        $this->validate($this->rules());

        $this->step = 'review';
    }

    public function back(): void
    {
        $this->step = 'form';
    }

    public function submit(): void
    {
        $data = $this->validate($this->rules());

        abort_if($this->employee->onboarding_completed_at, 403, 'This onboarding form has already been submitted.');

        // The upload is written to disk only now, so abandoning the form at the
        // review step leaves nothing behind.
        $data['photo_path'] = $this->storeProfilePhoto($this->employee, $this->photo);
        unset($data['photo']);

        $data['onboarding_completed_at'] = now();
        $this->employee->update($data);
        $this->employee->refresh();

        User::withPermission('employees.manage')->get()->each(function (User $hr): void {
            try {
                $hr->notify(new EmployeeOnboardingCompleted($this->employee));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function with(): array
    {
        return [
            'civilStatuses' => ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'],
        ];
    }
};
?>

@php
    $fullName = trim($employee->first_name . ' ' . ($employee->middle_name ? $employee->middle_name . ' ' : '') . $employee->last_name);
    $reviewRows = [
        'Full Name' => $fullName,
        'Phone Name' => $employee->phone_name ?: '-',
        'Company Email' => $employee->company_email,
        'Personal Email' => $employee->personal_email ?: '-',
        'Department' => $employee->department?->name ?? '-',
        'Job Title' => $employee->position?->title ?? '-',
        'Birthdate' => $birthdate,
        'Gender' => $gender,
        'Civil Status' => $civil_status,
        'Address' => $address,
        'Personal Contact' => $personal_contact_number,
        'Emergency Contact' => trim($emergency_contact_name . ' (' . $emergency_contact_number . ')'),
        'TIN' => $tin_number ?: '-',
        'SSS' => $sss_number ?: '-',
        'PhilHealth' => $philhealth_number ?: '-',
        'Pag-IBIG' => $pagibig_number ?: '-',
    ];
@endphp

<div class="min-h-screen bg-[#eef3f8] text-ink-900 dark:bg-ink-950 dark:text-white">
    <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex min-h-16 items-center rounded-2xl border border-ink-200 bg-white/90 px-5 py-4 shadow-sm shadow-ink-200/60 backdrop-blur dark:border-white/10 dark:bg-ink-900/80 dark:shadow-black/30">
            <img src="{{ asset('images/CreativeVision-LOGO-v2-03.png') }}" alt="CreatiVision" class="h-12 w-auto object-contain">
        </div>

        @if ($employee->onboarding_completed_at)
            <div class="mx-auto mt-16 w-full max-w-xl rounded-2xl border border-ink-200 bg-white p-8 text-center shadow-sm dark:border-white/10 dark:bg-ink-900">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                    <x-icon name="check" class="h-7 w-7" />
                </div>
                <h1 class="mt-5 text-2xl font-bold text-ink-950 dark:text-white">Profile submitted</h1>
                <p class="mt-2 text-sm font-medium text-ink-600 dark:text-ink-300">Thank you. HR will review your details and follow up with your HRIS account access.</p>
            </div>
        @else
            <div class="grid flex-1 gap-6 lg:grid-cols-[0.8fr_1.2fr]">
                <aside class="rounded-3xl bg-gradient-to-br from-ink-950 via-ink-900 to-brand-950 p-8 text-white shadow-xl shadow-ink-300/50 dark:shadow-black/30">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-brand-200">CreatiVision HRIS</p>
                    <h1 class="mt-5 text-4xl font-black leading-tight">
                        {{ $step === 'review' ? 'Review your profile.' : 'Complete your employee profile.' }}
                    </h1>
                    <p class="mt-4 max-w-md text-sm font-medium leading-7 text-ink-200">
                        {{ $step === 'review' ? 'Please confirm every detail before submitting to HR.' : 'HR has already recorded your work details. Please check them and complete your personal information.' }}
                    </p>

                    <div class="mt-8 rounded-2xl border border-white/10 bg-white/10 p-5">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-200">Employee</p>
                        <p class="mt-2 text-2xl font-bold">{{ $employee->employee_id }}</p>
                        <p class="mt-1 text-sm font-medium text-ink-200">{{ $fullName ?: $employee->company_email }}</p>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-ink-300">Step</p>
                            <p class="mt-1 font-bold">{{ $step === 'review' ? 'Review' : 'Profile' }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/10 p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-ink-300">Status</p>
                            <p class="mt-1 font-bold">Pending HR</p>
                        </div>
                    </div>
                </aside>

                <main class="rounded-3xl border border-ink-200 bg-white shadow-sm shadow-ink-200/70 dark:border-white/10 dark:bg-ink-900 dark:shadow-black/20">
                    @if ($step === 'review')
                        <div class="border-b border-ink-200 px-7 py-6 dark:border-white/10">
                            <h2 class="text-2xl font-bold text-ink-950 dark:text-white">Review Details</h2>
                            <p class="mt-1 text-sm font-medium text-ink-600 dark:text-ink-300">This is the final copy HR will receive.</p>
                        </div>

                        <div class="space-y-6 p-7">
                            <section>
                                <h3 class="mb-3 text-xs font-bold uppercase tracking-[0.2em] text-ink-500 dark:text-ink-400">Profile Details</h3>
                                <div class="overflow-hidden rounded-2xl border border-ink-200 dark:border-white/10">
                                    @foreach ($reviewRows as $label => $value)
                                        <div class="grid gap-2 border-b border-ink-100 px-4 py-3 text-sm last:border-b-0 sm:grid-cols-[12rem_1fr] dark:border-white/10">
                                            <dt class="font-bold text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                                            <dd class="font-semibold text-ink-900 dark:text-white">{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-3 text-xs font-bold uppercase tracking-[0.2em] text-ink-500 dark:text-ink-400">Compensation And Status</h3>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div class="rounded-2xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Basic Salary</p>
                                        <p class="mt-2 text-xl font-bold text-ink-950 dark:text-white">PHP {{ number_format($employee->basic_salary, 2) }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Allowance</p>
                                        <p class="mt-2 text-xl font-bold text-ink-950 dark:text-white">PHP {{ number_format($employee->allowance, 2) }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-ink-200 bg-ink-50 p-4 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Employment Status</p>
                                        <div class="mt-2"><x-badge :color="$employee->employment_status === 'Regular' ? 'green' : 'amber'">{{ $employee->employment_status }}</x-badge></div>
                                    </div>
                                </div>
                            </section>

                            <div class="flex flex-wrap gap-3 border-t border-ink-200 pt-6 dark:border-white/10">
                                <x-button type="button" wire:click="submit">Submit To HR</x-button>
                                <x-button type="button" variant="secondary" wire:click="back">Back</x-button>
                            </div>
                        </div>
                    @else
                        <div class="border-b border-ink-200 px-7 py-6 dark:border-white/10">
                            <h2 class="text-2xl font-bold text-ink-950 dark:text-white">Employee Onboarding Form</h2>
                            <p class="mt-1 text-sm font-medium text-ink-600 dark:text-ink-300">Visible HR details are locked. Complete the remaining personal information.</p>
                        </div>

                        <form wire:submit="next" class="space-y-7 p-7">
                            <section>
                                <h3 class="mb-4 text-xs font-bold uppercase tracking-[0.2em] text-ink-500 dark:text-ink-400">Recorded By HR</h3>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    @foreach ([
                                        'Full Name' => $fullName,
                                        'Phone Name' => $employee->phone_name ?: '-',
                                        'Company Email' => $employee->company_email,
                                        'Personal Email' => $employee->personal_email ?: '-',
                                        'Department' => $employee->department?->name ?? '-',
                                        'Job Title' => $employee->position?->title ?? '-',
                                        'Basic Salary' => 'PHP ' . number_format($employee->basic_salary, 2),
                                        'Allowance' => 'PHP ' . number_format($employee->allowance, 2),
                                        'Commission Scheme' => $employee->commission_scheme ?: '-',
                                        'Quota' => $employee->quota ? 'PHP ' . number_format($employee->quota, 2) : '-',
                                        'Employee Status' => $employee->employment_status,
                                    ] as $label => $value)
                                        <div>
                                            <x-label class="text-ink-500 dark:text-ink-400">{{ $label }}</x-label>
                                            <x-input
                                                type="text"
                                                disabled
                                                value="{{ $value }}"
                                                class="cursor-not-allowed !border-ink-200 !bg-[#eef2f6] !font-semibold !text-ink-500 !shadow-none opacity-100 dark:!border-white/10 dark:!bg-white/10 dark:!text-ink-400"
                                            />
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            <section class="border-t border-ink-200 pt-7 dark:border-white/10">
                                <h3 class="mb-4 text-xs font-bold uppercase tracking-[0.2em] text-ink-500 dark:text-ink-400">Profile Photo</h3>

                                <div class="flex flex-wrap items-center gap-5">
                                    @if ($photo)
                                        <img src="{{ $photo->temporaryUrl() }}" alt="Selected photo preview"
                                             class="h-24 w-24 shrink-0 rounded-full object-cover ring-1 ring-black/5 dark:ring-white/10">
                                    @else
                                        <x-avatar :employee="$employee" size="xl" />
                                    @endif

                                    <div class="min-w-[14rem] flex-1">
                                        <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp"
                                               class="block w-full text-sm text-ink-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-700 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-800 dark:text-ink-300">
                                        <p class="mt-2 text-xs font-medium text-ink-500 dark:text-ink-400">
                                            Optional. JPG, PNG or WEBP, up to 4MB. You can change it later from your profile.
                                        </p>
                                        <p wire:loading wire:target="photo" class="mt-1 text-xs font-semibold text-brand-700 dark:text-brand-300">Uploading...</p>
                                        @error('photo') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </section>

                            <section class="border-t border-ink-200 pt-7 dark:border-white/10">
                                <h3 class="mb-4 text-xs font-bold uppercase tracking-[0.2em] text-ink-500 dark:text-ink-400">Personal Details</h3>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-label>Birthdate</x-label>
                                        <div class="relative" x-data="datePicker($wire.entangle('birthdate').live)" @click.outside="open = false">
                                            <button type="button" @click="open = !open" class="flex h-11 w-full items-center justify-between rounded-lg border border-ink-200 bg-white px-3.5 text-left text-sm font-semibold text-ink-700 shadow-sm transition hover:border-brand-300 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                                                <span x-text="display()"></span>
                                                <x-icon name="calendar" class="h-4 w-4 text-ink-400" />
                                            </button>
                                            <div x-cloak x-show="open" x-transition class="absolute z-20 mt-2 w-80 rounded-2xl border border-ink-200 bg-white p-4 shadow-xl shadow-ink-200/70 dark:border-white/10 dark:bg-ink-900 dark:shadow-black/30">
                                                <div class="mb-4 flex items-center justify-between">
                                                    <button type="button" @click="previousMonth()" class="rounded-lg p-2 text-ink-500 hover:bg-ink-100 dark:hover:bg-white/10"><x-icon name="chevron-down" class="h-4 w-4 rotate-90" /></button>
                                                    <p class="text-sm font-bold text-ink-950 dark:text-white"><span x-text="monthNames[month]"></span> <span x-text="year"></span></p>
                                                    <button type="button" @click="nextMonth()" class="rounded-lg p-2 text-ink-500 hover:bg-ink-100 dark:hover:bg-white/10"><x-icon name="chevron-down" class="h-4 w-4 -rotate-90" /></button>
                                                </div>
                                                <div class="grid grid-cols-7 gap-1 text-center text-xs font-bold uppercase text-ink-400">
                                                    <template x-for="dayName in dayNames" :key="dayName"><div x-text="dayName"></div></template>
                                                </div>
                                                <div class="mt-2 grid grid-cols-7 gap-1 text-sm">
                                                    <template x-for="blank in firstDay()" :key="`blank-${blank}`"><div></div></template>
                                                    <template x-for="day in daysInMonth()" :key="day">
                                                        <button type="button" @click="select(day)" class="rounded-lg px-2 py-2 font-semibold text-ink-700 hover:bg-brand-50 hover:text-brand-700 dark:text-ink-200 dark:hover:bg-brand-500/10" :class="{ 'bg-brand-700 text-white hover:bg-brand-700 hover:text-white dark:bg-brand-600': isSelected(day) }" x-text="day"></button>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                        @error('birthdate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-label>Gender</x-label>
                                        <x-select wire:model="gender">
                                            <option value="">Select gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </x-select>
                                        @error('gender') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-label>Civil Status</x-label>
                                        <x-select wire:model="civil_status">
                                            <option value="">Select status</option>
                                            @foreach ($civilStatuses as $status)
                                                <option value="{{ $status }}">{{ $status }}</option>
                                            @endforeach
                                        </x-select>
                                        @error('civil_status') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <x-label>Address</x-label>
                                    <x-textarea wire:model="address" rows="3" />
                                    @error('address') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <x-label>Personal Contact Number</x-label>
                                        <x-input wire:model="personal_contact_number" type="text" />
                                        @error('personal_contact_number') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-label>Emergency Contact Name</x-label>
                                        <x-input wire:model="emergency_contact_name" type="text" />
                                        @error('emergency_contact_name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-label>Emergency Contact Number</x-label>
                                        <x-input wire:model="emergency_contact_number" type="text" />
                                        @error('emergency_contact_number') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <x-label>TIN Number</x-label>
                                        <x-input wire:model="tin_number" type="text" />
                                    </div>
                                    <div>
                                        <x-label>SSS Number</x-label>
                                        <x-input wire:model="sss_number" type="text" />
                                    </div>
                                    <div>
                                        <x-label>PhilHealth Number</x-label>
                                        <x-input wire:model="philhealth_number" type="text" />
                                    </div>
                                    <div>
                                        <x-label>Pag-IBIG Number</x-label>
                                        <x-input wire:model="pagibig_number" type="text" />
                                    </div>
                                </div>
                            </section>

                            <div class="flex justify-end border-t border-ink-200 pt-6 dark:border-white/10">
                                <x-button type="submit">Next</x-button>
                            </div>
                        </form>
                    @endif
                </main>
            </div>
        @endif
    </div>
</div>
