<?php

use App\Mail\OnboardingLinkMail;
use App\Models\Employee;
use App\Models\EmployeeLeaveDisposition;
use App\Models\EmployeeLeaveEligibility;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
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
        $this->mount($this->employee);
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

    public function mount(Employee $employee): void
    {
        $this->employee = $employee->load(['department', 'position', 'reportsTo', 'user']);
        $this->newScheduleStartDate = now()->toDateString();

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

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</h1>
        <div class="flex items-center gap-4">
            <a href="{{ route('employees.edit', $employee) }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Edit Profile</a>
            <a href="{{ route('employees.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">← Back to Employees</a>
        </div>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-card>
            <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">HR-Managed Info</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Employee ID</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->employee_id }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Phone Name</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->phone_name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Company Email</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->company_email }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Department</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->department->name }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Position</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->position->title }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Hire Date</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->hire_date->format('M d, Y') }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Basic Salary</dt><dd class="text-[#65758c] dark:text-white">₱{{ number_format($employee->basic_salary, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Allowance</dt><dd class="text-[#65758c] dark:text-white">₱{{ number_format($employee->allowance, 2) }}</dd></div>
                @if ($employee->commission_scheme)
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Commission Scheme</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->commission_scheme }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Quota</dt><dd class="text-[#65758c] dark:text-white">₱{{ number_format($employee->quota, 2) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Employment Status</dt><dd><x-badge :color="$employee->employment_status === 'Regular' ? 'green' : 'amber'">{{ $employee->employment_status }}</x-badge></dd></div>
                <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Reports To</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->reportsTo?->fullName() ?? '—' }}</dd></div>
            </dl>
        </x-card>

        <x-card>
            <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Personal Info (self-filled)</h2>
            @if ($employee->onboarding_completed_at)
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Birthdate</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->birthdate->format('M d, Y') }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Gender</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->gender ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Civil Status</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->civil_status }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Address</dt><dd class="text-right text-[#65758c] dark:text-white">{{ $employee->address }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Personal Contact</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->personal_contact_number }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Personal Email</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->personal_email }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Emergency Contact</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->emergency_contact_name }} ({{ $employee->emergency_contact_number }})</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">TIN</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->tin_number ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">SSS</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->sss_number ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">PhilHealth</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->philhealth_number ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="font-medium text-[#778599] dark:text-neutral-400">Pag-IBIG</dt><dd class="text-[#65758c] dark:text-white">{{ $employee->pagibig_number ?: '—' }}</dd></div>
                </dl>
            @else
                <p class="mb-4 text-sm font-medium text-[#778599]">Employee has not completed onboarding yet.</p>
                <form wire:submit="sendOnboardingLink" class="space-y-3">
                    <div>
                        <x-label>Send onboarding link to</x-label>
                        <div class="mt-1 space-y-2">
                            <label class="flex items-center gap-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                                <input type="radio" wire:model="onboardingEmailChoice" value="company"
                                       class="border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                                Company email <span class="text-xs text-[#778599]">({{ $employee->company_email }})</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                                <input type="radio" wire:model="onboardingEmailChoice" value="personal"
                                       class="border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                                Personal email <span class="text-xs text-[#778599]">({{ $employee->personal_email ?: 'not on file' }})</span>
                            </label>
                        </div>
                        @error('onboardingEmailChoice') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <x-button type="submit">Send Onboarding Link</x-button>
                </form>
            @endif

            <div class="mt-6 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <h2 class="mb-2 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Account</h2>
                @if ($employee->user_id)
                    <x-badge color="green">Login active ({{ $employee->user->email }})</x-badge>
                @elseif ($employee->onboarding_completed_at)
                    <p class="text-sm font-medium text-[#778599]">
                        Ready for credentials. Create the login from the
                        <a href="{{ route('users.index') }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-400">Users</a>
                        page.
                    </p>
                @else
                    <p class="text-sm font-medium text-[#778599]">Complete onboarding before creating a login.</p>
                @endif
            </div>
        </x-card>
    </div>

    <x-card>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Work Schedule</h2>
            <button wire:click="openScheduleModal" class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Update Work Schedule</button>
        </div>

        <p class="mb-4 text-sm font-medium text-[#778599] dark:text-neutral-300">
            Current:
            @if ($currentAssignment)
                <span class="font-medium text-[#65758c] dark:text-white">{{ $currentAssignment->workSchedule->name }}</span>
                <span class="font-medium text-[#778599] dark:text-neutral-400">(since {{ $currentAssignment->effective_start_date->format('M d, Y') }})</span>
            @else
                <span class="font-medium text-[#778599]">No schedule assigned</span>
            @endif
        </p>

        @if ($scheduleHistory->isNotEmpty())
            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Schedule</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">From</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">To</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($scheduleHistory as $assignment)
                            <tr wire:key="assign-{{ $assignment->id }}">
                                <td class="px-3 py-2 text-[#65758c] dark:text-white">{{ $assignment->workSchedule->name }}</td>
                                <td class="px-3 py-2 font-medium text-[#778599] dark:text-neutral-400">{{ $assignment->effective_start_date->format('M d, Y') }}</td>
                                <td class="px-3 py-2 font-medium text-[#778599] dark:text-neutral-400">{{ $assignment->effective_end_date?->format('M d, Y') ?? 'Present' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <x-card>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Payroll Settings</h2>
            <button wire:click="openPayrollModal" class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Edit Payroll Settings</button>
        </div>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            @foreach ([
                'Include in payroll runs' => $employee->include_in_payroll,
                'SSS contribution' => $employee->sss_enrolled,
                'PhilHealth contribution' => $employee->philhealth_enrolled,
                'Pag-IBIG contribution' => $employee->pagibig_enrolled,
                'BIR withholding tax' => $employee->bir_withholding_enrolled,
                'Allowance is taxable' => $employee->allowance_taxable,
            ] as $label => $on)
                <div class="flex items-center justify-between">
                    <dt class="font-medium text-[#778599] dark:text-neutral-400">{{ $label }}</dt>
                    <dd><x-badge :color="$on ? 'green' : 'neutral'">{{ $on ? 'Yes' : 'No' }}</x-badge></dd>
                </div>
            @endforeach
            <div class="flex items-center justify-between">
                <dt class="font-medium text-[#778599] dark:text-neutral-400">Separation Date</dt>
                <dd class="text-[#65758c] dark:text-white">{{ $employee->separation_date?->format('M d, Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="font-medium text-[#778599] dark:text-neutral-400">Separation Reason</dt>
                <dd class="text-[#65758c] dark:text-white">{{ $employee->separation_reason ?: '—' }}</dd>
            </div>
        </dl>
    </x-card>

    <x-card>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Leave Credits</h2>
            <button wire:click="openLeaveModal" class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Update Leave Credits</button>
        </div>

        @if (! $employee->isRegular())
            <p class="mb-3 text-sm text-amber-600 dark:text-amber-400">Employee is {{ $employee->employment_status }} — not yet eligible to accrue or use leave credits. Any leave taken will be Leave Without Pay.</p>
        @endif

        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Entitled</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Type</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Balance</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Year-end Disposition</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($leaveTypes as $type)
                        @php $eligible = $employee->isEligibleFor($type); @endphp
                        <tr wire:key="lt-view-{{ $type->id }}">
                            <td class="px-3 py-2">
                                <x-badge :color="$eligible ? 'green' : 'neutral'">{{ $eligible ? 'Yes' : 'No' }}</x-badge>
                            </td>
                            <td class="px-3 py-2 text-[#65758c] dark:text-white">
                                {{ $type->code }} — {{ $type->name }}
                                @if ($type->accrual_mode === 'event_based')
                                    <span class="block text-xs font-medium text-[#778599]">Event-based — granted per occurrence</span>
                                @elseif ($type->accrual_mode === 'monthly_accrual')
                                    <span class="block text-xs font-medium text-[#778599]">Accrues {{ rtrim(rtrim(number_format((float) $type->monthly_accrual_rate, 3), '0'), '.') }}/mo</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-medium text-[#65758c] dark:text-white">{{ $employee->leaveBalance($type) }} days</td>
                            <td class="px-3 py-2 font-medium text-[#778599] dark:text-neutral-400">
                                @if ($type->allow_carry_over || $type->allow_cash_conversion)
                                    {{ $employee->leaveDispositionFor($type) === 'cash_out' ? 'Cash Out' : 'Carry Over' }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Editors. The profile itself stays read-only; each opens from its section. --}}

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
