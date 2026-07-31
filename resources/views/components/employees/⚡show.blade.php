<?php

use App\Mail\AccountInviteMail;
use App\Mail\OnboardingLinkMail;
use App\Models\Employee;
use App\Models\EmployeeLeaveDisposition;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Employee $employee;
    public string $onboardingEmail = '';
    public ?string $statusMessage = null;
    public ?int $newScheduleId = null;
    public string $newScheduleStartDate = '';

    public function mount(Employee $employee): void
    {
        $this->employee = $employee->load(['department', 'position', 'reportsTo', 'user']);
        $this->onboardingEmail = $employee->company_email;
        $this->newScheduleStartDate = now()->toDateString();
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
        $this->statusMessage = 'Work schedule assigned.';
    }

    public function sendOnboardingLink(): void
    {
        $this->validate([
            'onboardingEmail' => ['required', 'email'],
        ]);

        $url = URL::temporarySignedRoute(
            'onboarding.show',
            now()->addDays(7),
            ['employee' => $this->employee->id]
        );

        Mail::to($this->onboardingEmail)->send(new OnboardingLinkMail($this->employee, $url));

        $this->statusMessage = "Onboarding link sent to {$this->onboardingEmail}.";
    }

    public function createLogin(): void
    {
        abort_if($this->employee->user_id, 403, 'This employee already has a login.');

        $user = User::create([
            'name' => $this->employee->fullName() ?: $this->employee->employee_id,
            'email' => $this->employee->company_email,
            'password' => Str::random(32),
        ]);
        $user->assignRole('Employee');

        $this->employee->update(['user_id' => $user->id]);

        $url = URL::temporarySignedRoute(
            'password.setup',
            now()->addDays(3),
            ['user' => $user->id]
        );

        Mail::to($this->employee->company_email)->send(new AccountInviteMail($this->employee, $url));

        $this->employee->refresh()->load('user');
        $this->statusMessage = "Login created. Invite sent to {$this->employee->company_email}.";
    }

    public function grantInitialCredits(int $leaveTypeId): void
    {
        $leaveType = LeaveType::findOrFail($leaveTypeId);

        // Monthly-accrual types build their balance through leave:run-monthly-accrual.
        // An upfront grant on top of that would double the annual entitlement.
        abort_if($leaveType->accrual_mode === 'monthly_accrual', 403, "{$leaveType->code} accrues monthly and cannot be granted upfront.");

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
        <a href="{{ route('employees.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">← Back to Employees</a>
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
                        <x-input wire:model="onboardingEmail" type="email" />
                        <p class="mt-1 text-xs font-medium text-[#778599]">Company or personal email — editable for this send.</p>
                        @error('onboardingEmail') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <x-button type="submit">Send Onboarding Link</x-button>
                </form>
            @endif

            <div class="mt-6 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <h2 class="mb-2 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Account</h2>
                @if ($employee->user_id)
                    <x-badge color="green">Login active ({{ $employee->user->email }})</x-badge>
                @elseif ($employee->onboarding_completed_at)
                    <x-button wire:click="createLogin">Create Login</x-button>
                @else
                    <p class="text-sm font-medium text-[#778599]">Complete onboarding before creating a login.</p>
                @endif
            </div>
        </x-card>
    </div>

    <x-card>
        <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Work Schedule</h2>

        <p class="mb-4 text-sm font-medium text-[#778599] dark:text-neutral-300">
            Current:
            @if ($currentAssignment)
                <span class="font-medium text-[#65758c] dark:text-white">{{ $currentAssignment->workSchedule->name }}</span>
                <span class="font-medium text-[#778599] dark:text-neutral-400">(since {{ $currentAssignment->effective_start_date->format('M d, Y') }})</span>
            @else
                <span class="font-medium text-[#778599]">No schedule assigned</span>
            @endif
        </p>

        <form wire:submit="assignSchedule" class="mb-4 flex flex-wrap items-end gap-3">
            <div class="min-w-[12rem] flex-1">
                <x-label>Assign Schedule</x-label>
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
                @error('newScheduleStartDate') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <x-button type="submit">Assign</x-button>
        </form>

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
        <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Leave Credits</h2>

        @if (! $employee->isRegular())
            <p class="mb-3 text-sm text-amber-600 dark:text-amber-400">Employee is {{ $employee->employment_status }} — not yet eligible to accrue or use leave credits. Any leave taken will be Leave Without Pay.</p>
        @endif

        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Type</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Balance</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Year-end Disposition</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($leaveTypes as $type)
                        <tr wire:key="lt-{{ $type->id }}">
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
                                @if ($type->accrual_mode === 'monthly_accrual')
                                    <span class="text-xs font-medium text-[#778599]">Accrues {{ rtrim(rtrim(number_format((float) $type->monthly_accrual_rate, 3), '0'), '.') }}/mo</span>
                                @elseif ($employee->leaveBalance($type) <= 0)
                                    <button wire:click="grantInitialCredits({{ $type->id }})"
                                            wire:confirm="Grant {{ $type->default_annual_credits }} {{ $type->code }} credits to {{ $employee->fullName() ?: $employee->employee_id }}?"
                                            class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Grant Initial Credits</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</div>