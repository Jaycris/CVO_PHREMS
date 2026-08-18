<?php

use App\Models\BankDetailRequest;
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
        'Quota' => $employee->quota ? 'PHP ' . number_format((float) $employee->quota, 2) : '-',
    ];
@endphp

<div class="space-y-7">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">My Profile</h1>
            <p class="mt-2 text-sm font-medium text-[#526783] dark:text-ink-400">View your employment record, contact details, and profile photo.</p>
        </div>

        <x-button as="a" variant="secondary" href="{{ route('dashboard') }}" wire:navigate class="h-10 px-4">
            Back to Dashboard
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <section class="relative rounded-2xl border border-white/10 bg-ink-950 shadow-sm shadow-ink-200/50 dark:shadow-black/20">
        <div class="relative p-6 sm:p-8 lg:p-10">
            <div class="absolute inset-0"
                 style="border-radius: 1rem; background: radial-gradient(circle at 12% 8%, rgba(21,122,82,.48), transparent 25rem), radial-gradient(circle at 94% 8%, rgba(37,99,235,.18), transparent 24rem), linear-gradient(135deg, #020617 0%, #0f172a 52%, #052e23 100%);"></div>
            <img src="{{ asset('images/logo-mark.png') }}" alt="" class="pointer-events-none absolute -bottom-20 -left-16 h-72 w-72 object-contain opacity-[0.07]">
            <div class="relative grid gap-8 xl:grid-cols-[1fr_24rem] xl:items-center">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                    <div x-data="{ open: false }" class="relative shrink-0">
                        <button type="button"
                                @click="open = ! open"
                                class="group relative block rounded-full focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-300/40">
                            @if ($photo)
                                <img src="{{ $photo->temporaryUrl() }}" alt="Selected photo preview"
                                     class="h-32 w-32 rounded-full object-cover ring-4 ring-white/15 transition group-hover:ring-brand-300/60">
                            @else
                                <x-avatar :employee="$employee" size="xl" class="!h-32 !w-32 !rounded-full !text-4xl ring-4 ring-white/15 transition group-hover:ring-brand-300/60" />
                            @endif
                            <span class="absolute inset-x-0 bottom-0 rounded-b-full bg-ink-950/70 py-2 text-center text-xs font-bold text-white opacity-0 backdrop-blur transition group-hover:opacity-100">Change</span>
                        </button>

                        <input x-ref="photoInput" type="file" wire:model.live="photo" accept="image/jpeg,image/png,image/webp" class="hidden">

                        <div x-cloak
                             x-show="open"
                             x-transition
                             @click.outside="open = false"
                             class="absolute left-1/2 top-full z-[80] mt-3 w-64 -translate-x-1/2 overflow-hidden rounded-xl border border-ink-200 bg-white p-1.5 text-sm shadow-2xl shadow-ink-950/30 dark:border-white/10 dark:bg-ink-900">
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
                                        @click="open = false"
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 font-semibold text-red-600 transition hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10">
                                    <x-icon name="trash" class="h-4 w-4" />
                                    Remove photo
                                </button>
                            @endif
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-200">Employee Profile</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $fullName }}</h2>
                        <p class="mt-2 text-sm font-medium text-ink-300">{{ $employee->position?->title ?? 'No position assigned' }} @if($employee->department) · {{ $employee->department->name }} @endif</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-white ring-1 ring-white/10">{{ $employee->employee_id }}</span>
                            <span class="rounded-full bg-brand-400/15 px-3 py-1 text-xs font-bold uppercase tracking-wide text-brand-100 ring-1 ring-brand-300/20">{{ $employee->employment_status ?: 'No status' }}</span>
                        </div>
                        <p wire:loading wire:target="photo" class="mt-3 text-xs font-semibold text-brand-200">Updating profile photo...</p>
                        @error('photo') <p class="mt-3 text-sm font-semibold text-red-300">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">Company Email</p>
                        <p class="mt-1 break-all text-sm font-bold text-white">{{ $employee->company_email ?: '-' }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">Hire Date</p>
                        <p class="mt-1 text-sm font-bold text-white">{{ $employee->hire_date?->format('M d, Y') ?? '-' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div class="space-y-6">
            <x-card :padding="false" class="overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Work</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Employment Details</h2>
                </div>
                <div class="grid gap-0 divide-y divide-ink-100 dark:divide-white/10">
                    @foreach ($employmentDetails as $label => $value)
                        <div class="grid gap-2 px-6 py-4 sm:grid-cols-[14rem_1fr] sm:items-center">
                            <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</p>
                            <p class="text-sm font-semibold text-ink-900 dark:text-white">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card :padding="false" class="overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Contact</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Contact Information</h2>
                </div>
                <div class="grid gap-4 p-6 lg:grid-cols-2">
                    @foreach ($contactDetails as $label => $value)
                        <div class="{{ $label === 'Address' ? 'sm:col-span-2' : '' }} rounded-xl border border-ink-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-ink-900/70">
                            <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</p>
                            <p class="mt-1 text-sm font-semibold text-ink-900 dark:text-white">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            </x-card>

            {{-- Bank details. The one thing on this page an employee maintains
                 themselves, and the only field that redirects money — so the
                 first entry is theirs and every change after it is HR's. --}}
            <x-card :padding="false" class="overflow-hidden rounded-2xl">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Payout</p>
                        <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Bank Details</h2>
                    </div>

                    @unless ($pendingBankRequest)
                        {{-- The click paints the modal straight away; the server
                             call behind it only fills in the bank and account
                             name. Waiting for that first made the button look
                             broken. --}}
                        <x-button wire:click="openBankForm"
                                  @click="$wire.showBankForm = true"
                                  variant="secondary" class="h-10 px-4">
                            <x-icon name="pencil" class="h-4 w-4" />
                            {{ $employee->hasBankDetails() ? 'Request a change' : 'Add bank details' }}
                        </x-button>
                    @endunless
                </div>

                <div class="space-y-5 p-6">
                    @if ($employee->hasBankDetails())
                        <div class="grid gap-4 lg:grid-cols-3">
                            @foreach ([
                                'Bank' => $employee->bank_name,
                                'Account Name' => $employee->bank_account_name,
                                'Account Number' => $employee->maskedBankAccount(),
                            ] as $label => $value)
                                <div class="rounded-xl border border-ink-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-ink-900/70">
                                    <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</p>
                                    <p class="mt-1 text-sm font-semibold text-ink-900 dark:text-white">{{ $value ?: '-' }}</p>
                                </div>
                            @endforeach
                        </div>

                        @if ($employee->bank_details_updated_at)
                            <p class="text-xs font-medium text-ink-500 dark:text-ink-400">
                                Last changed {{ $employee->bank_details_updated_at->format('M j, Y') }}.
                                Only the last four digits are shown.
                            </p>
                        @endif
                    @else
                        <div class="rounded-xl border border-dashed border-ink-300 px-4 py-6 text-center dark:border-white/15">
                            <p class="text-sm font-semibold text-ink-800 dark:text-white">No bank details on file.</p>
                            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">
                                Add them so payroll knows where to send your salary. You can enter them yourself this once.
                            </p>
                        </div>
                    @endif

                    @if ($pendingBankRequest)
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-amber-900 dark:text-amber-200">Waiting for HR to approve</p>
                                    <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-200/90">
                                        You asked to change this to <span class="font-bold">{{ $pendingBankRequest->bank_name }}</span>
                                        {{ $pendingBankRequest->maskedAccount() }}, filed {{ $pendingBankRequest->created_at->format('M j, Y') }}.
                                        Until HR approves it, your salary still goes to the account above.
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
                            <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Recent requests</p>
                            <ul class="mt-2 divide-y divide-ink-100 dark:divide-white/10">
                                @foreach ($bankHistory as $entry)
                                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5" wire:key="bank-{{ $entry->id }}">
                                        <span class="text-sm font-medium text-ink-700 dark:text-ink-300">
                                            {{ $entry->bank_name }} {{ $entry->maskedAccount() }}
                                            <span class="text-ink-500">&middot; {{ $entry->created_at->format('M j, Y') }}</span>
                                        </span>
                                        <x-badge :color="$entry->statusColor()">{{ $entry->statusLabel() }}</x-badge>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>

        <aside class="space-y-6">
            <x-card :padding="false" class="overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Personal</p>
                    <h2 class="mt-1 text-lg font-bold text-ink-950 dark:text-white">Personal Details</h2>
                </div>
                <dl class="divide-y divide-ink-100 dark:divide-white/10">
                    @foreach ($personalDetails as $label => $value)
                        <div class="px-6 py-4">
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-ink-900 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card :padding="false" class="overflow-hidden rounded-2xl">
                <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Compensation</p>
                    <h2 class="mt-1 text-lg font-bold text-ink-950 dark:text-white">Pay Details</h2>
                </div>
                <dl class="divide-y divide-ink-100 dark:divide-white/10">
                    @foreach ($payDetails as $label => $value)
                        <div class="px-6 py-4">
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-ink-900 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <div class="rounded-2xl border border-brand-200 bg-brand-50 p-5 text-sm font-medium leading-6 text-brand-900 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-100">
                Need to correct your record? Please contact HR so your official 201 file stays accurate.
            </div>
        </aside>
    </div>

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
            <x-button wire:click="closeBankForm" @click="open = false" variant="secondary">Cancel</x-button>
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
            @if ($photo)
                <img src="{{ $photo->temporaryUrl() }}" alt="Selected photo preview" class="max-h-[60vh] rounded-2xl object-contain">
            @elseif ($photoUrl)
                <img src="{{ $photoUrl }}" alt="{{ $fullName }} profile photo" class="max-h-[60vh] rounded-2xl object-contain">
            @else
                <x-avatar :employee="$employee" size="xl" class="!h-48 !w-48 !text-5xl" />
            @endif
        </div>
    </x-modal>
</div>
