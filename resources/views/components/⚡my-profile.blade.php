<?php

use App\Models\BankDetailRequest;
use App\Models\LeaveType;
use App\Services\BankDetailService;
use App\Support\StoresProfilePhoto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use StoresProfilePhoto, WithFileUploads;

    public $photo = null;
    public ?string $statusMessage = null;
    public bool $showPhotoPreview = false;

    public bool $showBankForm = false;
    public string $bankName = '';
    public string $bankAccountName = '';
    public string $bankAccountNumber = '';
    public string $bankAccountNumberConfirm = '';
    public string $bankReason = '';

    public function mount(): void
    {
        // Employees cannot reach the HR-only /employees routes, so this is the
        // only place they can maintain their own photo.
        abort_unless(Auth::user()->employee, 403, 'No employee profile is linked to your account.');
    }

    public function updatedPhoto(): void
    {
        $this->validate(['photo' => ['required', ...array_slice($this->photoRules(), 1)]]);

        $employee = Auth::user()->employee;
        $employee->update(['photo_path' => $this->storeProfilePhoto($employee, $this->photo)]);

        $this->reset('photo');
        $this->statusMessage = 'Profile photo updated.';
        $this->dispatch('profile-photo-updated', url: $employee->fresh()->photoUrl());
    }

    public function save(): void
    {
        $this->updatedPhoto();
    }

    public function openPhotoPreview(): void
    {
        $this->showPhotoPreview = true;
    }

    public function closePhotoPreview(): void
    {
        $this->showPhotoPreview = false;
    }

    public function removePhoto(): void
    {
        $employee = Auth::user()->employee;

        if ($employee->photo_path) {
            Storage::disk('public')->delete($employee->photo_path);
            $employee->update(['photo_path' => null]);
        }

        $this->reset('photo');
        $this->statusMessage = 'Profile photo removed.';
        $this->dispatch('profile-photo-updated', url: null);
    }

    public function openBankForm(): void
    {
        $employee = Auth::user()->employee;

        // Pre-filled with what is on file so a one-field correction does not
        // mean retyping the account number from memory.
        $this->bankName = (string) $employee->bank_name;
        $this->bankAccountName = (string) ($employee->bank_account_name ?: $employee->fullName());
        $this->bankAccountNumber = '';
        $this->bankAccountNumberConfirm = '';
        $this->bankReason = '';

        $this->resetValidation();
        $this->showBankForm = true;
    }

    public function closeBankForm(): void
    {
        $this->reset(['bankName', 'bankAccountName', 'bankAccountNumber', 'bankAccountNumberConfirm', 'bankReason']);
        $this->resetValidation();
        $this->showBankForm = false;
    }

    public function saveBankDetails(BankDetailService $service): void
    {
        $employee = Auth::user()->employee;
        $isChange = $service->hasDetails($employee);

        $this->validate([
            'bankName' => ['required', 'string', 'max:120'],
            'bankAccountName' => ['required', 'string', 'max:150'],
            // Digits, spaces and dashes only — banks print them either way, and
            // anything else is a typo rather than an account number.
            'bankAccountNumber' => ['required', 'string', 'max:40', 'regex:/^[0-9 \-]+$/'],
            'bankAccountNumberConfirm' => ['required', 'same:bankAccountNumber'],
            'bankReason' => [$isChange ? 'required' : 'nullable', 'string', 'max:200'],
        ], [
            'bankAccountNumber.regex' => 'An account number can only contain numbers, spaces and dashes.',
            'bankAccountNumberConfirm.same' => 'The two account numbers do not match.',
            'bankReason.required' => 'Tell HR why the account is changing.',
        ], [
            'bankName' => 'bank',
            'bankAccountName' => 'account name',
            'bankAccountNumber' => 'account number',
            'bankAccountNumberConfirm' => 'confirmation',
            'bankReason' => 'reason',
        ]);

        $request = $service->submit(
            $employee,
            $this->bankName,
            $this->bankAccountName,
            $this->bankAccountNumber,
            $this->bankReason ?: null,
        );

        $this->statusMessage = $request
            ? 'Sent to HR. Your salary keeps going to the account already on file until they approve it.'
            : 'Bank details saved.';

        $this->closeBankForm();
    }

    public function withdrawBankRequest(int $id, BankDetailService $service): void
    {
        $service->cancel(BankDetailRequest::findOrFail($id), Auth::user()->employee);
        $this->statusMessage = 'Request withdrawn.';
    }

    public function with(BankDetailService $service): array
    {
        $employee = Auth::user()->employee->fresh(['department', 'position', 'reportsTo']);

        return [
            'employee' => $employee,
            'currentAssignment' => $employee->currentScheduleAssignment(),
            'scheduleHistory' => $employee->scheduleAssignments()->with('workSchedule')->get(),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('name')->get(),
            'pendingBankRequest' => $service->pendingFor($employee),
            'bankHistory' => $employee->bankDetailRequests()
                ->whereIn('status', ['approved', 'declined'])
                ->latest()
                ->limit(5)
                ->get(),
        ];
    }
};
?>

@php
    $fullName = $employee->fullName() ?: auth()->user()->name;
    $photoUrl = $employee->photoUrl();
    $employmentDetails = [
        'Employee ID' => $employee->employee_id,
        'Phone Name' => $employee->phone_name ?: '-',
        'Department' => $employee->department?->name ?? '-',
        'Position' => $employee->position?->title ?? '-',
        'Reports To' => $employee->reportsTo?->fullName() ?: '-',
        'Hire Date' => $employee->hire_date?->format('M d, Y') ?? '-',
        'Employment Status' => $employee->employment_status ?: '-',
        'Employment Type' => $employee->employment_type ?: '-',
        'Workplace Type' => $employee->workplace_type ?: '-',
        'Onboarding' => $employee->onboarding_completed_at ? 'Completed' : 'Pending',
    ];

    $contactDetails = [
        'Company Email' => $employee->company_email ?: '-',
        'Personal Email' => $employee->personal_email ?: '-',
        'Personal Contact' => $employee->personal_contact_number ?: '-',
        'Emergency Contact' => trim(($employee->emergency_contact_name ?: '-') . ($employee->emergency_contact_number ? " ({$employee->emergency_contact_number})" : '')),
        'Address' => $employee->address ?: '-',
    ];

    $personalDetails = [
        'Birthdate' => $employee->birthdate?->format('M d, Y') ?? '-',
        'Civil Status' => $employee->civil_status ?: '-',
        'TIN' => $employee->tin_number ?: '-',
        'SSS' => $employee->sss_number ?: '-',
        'PhilHealth' => $employee->philhealth_number ?: '-',
        'Pag-IBIG' => $employee->pagibig_number ?: '-',
    ];

    $payDetails = [
        'Basic Salary' => 'PHP ' . number_format((float) $employee->basic_salary, 2),
        'Allowance' => 'PHP ' . number_format((float) $employee->allowance, 2),
        'Commission Scheme' => $employee->commission_scheme ?: '-',
        'Agent Target' => $employee->quota ? 'USD ' . number_format((float) $employee->quota, 2) : '-',
    ];
@endphp

{{--
    photoPreview holds a just-chosen photo as a link to the file already sitting
    in the browser, so the picture can be shown without asking the server for it.
    It lives on the outer element because both the avatar and the enlarged
    preview modal need to read it.
--}}
<div class="space-y-6" x-data="{ activeTab: 'personal', photoPreview: null }">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">My Profile</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">View your employment record, contact details, and payroll information.</p>
        </div>

        <x-button as="a" variant="secondary" href="{{ route('dashboard') }}" wire:navigate class="h-10 px-4">
            Back to Dashboard
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="border-b border-ink-200 dark:border-white/10">
        <nav class="-mb-px flex gap-7 overflow-x-auto" aria-label="My profile sections">
            @foreach ([
                'personal' => 'Personal Info',
                'employment' => 'Employment Details',
                'payroll' => 'Payroll Details',
                'schedule' => 'Work Schedule',
                'leave' => 'Leave Credits',
            ] as $tab => $label)
                <button type="button" @click="activeTab = '{{ $tab }}'"
                    class="relative shrink-0 border-b-2 px-0.5 pb-3 text-sm font-semibold transition"
                    :class="activeTab === '{{ $tab }}'
                        ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300'
                        : 'border-transparent text-ink-500 hover:text-ink-800 dark:text-ink-400 dark:hover:text-white'">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    <section x-show="activeTab === 'personal'" x-cloak class="space-y-4">
        <h2 class="text-xl font-bold text-ink-950 dark:text-white">Personal Info</h2>

        <div class="professional-panel overflow-visible">
            <div class="border-b border-ink-100 px-5 py-4 dark:border-white/10">
                <h3 class="text-lg font-bold text-ink-950 dark:text-white">Basic Information</h3>
            </div>

            <div class="grid gap-8 px-5 py-6 lg:grid-cols-[minmax(0,1.05fr)_1px_minmax(0,1fr)]">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                    <div x-data="{ open: false }" class="relative shrink-0">
                        <button type="button"
                                @click="open = ! open"
                                class="group relative block rounded-full focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-300/40"
                                title="Profile photo options">
                            <template x-if="photoPreview">
                                <img :src="photoPreview" alt=""
                                     class="h-32 w-32 rounded-full object-cover ring-4 ring-ink-100 transition group-hover:ring-brand-300/60 dark:ring-white/10">
                            </template>

                            <x-avatar :employee="$employee" size="xl" x-show="! photoPreview" class="!h-32 !w-32 !rounded-full !text-4xl ring-4 ring-ink-100 transition group-hover:ring-brand-300/60 dark:ring-white/10" />
                            <span class="absolute inset-x-0 bottom-0 rounded-b-full bg-ink-950/75 py-2 text-center text-xs font-bold text-white opacity-0 backdrop-blur transition group-hover:opacity-100">Change</span>
                        </button>

                        <input x-ref="photoInput" type="file" wire:model.live="photo" accept="image/jpeg,image/png,image/webp"
                               x-on:change="
                                   if (photoPreview) URL.revokeObjectURL(photoPreview);
                                   photoPreview = $event.target.files[0]
                                       ? URL.createObjectURL($event.target.files[0])
                                       : null;
                               "
                               class="hidden">

                        <div x-cloak
                             x-show="open"
                             x-transition
                             @click.outside="open = false"
                             class="absolute left-1/2 top-full z-[80] mt-3 w-64 -translate-x-1/2 overflow-hidden rounded-lg border border-ink-200 bg-white p-1.5 text-sm shadow-2xl shadow-ink-950/20 dark:border-white/10 dark:bg-ink-900">
                            <button type="button"
                                    wire:click="openPhotoPreview"
                                    @click="open = false"
                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 font-semibold text-ink-700 transition hover:bg-ink-50 dark:text-ink-200 dark:hover:bg-white/10">
                                <x-icon name="eye" class="h-4 w-4" />
                                View profile photo
                            </button>
                            <button type="button"
                                    @click="$refs.photoInput.click(); open = false"
                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 font-semibold text-ink-700 transition hover:bg-ink-50 dark:text-ink-200 dark:hover:bg-white/10">
                                <x-icon name="pencil" class="h-4 w-4" />
                                Upload new photo
                            </button>
                            @if ($employee->photo_path)
                                <button type="button"
                                        wire:click="removePhoto"
                                        wire:confirm="Remove your profile photo?"
                                        {{-- Drop the unsaved preview too, or removing the photo would appear to do nothing. --}}
                                        @click="open = false; photoPreview = null"
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 font-semibold text-red-600 transition hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10">
                                    <x-icon name="trash" class="h-4 w-4" />
                                    Remove photo
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="min-w-0">
                        <h3 class="truncate text-2xl font-bold text-ink-950 dark:text-white">{{ $fullName }}</h3>
                        <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">{{ $employee->employee_id }}</p>
                        <div class="mt-4 space-y-2.5 text-sm font-medium text-ink-700 dark:text-ink-200">
                            <div class="flex items-center gap-2.5">
                                <x-icon name="user-circle" class="h-4 w-4 shrink-0 text-ink-400" />
                                <span>{{ $employee->gender ?: 'Gender not provided' }}</span>
                            </div>
                            <div class="flex min-w-0 items-center gap-2.5">
                                <x-icon name="mail" class="h-4 w-4 shrink-0 text-ink-400" />
                                <span class="truncate" title="{{ $employee->personal_email ?: $employee->company_email }}">{{ $employee->personal_email ?: $employee->company_email }}</span>
                            </div>
                            <div class="flex items-center gap-2.5">
                                <x-icon name="phone" class="h-4 w-4 shrink-0 text-ink-400" />
                                <span>{{ $employee->personal_contact_number ?: 'Contact number not provided' }}</span>
                            </div>
                        </div>
                        <p wire:loading wire:target="photo" class="mt-3 text-xs font-semibold text-brand-700 dark:text-brand-300">Updating profile photo...</p>
                        @error('photo') <p class="mt-3 text-sm font-semibold text-red-600 dark:text-red-300">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="hidden bg-ink-200 lg:block dark:bg-white/10"></div>

                <dl class="grid content-center gap-x-6 gap-y-4 sm:grid-cols-[140px_minmax(0,1fr)]">
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Birth Date</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->birthdate?->format('M d, Y') ?? '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Civil Status</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->civil_status ?: '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Department</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->department?->name ?? '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Position</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->position?->title ?? '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Employment Status</dt>
                    <dd>
                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold {{ $employee->isRegular() ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300' : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-300' }}">
                            {{ $employee->employment_status ?: '—' }}
                        </span>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="professional-panel p-5">
                <h3 class="mb-4 text-lg font-bold text-ink-950 dark:text-white">Address</h3>
                <dl class="grid gap-3 sm:grid-cols-[130px_minmax(0,1fr)]">
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Home Address</dt>
                    <dd class="text-sm font-medium leading-6 text-ink-950 dark:text-white">{{ $employee->address ?: 'Address not provided.' }}</dd>
                </dl>
            </div>

            <div class="professional-panel p-5">
                <h3 class="mb-4 text-lg font-bold text-ink-950 dark:text-white">Emergency Contact</h3>
                <dl class="grid gap-3 sm:grid-cols-[130px_minmax(0,1fr)]">
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Name</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->emergency_contact_name ?: '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Phone Number</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->emergency_contact_number ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="rounded-lg border border-brand-200 bg-brand-50 p-5 text-sm font-medium leading-6 text-brand-900 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-100">
            Need to correct your official record? Please contact HR so your 201 file remains accurate.
        </div>
    </section>

    <section x-show="activeTab === 'employment'" x-cloak class="space-y-4">
        <div>
            <h2 class="text-xl font-bold text-ink-950 dark:text-white">Employment Details</h2>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Your current assignment and employment record.</p>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="professional-panel overflow-hidden">
                <div class="border-b border-ink-100 px-5 py-4 dark:border-white/10">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Work Information</h3>
                </div>
                <dl class="grid gap-x-8 px-5 py-2 sm:grid-cols-2">
                    @foreach ($employmentDetails as $label => $value)
                        <div class="min-w-0 border-b border-ink-100 py-4 dark:border-white/10">
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                            <dd class="mt-1 break-words text-sm font-semibold text-ink-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="professional-panel overflow-hidden">
                <div class="border-b border-ink-100 px-5 py-4 dark:border-white/10">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Contact Information</h3>
                </div>
                <dl class="grid gap-x-8 px-5 py-2 sm:grid-cols-2">
                    @foreach ($contactDetails as $label => $value)
                        <div class="min-w-0 border-b border-ink-100 py-4 {{ $label === 'Address' ? 'sm:col-span-2' : '' }} dark:border-white/10">
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                            <dd class="mt-1 break-words text-sm font-semibold text-ink-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'payroll'" x-cloak class="space-y-4">
        <div>
            <h2 class="text-xl font-bold text-ink-950 dark:text-white">Payroll Details</h2>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Your compensation and direct-deposit account.</p>
        </div>

        <div class="professional-panel overflow-hidden">
            <div class="border-b border-ink-100 px-5 py-4 dark:border-white/10">
                <h3 class="text-lg font-bold text-ink-950 dark:text-white">Pay Details</h3>
            </div>
            <dl class="grid gap-x-8 gap-y-5 p-5 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($payDetails as $label => $value)
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                        <dd class="mt-1 text-base font-bold tabular-nums text-ink-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="professional-panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4 dark:border-white/10">
                <div>
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Bank Details</h3>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Approved account used for direct deposit.</p>
                </div>

                @unless ($pendingBankRequest)
                    <x-button wire:click="openBankForm"
                              @click="$wire.showBankForm = true"
                              variant="secondary" class="h-10 px-4">
                        <x-icon name="pencil" class="h-4 w-4" />
                        {{ $employee->hasBankDetails() ? 'Request a Change' : 'Add Bank Details' }}
                    </x-button>
                @endunless
            </div>

            <div class="space-y-5 p-5">
                @if ($employee->hasBankDetails())
                    <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Bank</dt>
                            <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->bank_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Account Name</dt>
                            <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->bank_account_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Account Number</dt>
                            <dd class="mt-1 font-mono text-sm font-bold tracking-wide text-ink-950 dark:text-white">{{ $employee->maskedBankAccount() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Last Updated</dt>
                            <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->bank_details_updated_at?->format('M d, Y') ?? '—' }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs font-medium text-ink-500 dark:text-ink-400">Only the last four digits of your account number are displayed.</p>
                @else
                    <div class="rounded-lg border border-dashed border-ink-300 px-4 py-7 text-center dark:border-white/15">
                        <p class="text-sm font-semibold text-ink-800 dark:text-white">No bank details on file.</p>
                        <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Add your account so payroll knows where to send your salary.</p>
                    </div>
                @endif

                @if ($pendingBankRequest)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-amber-900 dark:text-amber-200">Waiting for approval</p>
                                <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-200/90">
                                    Requested change to <span class="font-bold">{{ $pendingBankRequest->bank_name }}</span>
                                    {{ $pendingBankRequest->maskedAccount() }}, filed {{ $pendingBankRequest->created_at->format('M j, Y') }}.
                                    Your salary continues to use the approved account until this request is accepted.
                                </p>
                            </div>

                            <x-button wire:click="withdrawBankRequest({{ $pendingBankRequest->id }})"
                                      wire:confirm="Withdraw this bank detail change?"
                                      variant="secondary" class="h-9 px-3 text-xs">
                                Withdraw
                            </x-button>
                        </div>
                    </div>
                @endif

                @if ($bankHistory->isNotEmpty())
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Recent Requests</p>
                        <ul class="mt-2 divide-y divide-ink-100 dark:divide-white/10">
                            @foreach ($bankHistory as $entry)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2.5" wire:key="bank-{{ $entry->id }}">
                                    <span class="text-sm font-medium text-ink-700 dark:text-ink-300">
                                        {{ $entry->bank_name }} {{ $entry->maskedAccount() }}
                                        <span class="text-ink-500">· {{ $entry->created_at->format('M j, Y') }}</span>
                                    </span>
                                    <x-badge :color="$entry->statusColor()">{{ $entry->statusLabel() }}</x-badge>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </section>
    <section x-show="activeTab === 'schedule'" x-cloak class="space-y-4">
        <div>
            <h2 class="text-xl font-bold text-ink-950 dark:text-white">Work Schedule</h2>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">
                Current:
                @if ($currentAssignment)
                    <span class="font-semibold text-ink-800 dark:text-white">{{ $currentAssignment->workSchedule->name }}</span>
                    since {{ $currentAssignment->effective_start_date->format('M d, Y') }}
                @else
                    No schedule assigned
                @endif
            </p>
        </div>

        <div class="professional-panel overflow-hidden">
            @if ($scheduleHistory->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                        <thead class="bg-ink-50 dark:bg-white/[0.03]">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Schedule</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Effective From</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Effective To</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                            @foreach ($scheduleHistory as $assignment)
                                <tr>
                                    <td class="px-5 py-4 font-semibold text-ink-950 dark:text-white">{{ $assignment->workSchedule->name }}</td>
                                    <td class="px-5 py-4 text-ink-700 dark:text-ink-200">{{ $assignment->effective_start_date->format('M d, Y') }}</td>
                                    <td class="px-5 py-4 text-ink-700 dark:text-ink-200">{{ $assignment->effective_end_date?->format('M d, Y') ?? 'Present' }}</td>
                                    <td class="px-5 py-4">
                                        @php $isCurrentAssignment = $currentAssignment?->id === $assignment->id; @endphp
                                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $isCurrentAssignment ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' : 'bg-ink-100 text-ink-600 dark:bg-white/10 dark:text-ink-300' }}">
                                            {{ $isCurrentAssignment ? 'Current' : 'Previous' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-5 py-10 text-center">
                    <x-icon name="calendar" class="mx-auto h-7 w-7 text-ink-300 dark:text-ink-500" />
                    <p class="mt-2 text-sm font-semibold text-ink-700 dark:text-ink-200">No work schedule assigned yet.</p>
                </div>
            @endif
        </div>
    </section>

    <section x-show="activeTab === 'leave'" x-cloak class="space-y-4">
        <div>
            <h2 class="text-xl font-bold text-ink-950 dark:text-white">Leave Credits</h2>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Your eligibility, available balances, and year-end treatment.</p>
        </div>

        @if (! $employee->isRegular())
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                Your current status is {{ $employee->employment_status }}. Leave credits become available according to the company eligibility policy.
            </div>
        @endif

        <div class="professional-panel overflow-hidden">
            @if ($leaveTypes->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                        <thead class="bg-ink-50 dark:bg-white/[0.03]">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Leave Type</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Eligibility</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Available Balance</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Year-End</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                            @foreach ($leaveTypes as $type)
                                @php $eligible = $employee->isEligibleFor($type); @endphp
                                <tr>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-ink-950 dark:text-white">{{ $type->name }}</p>
                                        <p class="mt-0.5 text-xs font-medium text-ink-500">{{ $type->code }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $eligible ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' : 'bg-ink-100 text-ink-600 dark:bg-white/10 dark:text-ink-300' }}">
                                            {{ $eligible ? 'Eligible' : 'Not eligible' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 font-semibold text-ink-950 dark:text-white">{{ $employee->leaveBalance($type) }} days</td>
                                    <td class="px-5 py-4 text-ink-700 dark:text-ink-200">
                                        @if ($type->allow_carry_over && $type->allow_cash_conversion)
                                            {{ $employee->leaveDispositionFor($type) === 'cash_out' ? 'Cash Out' : 'Carry Over' }}
                                        @elseif ($type->allow_carry_over)
                                            Carry Over
                                        @elseif ($type->allow_cash_conversion)
                                            Cash Out
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-5 py-10 text-center">
                    <x-icon name="document" class="mx-auto h-7 w-7 text-ink-300 dark:text-ink-500" />
                    <p class="mt-2 text-sm font-semibold text-ink-700 dark:text-ink-200">No active leave types are configured.</p>
                </div>
            @endif
        </div>
    </section>
    <x-modal wire="showBankForm" onClose="closeBankForm" maxWidth="lg">
        <h2 class="text-lg font-bold text-ink-950 dark:text-white">
            {{ $employee->hasBankDetails() ? 'Request a bank detail change' : 'Add your bank details' }}
        </h2>
        <p class="mt-1 text-sm font-medium text-[#778599]">
            @if ($employee->hasBankDetails())
                HR checks this before anything moves. Your salary keeps going to the account on file until they approve it.
            @else
                This is where your salary will be sent. Copy it from your bank, not from memory.
            @endif
        </p>

        <div class="mt-5 space-y-4">
            <div>
                <x-label>Bank</x-label>
                <x-input wire:model="bankName" type="text" placeholder="e.g. BDO Unibank" />
                @error('bankName') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Account name</x-label>
                <x-input wire:model="bankAccountName" type="text" placeholder="The name printed on the account" />
                @error('bankAccountName') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Typed twice, and never pre-filled. A wrong digit here sends a
                 salary to a stranger, and it is the one mistake nobody spots
                 until payday. --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Account number</x-label>
                    <x-input wire:model="bankAccountNumber" type="text" autocomplete="off" />
                    @error('bankAccountNumber') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Type it again</x-label>
                    <x-input wire:model="bankAccountNumberConfirm" type="text" autocomplete="off" />
                    @error('bankAccountNumberConfirm') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($employee->hasBankDetails())
                <div>
                    <x-label>Why is it changing?</x-label>
                    <x-textarea wire:model="bankReason" rows="2" placeholder="e.g. Closed my BPI account and moved to BDO" />
                    @error('bankReason') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        {{-- Saving genuinely needs the server, so the button says so while it
             waits rather than sitting there looking untouched. --}}
        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="saveBankDetails" wire:loading.attr="disabled" wire:target="saveBankDetails">
                <span wire:loading.remove wire:target="saveBankDetails">
                    {{ $employee->hasBankDetails() ? 'Send to HR' : 'Save' }}
                </span>
                <span wire:loading wire:target="saveBankDetails">Saving…</span>
            </x-button>
            <x-button wire:click="closeBankForm" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>

    <x-modal :show="$showPhotoPreview" onClose="closePhotoPreview" maxWidth="xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Profile Photo</p>
                <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">{{ $fullName }}</h2>
            </div>
            <button type="button" wire:click="closePhotoPreview" class="rounded-lg p-2 text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-white/10 dark:hover:text-white">
                <x-icon name="x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div class="mt-6 flex justify-center rounded-2xl bg-ink-50 p-6 dark:bg-white/5">
            <template x-if="photoPreview">
                <img :src="photoPreview" alt="" class="max-h-[60vh] rounded-2xl object-contain">
            </template>

            @if ($photoUrl)
                <img x-show="! photoPreview" src="{{ $photoUrl }}" alt="{{ $fullName }} profile photo" class="max-h-[60vh] rounded-2xl object-contain">
            @else
                <x-avatar :employee="$employee" size="xl" x-show="! photoPreview" class="!h-48 !w-48 !text-5xl" />
            @endif
        </div>
    </x-modal>
</div>
