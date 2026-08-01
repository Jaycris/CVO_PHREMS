<?php

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeOnboardingCompleted;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public Employee $employee;

    /** 'form' while filling in details, 'review' on the confirmation step. */
    public string $step = 'form';

    // Employee-supplied fields. Everything HR captured at creation is shown
    // read-only instead, so the employee can verify but not alter it.
    public string $birthdate = '';
    public string $address = '';
    public string $personal_contact_number = '';
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

        $this->employee = $employee;
        $this->birthdate = $employee->birthdate?->format('Y-m-d') ?? '';
        $this->address = (string) $employee->address;
        $this->personal_contact_number = (string) $employee->personal_contact_number;
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
            'birthdate' => ['required', 'date', 'before:today'],
            'address' => ['required', 'string'],
            'personal_contact_number' => ['required', 'string', 'max:50'],
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
        // Re-validate rather than trusting the step flag — a crafted request
        // could call submit() directly without ever passing through next().
        $data = $this->validate($this->rules());

        abort_if($this->employee->onboarding_completed_at, 403, 'This onboarding form has already been submitted.');

        $data['onboarding_completed_at'] = now();
        $this->employee->update($data);
        $this->employee->refresh();

        // HR is notified in-app and by email so they know a profile is ready
        // for login creation. Admin is included because a small company may
        // have no dedicated HR account, and a silent no-op would strand the
        // employee. One bad address must not abort the rest.
        User::role(['HR', 'Admin'])->get()->each(function (User $hr): void {
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

<div class="mx-auto max-w-2xl px-4 py-10">
    <div class="mb-6 flex items-center justify-between">
        <img src="{{ asset('images/logo.png') }}" alt="CreatiVision" class="h-9 w-auto object-contain">
        <x-theme-toggle />
    </div>

    @if ($employee->onboarding_completed_at)
        <x-card class="p-8 text-center">
            <h1 class="text-lg font-semibold text-[#0f172a] dark:text-white">Thank you!</h1>
            <p class="mt-2 text-sm font-medium text-[#778599] dark:text-neutral-400">Your profile has been submitted. HR will follow up with your account access.</p>
        </x-card>
    @elseif ($step === 'review')
        <h1 class="mb-1 text-xl font-semibold text-[#0f172a] dark:text-white">Review Your Details</h1>
        <p class="mb-6 text-sm font-medium text-[#778599] dark:text-neutral-400">Please check everything below before submitting.</p>

        <x-card>
            <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Your Details</h2>
            <dl class="space-y-2 text-sm">
                @foreach ([
                    'Full Name' => trim($employee->first_name . ' ' . ($employee->middle_name ? $employee->middle_name . ' ' : '') . $employee->last_name),
                    'Phone Name' => $employee->phone_name ?: '—',
                    'Company Email' => $employee->company_email,
                    'Personal Email' => $employee->personal_email ?: '—',
                    'Department' => $employee->department->name,
                    'Job Title' => $employee->position->title,
                    'Birthdate' => $birthdate,
                    'Civil Status' => $civil_status,
                    'Address' => $address,
                    'Personal Contact' => $personal_contact_number,
                    'Emergency Contact' => $emergency_contact_name . ' (' . $emergency_contact_number . ')',
                    'TIN' => $tin_number ?: '—',
                    'SSS' => $sss_number ?: '—',
                    'PhilHealth' => $philhealth_number ?: '—',
                    'Pag-IBIG' => $pagibig_number ?: '—',
                ] as $label => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-[#778599] dark:text-neutral-400">{{ $label }}</dt>
                        <dd class="text-right text-[#65758c] dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employment</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-[#778599] dark:text-neutral-400">Basic Salary</dt>
                        <dd class="text-right text-[#65758c] dark:text-white">₱{{ number_format($employee->basic_salary, 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-[#778599] dark:text-neutral-400">Allowance</dt>
                        <dd class="text-right text-[#65758c] dark:text-white">₱{{ number_format($employee->allowance, 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-[#778599] dark:text-neutral-400">Employment Status</dt>
                        <dd class="text-right"><x-badge :color="$employee->employment_status === 'Regular' ? 'green' : 'amber'">{{ $employee->employment_status }}</x-badge></dd>
                    </div>
                </dl>
            </div>

            <div class="mt-6 flex gap-2">
                <x-button type="button" wire:click="submit">Submit</x-button>
                <x-button type="button" variant="secondary" wire:click="back">Back</x-button>
            </div>
        </x-card>
    @else
        <h1 class="mb-1 text-xl font-semibold text-[#0f172a] dark:text-white">Complete Your Profile</h1>
        <p class="mb-6 text-sm font-medium text-[#778599] dark:text-neutral-400">Welcome, {{ $employee->employee_id }}. Please fill in your personal details below.</p>

        <x-card>
            <form wire:submit="next" class="space-y-5">
                <div>
                    <h2 class="mb-1 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Recorded by HR</h2>
                    <p class="mb-3 text-xs font-medium text-[#778599]">Please check these are correct. Contact HR if anything is wrong.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-label>Full Name</x-label>
                            <x-input type="text" disabled value="{{ trim($employee->first_name . ' ' . ($employee->middle_name ? $employee->middle_name . ' ' : '') . $employee->last_name) }}" class="opacity-60" />
                        </div>
                        <div>
                            <x-label>Phone Name</x-label>
                            <x-input type="text" disabled value="{{ $employee->phone_name ?: '—' }}" class="opacity-60" />
                        </div>
                        <div>
                            <x-label>Company Email</x-label>
                            <x-input type="email" disabled value="{{ $employee->company_email }}" class="opacity-60" />
                        </div>
                        <div>
                            <x-label>Personal Email</x-label>
                            <x-input type="email" disabled value="{{ $employee->personal_email ?: '—' }}" class="opacity-60" />
                        </div>
                        <div>
                            <x-label>Department</x-label>
                            <x-input type="text" disabled value="{{ $employee->department->name }}" class="opacity-60" />
                        </div>
                        <div>
                            <x-label>Job Title</x-label>
                            <x-input type="text" disabled value="{{ $employee->position->title }}" class="opacity-60" />
                        </div>
                    </div>
                </div>

                <div class="border-t border-neutral-200 pt-5 dark:border-neutral-800">
                    <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Your Details</h2>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-label>Birthdate</x-label>
                            <x-input wire:model="birthdate" type="date" />
                            @error('birthdate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
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
                        <x-textarea wire:model="address" rows="2" />
                        @error('address') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-4">
                        <x-label>Personal Contact Number</x-label>
                        <x-input wire:model="personal_contact_number" type="text" />
                        @error('personal_contact_number') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                </div>

                <x-button type="submit">Next</x-button>
            </form>
        </x-card>
    @endif
</div>
