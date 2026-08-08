<?php

use App\Mail\AccountInviteMail;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showForm = false;
    public bool $showConfirmation = false;
    public string $confirmationMessage = '';

    public ?int $employeeId = null;
    public string $email = '';
    public string $role = 'Employee';

    // System-assigned. Locked so a crafted request cannot swap it for a
    // chosen value — disabling the input only stops honest editing.
    #[Locked]
    public string $userCode = '';

    public string $search = '';

    public function create(): void
    {
        $this->reset(['employeeId', 'email']);
        $this->role = 'Employee';
        $this->userCode = User::generateUserCode();
        $this->resetValidation();
        $this->showForm = true;
    }

    /** Selecting an employee fills the address the invitation will be sent to. */
    public function updatedEmployeeId($value): void
    {
        $this->email = $value
            ? (string) Employee::find($value)?->company_email
            : '';
    }

    public function save(): void
    {
        $data = $this->validate([
            'employeeId' => ['required', 'exists:employees,id'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', 'exists:roles,name'],
        ]);

        $employee = Employee::findOrFail($data['employeeId']);

        // A login only ever exists on top of an employee record, and never twice.
        abort_if($employee->user_id !== null, 403, 'This employee already has a login.');

        $user = DB::transaction(function () use ($employee, $data) {
            // The code was reserved when the form opened; if another account
            // claimed it in the meantime, take a fresh one rather than failing
            // on the unique index.
            $code = $this->userCode;
            if ($code === '' || User::where('user_code', $code)->exists()) {
                $code = User::generateUserCode();
            }

            $user = User::create([
                'user_code' => $code,
                'name' => $employee->fullName() ?: $employee->employee_id,
                'email' => $data['email'],
                // Placeholder only — the employee sets their own via the invite link.
                'password' => Str::random(40),
            ]);

            $user->assignRole($data['role']);
            $employee->update(['user_id' => $user->id]);

            return $user;
        });

        $url = URL::temporarySignedRoute('password.setup', now()->addDays(3), ['user' => $user->id]);
        Mail::to($user->email)->send(new AccountInviteMail($employee, $url));

        $this->showForm = false;
        $this->confirmationMessage = "User has been registered. Invitation has been sent to {$user->email}.";
        $this->showConfirmation = true;
        $this->reset(['employeeId', 'email']);
    }

    public function closeForm(): void
    {
        $this->reset(['employeeId', 'email']);
        $this->resetValidation();
        $this->showForm = false;
    }

    public function acknowledge(): void
    {
        $this->showConfirmation = false;
        $this->confirmationMessage = '';
    }

    public function with(): array
    {
        return [
            'users' => User::with('employee')
                ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('user_code', 'like', "%{$this->search}%")))
                ->orderBy('name')
                ->get(),
            // Only employees who don't already have credentials can be picked.
            'availableEmployees' => Employee::whereNull('user_id')->orderBy('employee_id')->get(),
            'roles' => Role::orderBy('name')->pluck('name'),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Users</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Manage HRIS login accounts. Every user is tied to an employee record.</p>
        </div>
        <x-button wire:click="create" pill>
            <x-icon name="plus" class="h-4 w-4" /> Add User
        </x-button>
    </div>

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">User Directory</h2>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search users…"
                   class="w-56 rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-[#65758c] shadow-sm placeholder:text-[#778599] focus:border-brand-500 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-5 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">User ID</th>
                        <th class="px-4 py-5 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Employee</th>
                        <th class="px-4 py-5 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Email</th>
                        <th class="px-4 py-5 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Role</th>
                        <th class="px-4 py-5 text-left text-xs font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Password</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-3 text-sm font-medium text-[#65758c] dark:text-white">{{ $user->user_code }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-[#65758c] dark:text-white">
                                {{ $user->name }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $user->employee?->employee_id ?? 'No employee linked' }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-[#778599] dark:text-neutral-400">{{ $user->email }}</td>
                            <td class="px-4 py-3 text-sm"><x-badge color="brand">{{ $user->getRoleNames()->join(', ') ?: 'No role' }}</x-badge></td>
                            <td class="px-4 py-3 text-sm">
                                <x-badge :color="$user->password_set_at ? 'green' : 'amber'">
                                    {{ $user->password_set_at ? 'Set' : 'Invite pending' }}
                                </x-badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-sm font-medium text-[#778599]">No users yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal :show="$showForm" onClose="closeForm">
        <h2 class="mb-4 text-lg font-bold text-[#0f172a] dark:text-white">Add User</h2>

        @if ($availableEmployees->isEmpty())
            <p class="text-sm font-medium text-[#778599]">Every employee already has a login. Create an employee record first.</p>
            <div class="mt-4"><x-button type="button" variant="secondary" wire:click="closeForm">Close</x-button></div>
        @else
            <form wire:submit="save" class="space-y-4">
                <div>
                    <x-label>User ID</x-label>
                    <x-input type="text" value="{{ $userCode }}" disabled readonly class="cursor-not-allowed opacity-60" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">Assigned automatically.</p>
                </div>

                <div>
                    <x-label>Employee</x-label>
                    <x-select wire:model.live="employeeId">
                        <option value="">Select employee</option>
                        @foreach ($availableEmployees as $employee)
                            <option value="{{ $employee->id }}">
                                {{ $employee->employee_id }} — {{ $employee->fullName() ?: $employee->company_email }}
                            </option>
                        @endforeach
                    </x-select>
                    <p class="mt-1 text-xs font-medium text-[#778599]">Only employees without an existing login are listed.</p>
                    @error('employeeId') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Email Address</x-label>
                    <x-input wire:model="email" type="email" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">Filled from the employee's company email. The invitation goes here.</p>
                    @error('email') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Role</x-label>
                    <x-select wire:model="role">
                        @foreach ($roles as $roleName)
                            <option value="{{ $roleName }}">{{ $roleName }}</option>
                        @endforeach
                    </x-select>
                    @error('role') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2 pt-2">
                    <x-button type="submit">Submit</x-button>
                    <x-button type="button" variant="secondary" wire:click="closeForm">Cancel</x-button>
                </div>
            </form>
        @endif
    </x-modal>

    <x-modal :show="$showConfirmation" onClose="acknowledge" maxWidth="sm">
        <div class="text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300">
                <x-icon name="check" class="h-6 w-6" />
            </div>
            <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">User Registered</h2>
            <p class="mt-2 text-sm font-medium text-[#778599] dark:text-neutral-400">{{ $confirmationMessage }}</p>
            <div class="mt-5">
                <x-button type="button" wire:click="acknowledge">OK</x-button>
            </div>
        </div>
    </x-modal>
</div>
