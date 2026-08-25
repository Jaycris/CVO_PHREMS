<?php

use App\Mail\OnboardingLinkMail;
use App\Models\Employee;
use App\Models\EmployeeLeaveDisposition;
use App\Models\EmployeeLeaveEligibility;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Services\Commission\CommissionProfileMirror;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Employee $employee;
    public string $onboardingEmailChoice = 'company';
    public ?string $statusMessage = null;
    public ?int $newScheduleId = null;
    public string $newScheduleStartDate = '';

    // Payroll settings
    public bool $includeInPayroll = true;
    public bool $sssEnrolled = true;
    public bool $philhealthEnrolled = true;
    public bool $pagibigEnrolled = true;
    public bool $birWithholdingEnrolled = true;
    public bool $allowanceTaxable = false;
    public ?string $separationDate = null;
    public ?string $separationReason = null;

    // The profile is read-only; each section opens its own editor.
    public bool $showScheduleModal = false;
    public bool $showPayrollModal = false;
    public bool $showLeaveModal = false;

    // Event-based leave grant (Maternity, Paternity, ...)
    public ?int $eventGrantTypeId = null;
    public string $eventGrantDays = '';
    public string $eventGrantNote = '';

    public function openScheduleModal(): void
    {
        $this->newScheduleId = null;
        $this->newScheduleStartDate = now()->toDateString();
        $this->resetValidation();
        $this->showScheduleModal = true;
    }

    public function openPayrollModal(): void
    {
        // Re-read from the record so a cancelled edit never leaves stale values.
        // Deliberately not mount(): that asks the CRM, and opening a modal is
        // not a reason to make an HTTP call.
        $this->loadPayrollState();
        $this->resetValidation();
        $this->showPayrollModal = true;
    }

    public function openLeaveModal(): void
    {
        $this->resetValidation();
        $this->showLeaveModal = true;
    }

    public function closeModals(): void
    {
        $this->showScheduleModal = false;
        $this->showPayrollModal = false;
        $this->showLeaveModal = false;
        $this->resetValidation();
    }

    public function mount(Employee $employee, CommissionProfileMirror $mirror): void
    {
        // The CRM owns an agent's scheme and target, so this page asks it
        // before showing them rather than printing whatever was last written
        // down. Answers are cached for a few minutes, non-agents are skipped
        // entirely, and a CRM that cannot answer leaves the stored figures
        // exactly as they were — opening a profile must never fail because
        // another system is down.
        $mirror->refresh($employee);

        $this->employee = $employee->fresh()->load(['department', 'position', 'reportsTo', 'user']);
        $this->newScheduleStartDate = now()->toDateString();

        $this->loadPayrollState();
    }

    /** The editable payroll flags, read off the record. */
    protected function loadPayrollState(): void
    {
        $employee = $this->employee;

        $this->includeInPayroll = (bool) $employee->include_in_payroll;
        $this->sssEnrolled = (bool) $employee->sss_enrolled;
        $this->philhealthEnrolled = (bool) $employee->philhealth_enrolled;
        $this->pagibigEnrolled = (bool) $employee->pagibig_enrolled;
        $this->birWithholdingEnrolled = (bool) $employee->bir_withholding_enrolled;
        $this->allowanceTaxable = (bool) $employee->allowance_taxable;
        $this->separationDate = $employee->separation_date?->toDateString();
        $this->separationReason = $employee->separation_reason;
    }

    public function savePayrollSettings(): void
    {
        $data = $this->validate([
            'separationDate' => ['nullable', 'date', 'after_or_equal:' . $this->employee->hire_date->toDateString()],
            'separationReason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->employee->update([
            'include_in_payroll' => $this->includeInPayroll,
            'sss_enrolled' => $this->sssEnrolled,
            'philhealth_enrolled' => $this->philhealthEnrolled,
            'pagibig_enrolled' => $this->pagibigEnrolled,
            'bir_withholding_enrolled' => $this->birWithholdingEnrolled,
            'allowance_taxable' => $this->allowanceTaxable,
            'separation_date' => $data['separationDate'] ?: null,
            'separation_reason' => $data['separationReason'] ?: null,
        ]);

        $this->employee->refresh();
        $this->showPayrollModal = false;
        $this->statusMessage = 'Payroll settings updated.';
    }

    public function assignSchedule(): void
    {
        $data = $this->validate([
            'newScheduleId' => ['required', 'exists:work_schedules,id'],
            'newScheduleStartDate' => ['required', 'date'],
        ]);

        $this->employee->assignSchedule(
            WorkSchedule::findOrFail($data['newScheduleId']),
            $data['newScheduleStartDate']
        );

        $this->reset(['newScheduleId']);
        $this->newScheduleStartDate = now()->toDateString();
        $this->showScheduleModal = false;
        $this->statusMessage = 'Work schedule assigned.';
    }

    public function sendOnboardingLink(): void
    {
        $this->validate([
            'onboardingEmailChoice' => ['required', 'in:company,personal'],
        ]);

        $recipient = $this->onboardingEmailChoice === 'personal'
            ? $this->employee->personal_email
            : $this->employee->company_email;

        if (! $recipient) {
            $this->addError('onboardingEmailChoice', 'That employee has no ' . $this->onboardingEmailChoice . ' email on file.');

            return;
        }

        $url = URL::temporarySignedRoute(
            'onboarding.show',
            now()->addDays(7),
            ['employee' => $this->employee->id]
        );

        Mail::to($recipient)->queue(new OnboardingLinkMail($this->employee, $url));

        $this->statusMessage = "Onboarding link sent to {$recipient}.";
    }

    public function grantInitialCredits(int $leaveTypeId): void
    {
        $leaveType = LeaveType::findOrFail($leaveTypeId);

        // Monthly-accrual types build their balance through leave:run-monthly-accrual.
        // An upfront grant on top of that would double the annual entitlement.
        abort_if($leaveType->accrual_mode === 'monthly_accrual', 403, "{$leaveType->code} accrues monthly and cannot be granted upfront.");

        // Event-based types are granted per occurrence via grantEventLeave().
        abort_if($leaveType->accrual_mode === 'event_based', 403, "{$leaveType->code} is event-based and is granted per occurrence.");

        abort_unless($this->employee->isEligibleFor($leaveType), 403, "This employee is not entitled to {$leaveType->name}.");

        abort_if($this->employee->leaveBalance($leaveType) > 0, 403, "{$leaveType->code} credits have already been granted.");

        LeaveCreditTransaction::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'transaction_date' => now()->toDateString(),
            'amount' => $leaveType->default_annual_credits,
            'reason' => 'initial_grant',
        ]);

        $this->statusMessage = "Granted {$leaveType->default_annual_credits} {$leaveType->code} credits.";
    }

    /**
     * Undo the most recent manual grant for a leave type.
     *
     * Deletes the ledger row rather than writing a compensating entry, because
     * the case this exists for is an accidental click that should never have
     * been recorded. It refuses once any of those credits have been spent,
     * since removing them would drive the balance negative — that situation
     * needs a deliberate adjustment, not an undo.
     */
    public function revertGrant(int $leaveTypeId): void
    {
        $leaveType = LeaveType::findOrFail($leaveTypeId);

        $grant = LeaveCreditTransaction::where('employee_id', $this->employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('reason', 'initial_grant')
            ->latest('id')
            ->first();

        abort_unless($grant, 404, "There is no manual {$leaveType->code} grant to revert.");

        $balanceAfter = $this->employee->leaveBalance($leaveType) - (float) $grant->amount;

        abort_if(
            $balanceAfter < 0,
            403,
            "Cannot revert — some of these {$leaveType->code} credits have already been used."
        );

        $amount = $grant->amount;
        $grant->delete();

        $this->statusMessage = "Reverted {$amount} {$leaveType->code} credit(s).";
    }

    public function toggleEligibility(int $leaveTypeId): void
    {
        $leaveType = LeaveType::findOrFail($leaveTypeId);
        $current = $this->employee->isEligibleFor($leaveType);

        EmployeeLeaveEligibility::updateOrCreate(
            ['employee_id' => $this->employee->id, 'leave_type_id' => $leaveType->id],
            ['is_eligible' => ! $current]
        );

        $this->employee->load('leaveEligibilities');
        $this->statusMessage = $current
            ? "{$leaveType->name} entitlement removed."
            : "{$leaveType->name} entitlement granted.";
    }

    public function openEventGrant(int $leaveTypeId): void
    {
        // Swap rather than stack — the leave editor reopens once this closes.
        $this->showLeaveModal = false;
        $this->eventGrantTypeId = $leaveTypeId;
        $this->eventGrantDays = (string) LeaveType::findOrFail($leaveTypeId)->default_annual_credits;
        $this->eventGrantNote = '';
        $this->resetValidation();
    }

    public function closeEventGrant(): void
    {
        $this->reset(['eventGrantTypeId', 'eventGrantDays', 'eventGrantNote']);
        $this->resetValidation();
        $this->showLeaveModal = true;
    }

    /**
     * Event-based leave is granted per occurrence — a second pregnancy earns a
     * second entitlement — so unlike grantInitialCredits() this deliberately
     * does NOT refuse when a balance already exists. The note records why.
     */
    public function grantEventLeave(): void
    {
        $data = $this->validate([
            'eventGrantTypeId' => ['required', 'exists:leave_types,id'],
            'eventGrantDays' => ['required', 'numeric', 'min:0.5'],
            'eventGrantNote' => ['required', 'string', 'max:255'],
        ]);

        $leaveType = LeaveType::findOrFail($data['eventGrantTypeId']);

        abort_unless($leaveType->accrual_mode === 'event_based', 403, "{$leaveType->code} is not an event-based leave type.");
        abort_unless($this->employee->isEligibleFor($leaveType), 403, "This employee is not entitled to {$leaveType->name}.");

        LeaveCreditTransaction::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'transaction_date' => now()->toDateString(),
            'amount' => $data['eventGrantDays'],
            'reason' => 'initial_grant',
            'note' => $data['eventGrantNote'],
        ]);

        $this->closeEventGrant();
        $this->statusMessage = "Granted {$data['eventGrantDays']} day(s) of {$leaveType->name}.";
    }

    public function setDisposition(int $leaveTypeId, string $disposition): void
    {
        EmployeeLeaveDisposition::updateOrCreate(
            ['employee_id' => $this->employee->id, 'leave_type_id' => $leaveTypeId],
            ['disposition' => $disposition]
        );

        $this->statusMessage = 'Year-end disposition updated.';
    }

    public function with(): array
    {
        return [
            'workSchedules' => WorkSchedule::orderBy('name')->get(),
            'currentAssignment' => $this->employee->currentScheduleAssignment(),
            'scheduleHistory' => $this->employee->scheduleAssignments()->with('workSchedule')->get(),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('name')->get(),
        ];
    }
};
?>

<div class="space-y-6" x-data="{ activeTab: 'personal' }">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-3">
                <x-avatar :employee="$employee" size="lg" />
                <div class="min-w-0">
                    <h1 class="truncate text-2xl font-bold text-ink-950 dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</h1>
                    <p class="mt-0.5 text-sm font-medium text-ink-500 dark:text-ink-400">
                        {{ $employee->employee_id }}
                        <span class="mx-1.5 text-ink-300 dark:text-ink-600">·</span>
                        {{ $employee->position?->title ?? 'No position assigned' }}
                    </p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('employees.edit', $employee) }}" wire:navigate
                class="inline-flex h-10 items-center gap-2 rounded-lg border border-ink-200 bg-white px-4 text-sm font-bold text-ink-700 shadow-sm transition hover:border-brand-300 hover:text-brand-700 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                <x-icon name="pencil" class="h-4 w-4" />
                Edit Profile
            </a>
            <a href="{{ route('employees.index') }}" wire:navigate
                class="inline-flex h-10 items-center gap-2 rounded-lg border border-ink-200 bg-white px-4 text-sm font-bold text-ink-700 shadow-sm transition hover:border-brand-300 hover:text-brand-700 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                <x-icon name="arrow-right" class="h-4 w-4 rotate-180" />
                Employees
            </a>
        </div>
    </div>

    @if ($statusMessage)
        <div class="flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200">
            <x-icon name="check" class="h-5 w-5 shrink-0" />
            {{ $statusMessage }}
        </div>
    @endif

    <div class="border-b border-ink-200 dark:border-white/10">
        <nav class="-mb-px flex gap-7 overflow-x-auto" aria-label="Employee profile sections">
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

        <div class="professional-panel overflow-hidden">
            <div class="flex items-center justify-between border-b border-ink-100 px-5 py-4 dark:border-white/10">
                <h3 class="text-lg font-bold text-ink-950 dark:text-white">Basic Information</h3>
                <a href="{{ route('employees.edit', $employee) }}" wire:navigate
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-brand-700 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-brand-300"
                    title="Edit employee profile">
                    <x-icon name="pencil" class="h-4 w-4" />
                </a>
            </div>

            <div class="grid gap-8 px-5 py-6 lg:grid-cols-[minmax(0,1.05fr)_1px_minmax(0,1fr)]">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                    <x-avatar :employee="$employee" size="xl" class="h-32 w-32 text-3xl ring-4 ring-ink-100 dark:ring-white/10" />
                    <div class="min-w-0">
                        <h3 class="truncate text-2xl font-bold text-ink-950 dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</h3>
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
                    </div>
                </div>

                <div class="hidden bg-ink-200 lg:block dark:bg-white/10"></div>

                <dl class="grid content-center gap-x-6 gap-y-4 sm:grid-cols-[140px_minmax(0,1fr)]">
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Birth Date</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->birthdate?->format('M d, Y') ?? '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Civil Status</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->civil_status ?: '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Phone Name</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->phone_name ?: '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Employment Status</dt>
                    <dd>
                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold {{ $employee->isRegular() ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300' : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-300' }}">
                            {{ $employee->employment_status }}
                        </span>
                    </dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Onboarding</dt>
                    <dd class="text-sm font-semibold {{ $employee->onboarding_completed_at ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                        {{ $employee->onboarding_completed_at ? 'Completed' : 'Pending' }}
                    </dd>
                </dl>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="professional-panel p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Address</h3>
                    <a href="{{ route('employees.edit', $employee) }}" wire:navigate class="text-ink-500 hover:text-brand-700 dark:text-ink-400 dark:hover:text-brand-300" title="Edit address">
                        <x-icon name="pencil" class="h-4 w-4" />
                    </a>
                </div>
                <dl class="grid gap-3 sm:grid-cols-[130px_minmax(0,1fr)]">
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Home Address</dt>
                    <dd class="text-sm font-medium leading-6 text-ink-950 dark:text-white">{{ $employee->address ?: 'Address not provided.' }}</dd>
                </dl>
            </div>

            <div class="professional-panel p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Emergency Contact</h3>
                    <a href="{{ route('employees.edit', $employee) }}" wire:navigate class="text-ink-500 hover:text-brand-700 dark:text-ink-400 dark:hover:text-brand-300" title="Edit emergency contact">
                        <x-icon name="pencil" class="h-4 w-4" />
                    </a>
                </div>
                <dl class="grid gap-3 sm:grid-cols-[130px_minmax(0,1fr)]">
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Name</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->emergency_contact_name ?: '—' }}</dd>
                    <dt class="text-sm font-semibold text-ink-600 dark:text-ink-300">Phone Number</dt>
                    <dd class="text-sm font-medium text-ink-950 dark:text-white">{{ $employee->emergency_contact_number ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        @if (! $employee->onboarding_completed_at)
            <div class="professional-panel overflow-hidden">
                <div class="border-b border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-400/20 dark:bg-amber-400/10">
                    <div class="flex items-start gap-3">
                        <x-icon name="clock" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" />
                        <div>
                            <h3 class="font-bold text-amber-900 dark:text-amber-100">Personal information is awaiting onboarding</h3>
                            <p class="mt-1 text-sm font-medium text-amber-700 dark:text-amber-200">Send the secure form to collect the remaining employee details.</p>
                        </div>
                    </div>
                </div>
                <form wire:submit="sendOnboardingLink" class="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    <div>
                        <x-label>Send onboarding link to</x-label>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-ink-200 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 dark:border-white/10 dark:text-ink-200 dark:hover:bg-white/5">
                                <input type="radio" wire:model="onboardingEmailChoice" value="company" class="border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-900">
                                <span class="min-w-0">Company email <span class="block truncate text-xs font-medium text-ink-500">{{ $employee->company_email }}</span></span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-ink-200 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 dark:border-white/10 dark:text-ink-200 dark:hover:bg-white/5">
                                <input type="radio" wire:model="onboardingEmailChoice" value="personal" class="border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-900">
                                <span class="min-w-0">Personal email <span class="block truncate text-xs font-medium text-ink-500">{{ $employee->personal_email ?: 'Not on file' }}</span></span>
                            </label>
                        </div>
                        @error('onboardingEmailChoice') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <x-button type="submit">
                        <x-icon name="arrow-right" class="h-4 w-4" />
                        Send Onboarding Link
                    </x-button>
                </form>
            </div>
        @endif

        <div class="professional-panel p-5">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                    <x-icon name="shield-check" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Account Access</h3>
                    @if ($employee->user_id && $employee->user?->is_active)
                        <p class="mt-1 break-words text-sm font-semibold text-emerald-700 dark:text-emerald-300">Login active · {{ $employee->user->email }}</p>
                    @elseif ($employee->user_id)
                        <p class="mt-1 break-words text-sm font-semibold text-red-600 dark:text-red-300">Login disabled · {{ $employee->user->email }}</p>
                        <a href="{{ route('users.index') }}" wire:navigate class="mt-1 inline-block text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300">Manage access in Users</a>
                    @elseif ($employee->onboarding_completed_at)
                        <p class="mt-1 text-sm font-semibold text-ink-700 dark:text-ink-200">Ready for credentials.</p>
                        <a href="{{ route('users.index') }}" wire:navigate class="mt-1 inline-block text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300">Create login in Users</a>
                    @else
                        <p class="mt-1 text-sm font-semibold text-ink-600 dark:text-ink-300">Complete onboarding before creating a login.</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'employment'" x-cloak class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-ink-950 dark:text-white">Employment Details</h2>
                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Assignment, reporting line, and compensation information.</p>
            </div>
            <a href="{{ route('employees.edit', $employee) }}" wire:navigate class="inline-flex h-10 items-center gap-2 rounded-lg border border-ink-200 bg-white px-4 text-sm font-bold text-ink-700 shadow-sm hover:text-brand-700 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                <x-icon name="pencil" class="h-4 w-4" />
                Edit
            </a>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="professional-panel overflow-hidden">
                <div class="border-b border-ink-100 px-5 py-4 dark:border-white/10">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Work Information</h3>
                </div>
                <dl class="grid gap-x-8 px-5 py-2 sm:grid-cols-2">
                    @foreach ([
                        'Employee ID' => $employee->employee_id,
                        'Company Email' => $employee->company_email,
                        'Department' => $employee->department?->name ?? '—',
                        'Position' => $employee->position?->title ?? '—',
                        'Hire Date' => $employee->hire_date->format('M d, Y'),
                        'Employment Status' => $employee->employment_status,
                        'Employment Type' => $employee->employment_type ?: '—',
                        'Workplace Type' => $employee->workplace_type ?: '—',
                        'Clocks In' => $employee->tracks_attendance ? 'Yes' : 'No — fixed work',
                        'Reports To' => $employee->reportsTo?->fullName() ?? '—',
                    ] as $label => $value)
                        <div class="min-w-0 border-b border-ink-100 py-4 dark:border-white/10">
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                            <dd class="mt-1 break-words text-sm font-semibold text-ink-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="professional-panel overflow-hidden">
                <div class="border-b border-ink-100 px-5 py-4 dark:border-white/10">
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Compensation</h3>
                </div>
                @php
                    $compensation = [
                        'Basic Salary' => 'PHP '.number_format($employee->basic_salary, 2),
                        'Allowance' => 'PHP '.number_format($employee->allowance, 2),
                    ];

                    // Commission rows only for people who earn it. Everybody
                    // else was being shown two dashes, which reads as something
                    // missing rather than something that does not apply.
                    if ($employee->commission_frequency !== 'none') {
                        $compensation['Commission Scheme'] = $employee->commission_scheme ?: '—';
                        $compensation['Agent Target'] = $employee->quota !== null
                            ? 'USD '.number_format($employee->quota, 2)
                            : '—';
                    }
                @endphp

                <dl class="grid gap-x-8 px-5 py-2 sm:grid-cols-2">
                    @foreach ($compensation as $label => $value)
                        <div class="min-w-0 border-b border-ink-100 py-4 dark:border-white/10">
                            <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</dt>
                            <dd class="mt-1 break-words text-base font-bold tabular-nums text-ink-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'payroll'" x-cloak class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-bold text-ink-950 dark:text-white">Payroll Details</h2>
                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Payroll enrollment and statutory contribution settings.</p>
            </div>
            <button wire:click="openPayrollModal" class="inline-flex h-10 items-center gap-2 rounded-lg border border-ink-200 bg-white px-4 text-sm font-bold text-ink-700 shadow-sm hover:text-brand-700 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                <x-icon name="pencil" class="h-4 w-4" />
                Edit Payroll Settings
            </button>
        </div>

        <div class="professional-panel p-5">
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    'Include in payroll runs' => $employee->include_in_payroll,
                    'SSS contribution' => $employee->sss_enrolled,
                    'PhilHealth contribution' => $employee->philhealth_enrolled,
                    'Pag-IBIG contribution' => $employee->pagibig_enrolled,
                    'BIR withholding tax' => $employee->bir_withholding_enrolled,
                    'Allowance is taxable' => $employee->allowance_taxable,
                ] as $label => $enabled)
                    <div class="flex items-center justify-between gap-4 border-b border-ink-100 pb-4 dark:border-white/10">
                        <dt class="text-sm font-semibold text-ink-700 dark:text-ink-200">{{ $label }}</dt>
                        <dd class="rounded-full px-2.5 py-1 text-xs font-bold {{ $enabled ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' : 'bg-ink-100 text-ink-600 dark:bg-white/10 dark:text-ink-300' }}">{{ $enabled ? 'Enabled' : 'Disabled' }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Separation Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->separation_date?->format('M d, Y') ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Separation Reason</dt>
                    <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->separation_reason ?: '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="professional-panel overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-ink-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                <div>
                    <h3 class="text-lg font-bold text-ink-950 dark:text-white">Bank Details</h3>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Approved account used for direct deposit.</p>
                </div>
                @can('bank_details.approve')
                    <a href="{{ route('bank-details.index') }}" wire:navigate
                        class="text-sm font-bold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">
                        Manage Bank Details
                    </a>
                @endcan
            </div>

            <dl class="grid gap-x-8 gap-y-5 p-5 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Bank</dt>
                    <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->bank_name ?: 'Not provided' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Account Name</dt>
                    <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->bank_account_name ?: 'Not provided' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Account Number</dt>
                    <dd class="mt-1 font-mono text-sm font-bold tracking-wide text-ink-950 dark:text-white">{{ $employee->hasBankDetails() ? $employee->maskedBankAccount() : 'Not provided' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Last Updated</dt>
                    <dd class="mt-1 text-sm font-semibold text-ink-950 dark:text-white">{{ $employee->bank_details_updated_at?->format('M d, Y') ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <section x-show="activeTab === 'schedule'" x-cloak class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
            <button wire:click="openScheduleModal" class="inline-flex h-10 items-center gap-2 rounded-lg border border-ink-200 bg-white px-4 text-sm font-bold text-ink-700 shadow-sm hover:text-brand-700 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                <x-icon name="pencil" class="h-4 w-4" />
                Update Schedule
            </button>
        </div>

        <div class="professional-panel overflow-hidden">
            @if ($scheduleHistory->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                        <thead class="bg-ink-50 dark:bg-white/[0.03]">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Schedule</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">From</th>
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">To</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                            @foreach ($scheduleHistory as $assignment)
                                <tr>
                                    <td class="px-5 py-4 font-semibold text-ink-950 dark:text-white">{{ $assignment->workSchedule->name }}</td>
                                    <td class="px-5 py-4 text-ink-700 dark:text-ink-200">{{ $assignment->effective_start_date->format('M d, Y') }}</td>
                                    <td class="px-5 py-4 text-ink-700 dark:text-ink-200">{{ $assignment->effective_end_date?->format('M d, Y') ?? 'Present' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-5 py-10 text-center text-sm font-medium text-ink-500 dark:text-ink-400">No schedule history yet.</div>
            @endif
        </div>
    </section>

    <section x-show="activeTab === 'leave'" x-cloak class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-bold text-ink-950 dark:text-white">Leave Credits</h2>
                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Eligibility, available balances, and year-end disposition.</p>
            </div>
            <button wire:click="openLeaveModal" class="inline-flex h-10 items-center gap-2 rounded-lg border border-ink-200 bg-white px-4 text-sm font-bold text-ink-700 shadow-sm hover:text-brand-700 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                <x-icon name="pencil" class="h-4 w-4" />
                Update Leave Credits
            </button>
        </div>

        @if (! $employee->isRegular())
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                Employee is {{ $employee->employment_status }} and is not yet eligible to accrue or use leave credits. Any leave taken will be Leave Without Pay.
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
                                <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">Balance</th>
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
                                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $eligible ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' : 'bg-ink-100 text-ink-600 dark:bg-white/10 dark:text-ink-300' }}">{{ $eligible ? 'Eligible' : 'Not eligible' }}</span>
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
                <div class="px-5 py-10 text-center text-sm font-medium text-ink-500 dark:text-ink-400">No active leave types configured.</div>
            @endif
        </div>
    </section>
    <x-modal :show="$showScheduleModal" onClose="closeModals">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">Update Work Schedule</h2>
        <form wire:submit="assignSchedule" class="space-y-4">
            <div>
                <x-label>Schedule</x-label>
                <x-select wire:model="newScheduleId">
                    <option value="">Select schedule</option>
                    @foreach ($workSchedules as $schedule)
                        <option value="{{ $schedule->id }}">{{ $schedule->name }} ({{ $schedule->start_time->format('g:i A') }}–{{ $schedule->end_time->format('g:i A') }})</option>
                    @endforeach
                </x-select>
                @error('newScheduleId') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-label>Effective Date</x-label>
                <x-input wire:model="newScheduleStartDate" type="date" />
                <p class="mt-1 text-xs font-medium text-[#778599]">The previous schedule is closed the day before this date.</p>
                @error('newScheduleStartDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2 pt-2">
                <x-button type="submit">Assign</x-button>
                <x-button type="button" variant="secondary" wire:click="closeModals">Cancel</x-button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$showPayrollModal" onClose="closeModals" maxWidth="lg">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">Edit Payroll Settings</h2>
        <form wire:submit="savePayrollSettings" class="space-y-4">
            <div class="space-y-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="includeInPayroll" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                    Include in payroll runs
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="sssEnrolled" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                    SSS contribution
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="philhealthEnrolled" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                    PhilHealth contribution
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="pagibigEnrolled" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                    Pag-IBIG contribution
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="birWithholdingEnrolled" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                    BIR withholding tax <span class="text-xs text-[#778599]">(uncheck for minimum-wage earners)</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="allowanceTaxable" class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                    Allowance is taxable <span class="text-xs text-[#778599]">(leave off if de minimis)</span>
                </label>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Separation Date</x-label>
                    <x-input wire:model="separationDate" type="date" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">Excludes the employee from payroll runs after they leave.</p>
                    @error('separationDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Separation Reason</x-label>
                    <x-input wire:model="separationReason" type="text" placeholder="e.g. Resigned" />
                    @error('separationReason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Save Payroll Settings</x-button>
                <x-button type="button" variant="secondary" wire:click="closeModals">Cancel</x-button>
            </div>
        </form>
    </x-modal>

    <x-modal :show="$showLeaveModal" onClose="closeModals" maxWidth="4xl">
        <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Update Leave Credits</h2>
        <p class="mb-4 text-sm font-medium text-[#778599]">Changes here apply immediately.</p>

        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Entitled</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Type</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Balance</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Year-end</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($leaveTypes as $type)
                        @php
                            $eligible = $employee->isEligibleFor($type);
                            $hasManualGrant = $employee->leaveCreditTransactions()
                                ->where('leave_type_id', $type->id)
                                ->where('reason', 'initial_grant')
                                ->exists();
                        @endphp
                        <tr wire:key="lt-edit-{{ $type->id }}" @class(['opacity-60' => ! $eligible])>
                            <td class="px-3 py-2">
                                <input type="checkbox" wire:click="toggleEligibility({{ $type->id }})" @checked($eligible)
                                       class="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                            </td>
                            <td class="px-3 py-2 text-[#65758c] dark:text-white">{{ $type->code }} — {{ $type->name }}</td>
                            <td class="px-3 py-2 font-medium text-[#65758c] dark:text-white">{{ $employee->leaveBalance($type) }} days</td>
                            <td class="px-3 py-2">
                                @if ($type->allow_carry_over || $type->allow_cash_conversion)
                                    <select wire:change="setDisposition({{ $type->id }}, $event.target.value)" class="rounded-lg border-neutral-300 bg-white text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
                                        <option value="carry_over" @selected($employee->leaveDispositionFor($type) === 'carry_over')>Carry Over</option>
                                        <option value="cash_out" @selected($employee->leaveDispositionFor($type) === 'cash_out')>Cash Out</option>
                                    </select>
                                @else
                                    <span class="font-medium text-[#778599]">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    @if (! $eligible)
                                        <span class="text-xs font-medium text-[#778599]">Not entitled</span>
                                    @elseif ($type->accrual_mode === 'monthly_accrual')
                                        <span class="text-xs font-medium text-[#778599]">Accrues {{ rtrim(rtrim(number_format((float) $type->monthly_accrual_rate, 3), '0'), '.') }}/mo</span>
                                    @elseif ($type->accrual_mode === 'event_based')
                                        <button type="button" wire:click="openEventGrant({{ $type->id }})"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Grant for an Event</button>
                                    @elseif ($employee->leaveBalance($type) <= 0)
                                        <button type="button" wire:click="grantInitialCredits({{ $type->id }})"
                                                wire:confirm="Grant {{ $type->default_annual_credits }} {{ $type->code }} credits to {{ $employee->fullName() ?: $employee->employee_id }}?"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Grant Initial Credits</button>
                                    @endif

                                    @if ($hasManualGrant)
                                        <button type="button" wire:click="revertGrant({{ $type->id }})"
                                                wire:confirm="Revert the last manual {{ $type->code }} grant for {{ $employee->fullName() ?: $employee->employee_id }}?"
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Revert Grant</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <x-button type="button" variant="secondary" wire:click="closeModals">Done</x-button>
        </div>
    </x-modal>

    <x-modal :show="$eventGrantTypeId !== null" onClose="closeEventGrant">
        @php $eventType = $eventGrantTypeId ? $leaveTypes->firstWhere('id', $eventGrantTypeId) : null; @endphp
        <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Grant {{ $eventType?->name }}</h2>
        <p class="mb-4 text-sm font-medium text-[#778599]">
            Event-based leave is granted per occurrence, so this can be used again for a future event.
        </p>

        <form wire:submit="grantEventLeave" class="space-y-4">
            <div>
                <x-label>Days to Grant</x-label>
                <x-input wire:model="eventGrantDays" type="number" step="0.5" min="0.5" />
                @error('eventGrantDays') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-label>Reason / Event</x-label>
                <x-input wire:model="eventGrantNote" type="text" placeholder="e.g. Maternity — expected delivery Nov 2026" />
                <p class="mt-1 text-xs font-medium text-[#778599]">Recorded against the credit for audit.</p>
                @error('eventGrantNote') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2 pt-2">
                <x-button type="submit">Grant</x-button>
                <x-button type="button" variant="secondary" wire:click="closeEventGrant">Cancel</x-button>
            </div>
        </form>
    </x-modal>
</div>
