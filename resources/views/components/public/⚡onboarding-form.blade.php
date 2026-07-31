<?php

use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public Employee $employee;

    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $birthdate = '';
    public string $address = '';
    public string $personal_contact_number = '';
    public string $personal_email = '';
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
        $this->first_name = (string) $employee->first_name;
        $this->middle_name = (string) $employee->middle_name;
        $this->last_name = (string) $employee->last_name;
        $this->birthdate = $employee->birthdate?->format('Y-m-d') ?? '';
        $this->address = (string) $employee->address;
        $this->personal_contact_number = (string) $employee->personal_contact_number;
        $this->personal_email = (string) $employee->personal_email;
        $this->civil_status = (string) $employee->civil_status;
        $this->emergency_contact_name = (string) $employee->emergency_contact_name;
        $this->emergency_contact_number = (string) $employee->emergency_contact_number;
        $this->tin_number = (string) $employee->tin_number;
        $this->sss_number = (string) $employee->sss_number;
        $this->philhealth_number = (string) $employee->philhealth_number;
        $this->pagibig_number = (string) $employee->pagibig_number;
    }

    public function submit(): void
    {
        $data = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birthdate' => ['required', 'date', 'before:today'],
            'address' => ['required', 'string'],
            'personal_contact_number' => ['required', 'string', 'max:50'],
            'personal_email' => ['required', 'email'],
            'civil_status' => ['required', 'in:Single,Married,Widowed,Separated,Divorced'],
            'emergency_contact_name' => ['required', 'string', 'max:255'],
            'emergency_contact_number' => ['required', 'string', 'max:50'],
            'tin_number' => ['nullable', 'string', 'max:50'],
            'sss_number' => ['nullable', 'string', 'max:50'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'pagibig_number' => ['nullable', 'string', 'max:50'],
        ]);

        $data['onboarding_completed_at'] = now();

        $this->employee->update($data);
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
    @else
        <h1 class="mb-1 text-xl font-semibold text-[#0f172a] dark:text-white">Complete Your Profile</h1>
        <p class="mb-6 text-sm font-medium text-[#778599] dark:text-neutral-400">Welcome, {{ $employee->employee_id }}. Please fill in your personal details below.</p>

        <x-card>
            <form wire:submit="submit" class="space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <x-label>First Name</x-label>
                        <x-input wire:model="first_name" type="text" />
                        @error('first_name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Middle Name</x-label>
                        <x-input wire:model="middle_name" type="text" />
                    </div>
                    <div>
                        <x-label>Last Name</x-label>
                        <x-input wire:model="last_name" type="text" />
                        @error('last_name') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-label>Birthdate</x-label>
                        <x-input wire:model="birthdate" type="date" />
                        @error('birthdate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Civil Status</x-label>
                        <x-select wire:model="civil_status">
                            <option value="">Select</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Widowed">Widowed</option>
                            <option value="Separated">Separated</option>
                            <option value="Divorced">Divorced</option>
                        </x-select>
                        @error('civil_status') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <x-label>Address</x-label>
                    <x-textarea wire:model="address" rows="2" />
                    @error('address') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-label>Personal Contact #</x-label>
                        <x-input wire:model="personal_contact_number" type="text" />
                        @error('personal_contact_number') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Personal Email</x-label>
                        <x-input wire:model="personal_email" type="email" />
                        @error('personal_email') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/50">
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

                <div class="grid grid-cols-2 gap-4">
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

                <x-button type="submit">Submit</x-button>
            </form>
        </x-card>
    @endif
</div>