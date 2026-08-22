<?php

use App\Livewire\Concerns\WithTablePagination;
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
    use WithTablePagination;

    public bool $showForm = false;
    public bool $showConfirmation = false;
    public bool $showAccess = false;
    public string $confirmationMessage = '';

    public ?int $employeeId = null;
    public string $email = '';
    public string $role = 'Employee';

    #[Locked]
    public ?int $accessUserId = null;
    public string $accessRole = 'Employee';
    /** @var list<string> */
    public array $accessGrants = [];
    public bool $accessSuperAdmin = false;

    // System-assigned. Locked so a crafted request cannot swap it for a
    // chosen value — disabling the input only stops honest editing.
    #[Locked]
    public string $userCode = '';

    public string $search = '';
    public ?string $statusMessage = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @param list<int|string> $ids */
    public function editSelected(array $ids): void
    {
        if (count($ids) === 1) {
            $this->manageAccess((int) $ids[0]);
        }
    }

    /** @param list<int|string> $ids */
    public function setSelectedAccess(array $ids, bool $active): void
    {
        $users = User::whereKey(array_map('intval', $ids))->get();
        $changed = 0;
        $protected = 0;

        foreach ($users as $user) {
            $lastActiveAdmin = $user->is_super_admin
                && User::where('is_super_admin', true)->where('is_active', true)->count() <= 1;

            if (! $active && ($user->is(auth()->user()) || $lastActiveAdmin)) {
                $protected++;
                continue;
            }

            if ((bool) $user->is_active !== $active) {
                $user->update(['is_active' => $active]);
                $changed++;
            }
        }

        $action = $active ? 'enabled' : 'disabled';
        $this->statusMessage = $changed > 0
            ? "{$changed} user " . Str::plural('account', $changed) . " {$action}."
            : 'No account access was changed.';

        if ($protected > 0) {
            $this->statusMessage .= ' Your own account and the last active full administrator are protected.';
        }
    }

    /** @param list<int|string> $ids */
    public function removeSelected(array $ids): void
    {
        $users = User::whereKey(array_map('intval', $ids))->get();
        $removed = 0;
        $protected = 0;

        DB::transaction(function () use ($users, &$removed, &$protected): void {
            foreach ($users as $user) {
                $lastAdmin = $user->is_super_admin && User::where('is_super_admin', true)->count() <= 1;

                if ($user->is(auth()->user()) || $lastAdmin) {
                    $protected++;
                    continue;
                }

                $user->delete();
                $removed++;
            }
        });

        $this->statusMessage = $removed > 0
            ? "{$removed} user " . Str::plural('account', $removed) . ' removed. The employee record was kept.'
            : 'No user account was removed.';

        if ($protected > 0) {
            $this->statusMessage .= ' Your own account and the last full administrator are protected.';
        }

        $this->resetPage();
    }

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
        Mail::to($user->email)->queue(new AccountInviteMail($employee, $url));

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

    // -----------------------------------------------------------------
    // Access
    //
    // A user's access is the sum of their tier, the position they hold and
    // anything granted here on top. Position-derived permissions are shown
    // but not editable from this screen — they belong to the job, and
    // changing them here would silently change every other holder.
    // -----------------------------------------------------------------

    public function manageAccess(int $id): void
    {
        $user = User::with(['permissions', 'employee.position.permissions'])->findOrFail($id);

        $this->accessUserId = $user->id;
        $this->accessRole = $user->getRoleNames()->first() ?? 'Employee';
        $this->accessGrants = $user->directPermissionNames()->all();
        $this->accessSuperAdmin = (bool) $user->is_super_admin;
        $this->resetValidation();
        $this->showAccess = true;
    }

    public function saveAccess(): void
    {
        $data = $this->validate([
            'accessRole' => ['required', 'in:Admin,Employee'],
            'accessGrants' => ['array'],
            'accessGrants.*' => ['string', 'exists:permissions,name'],
        ]);

        $user = User::findOrFail($this->accessUserId);

        $superAdmin = $this->accessSuperAdmin && $data['accessRole'] === 'Admin';

        // Without this the last administrator can be demoted through the UI and
        // nobody is left able to restore anyone's access.
        if ($user->is_super_admin && ! $superAdmin && User::where('is_super_admin', true)->count() <= 1) {
            $this->addError('accessSuperAdmin', 'This is the only full administrator. Promote someone else first.');

            return;
        }

        $user->syncRoles([$data['accessRole']]);
        $user->syncPermissions($data['accessRole'] === 'Admin' ? ($data['accessGrants'] ?? []) : []);
        $user->update(['is_super_admin' => $superAdmin]);

        $this->closeAccess();
        $this->confirmationMessage = "Access updated for {$user->name}.";
        $this->showConfirmation = true;
    }

    public function closeAccess(): void
    {
        $this->reset(['accessUserId', 'accessRole', 'accessGrants', 'accessSuperAdmin']);
        $this->resetValidation();
        $this->showAccess = false;
    }

    public function with(): array
    {
        $accessUser = $this->accessUserId
            ? User::with(['employee.position.permissions'])->find($this->accessUserId)
            : null;

        return [
            'accessUser' => $accessUser,
            'positionGrants' => $accessUser?->positionPermissionNames() ?? collect(),
            'permissionGroups' => config('permissions.groups'),
            'users' => User::with(['employee.position', 'roles', 'permissions'])
                ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('user_code', 'like', "%{$this->search}%")))
                ->orderBy('name')
                ->paginate($this->perPage()),
            'totalUsers' => User::count(),
            'activeUsers' => User::where('is_active', true)->count(),
            'disabledUsers' => User::where('is_active', false)->count(),
            'pendingInvites' => User::whereNull('password_set_at')->count(),
            // Only employees who don't already have credentials can be picked.
            'availableEmployees' => Employee::whereNull('user_id')->orderBy('employee_id')->get(),
            'roles' => Role::orderBy('name')->pluck('name'),
        ];
    }

    /** Summary shown in the directory, so access is legible without opening each user. */
    public function accessSummary(User $user): string
    {
        if (! $user->isAdminTier()) {
            return 'Self-service only';
        }

        if ($user->is_super_admin) {
            return 'Full administrator';
        }

        $count = $user->effectivePermissionNames()->count();

        return $count === 0
            ? 'Admin tier, nothing granted'
            : $count . ' ' . Str::plural('permission', $count);
    }
};
?>

<div class="space-y-7" x-data="{ selected: [] }">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-3xl font-bold tracking-tight text-ink-950 dark:text-white">Users</h2>
            <p class="mt-1 text-base font-medium text-ink-600 dark:text-ink-300">Manage HRIS login accounts, access tiers, and account availability.</p>
        </div>
        <x-button wire:click="create" class="h-10 px-4">
            <x-icon name="plus" class="h-4 w-4" />
            Add User
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm font-semibold text-brand-800 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-300">
            {{ $statusMessage }}
        </div>
    @endif

    <div class="flex flex-wrap gap-4">
        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Total Users</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $totalUsers }}</p>
            <p class="mt-1 text-sm font-semibold text-brand-700 dark:text-brand-300">Registered accounts</p>
        </div>
        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Enabled Access</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $activeUsers }}</p>
            <p class="mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300">Can sign in</p>
        </div>
        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Disabled Access</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $disabledUsers }}</p>
            <p class="mt-1 text-sm font-semibold text-red-600 dark:text-red-300">Sign-in suspended</p>
        </div>
        <div class="professional-panel w-full rounded-lg px-4 py-3 sm:w-56">
            <p class="text-sm font-medium text-ink-600 dark:text-ink-300">Pending Invitations</p>
            <p class="mt-2 text-3xl font-bold text-ink-950 dark:text-white">{{ $pendingInvites }}</p>
            <p class="mt-1 text-sm font-semibold text-amber-600 dark:text-amber-300">Password not set</p>
        </div>
    </div>

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="flex flex-col gap-4 border-b border-ink-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
            <div>
                <h3 class="text-xl font-bold text-ink-950 dark:text-white">User Directory</h3>
                <p class="mt-1 h-4 text-sm font-semibold text-amber-600 dark:text-amber-300" x-text="selected.length ? selected.length + ' selected' : ''"></p>
            </div>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center">
                <div class="flex items-center gap-2">
                    <button type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/5 dark:text-ink-300"
                        :disabled="selected.length !== 1" @click="if (selected.length === 1) $wire.editSelected(selected)"
                        title="Edit selected user's access">
                        <x-icon name="pencil" class="h-4 w-4" />
                    </button>
                    <button type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 shadow-sm transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
                        :disabled="selected.length === 0" @click="if (selected.length) { $wire.setSelectedAccess(selected, true); selected = []; }"
                        title="Enable selected account access">
                        <x-icon name="shield-check" class="h-4 w-4" />
                    </button>
                    <button type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 text-amber-700 shadow-sm transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                        :disabled="selected.length === 0"
                        @click="if (selected.length && confirm('Disable access for the selected user account(s)?')) { $wire.setSelectedAccess(selected, false); selected = []; }"
                        title="Disable selected account access">
                        <x-icon name="user-minus" class="h-4 w-4" />
                    </button>
                    <button type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 shadow-sm transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300"
                        :disabled="selected.length === 0"
                        @click="if (selected.length && confirm('Remove the selected HRIS user account(s)? Employee records will be kept.')) { $wire.removeSelected(selected); selected = []; }"
                        title="Remove selected user account">
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>
                <label class="relative block sm:w-80">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <x-input wire:model.live.debounce.250ms="search" @input="selected = []" placeholder="Search users..." class="h-10 pl-9" />
                </label>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-ink-200 dark:divide-white/10">
                <thead class="bg-ink-50/80 dark:bg-white/[0.03]">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input type="checkbox"
                                class="h-5 w-5 rounded border-ink-300 text-brand-700 focus:ring-brand-600 dark:border-white/20 dark:bg-ink-900"
                                @change="selected = $event.target.checked ? @js($users->getCollection()->pluck('id')->values()) : []"
                                :checked="selected.length === @js($users->count()) && @js($users->count()) > 0">
                        </th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">User ID</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Email</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Position</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Tier</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Permissions</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Account</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Password</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100 bg-white dark:divide-white/10 dark:bg-ink-900/40">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}"
                            class="cursor-pointer transition hover:bg-brand-50/50 dark:hover:bg-white/[0.03]"
                            :class="selected.includes({{ $user->id }}) ? 'bg-brand-50/60 dark:bg-brand-500/10' : ''"
                            @click="selected.includes({{ $user->id }}) ? selected = selected.filter((id) => id !== {{ $user->id }}) : selected.push({{ $user->id }})">
                            <td class="px-6 py-4" @click.stop>
                                <input type="checkbox" value="{{ $user->id }}" x-model.number="selected"
                                    class="h-5 w-5 rounded border-ink-300 text-brand-700 focus:ring-brand-600 dark:border-white/20 dark:bg-ink-900">
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-bold text-ink-800 dark:text-ink-100">{{ $user->user_code }}</td>
                            <td class="min-w-52 px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <x-avatar :employee="$user->employee" size="md" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-brand-800 dark:text-brand-300">{{ $user->name }}</p>
                                        <p class="mt-1 text-xs font-medium text-ink-500 dark:text-ink-400">{{ $user->employee?->employee_id ?? 'No employee linked' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="max-w-64 truncate px-4 py-4 text-sm font-medium text-ink-600 dark:text-ink-300">{{ $user->email }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-ink-700 dark:text-ink-200">{{ $user->employee?->position?->title ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-4"><x-badge :color="$user->isAdminTier() ? 'brand' : 'neutral'">{{ $user->getRoleNames()->join(', ') ?: 'No role' }}</x-badge></td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-ink-600 dark:text-ink-300">{{ $this->accessSummary($user) }}</td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? 'Enabled' : 'Disabled' }}</x-badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$user->password_set_at ? 'green' : 'amber'">{{ $user->password_set_at ? 'Set' : 'Invite pending' }}</x-badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="users" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No users found</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Add a user account or adjust your search.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                {{ $users->links('components.pagination', ['noun' => 'users']) }}
            </div>
        @endif
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
                    <x-label>Access Tier</x-label>
                    <x-select wire:model="role">
                        @foreach ($roles as $roleName)
                            <option value="{{ $roleName }}">{{ $roleName }}</option>
                        @endforeach
                    </x-select>
                    <p class="mt-1 text-xs font-medium text-[#778599]">
                        <strong class="font-semibold">Employee</strong> — own profile, attendance, and filing leave, overtime and cash advance.
                        <strong class="font-semibold">Admin</strong> — the above, plus whatever their position grants.
                    </p>
                    @error('role') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2 pt-2">
                    <x-button type="submit">Submit</x-button>
                    <x-button type="button" variant="secondary" wire:click="closeForm">Cancel</x-button>
                </div>
            </form>
        @endif
    </x-modal>

    <x-modal :show="$showAccess" onClose="closeAccess" maxWidth="lg">
        @if ($accessUser)
            <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Access — {{ $accessUser->name }}</h2>
            <p class="mb-5 text-sm font-medium text-[#778599]">
                {{ $accessUser->employee?->position?->title ?? 'No position assigned' }}
                @if ($accessUser->employee) &middot; {{ $accessUser->employee->employee_id }} @endif
            </p>

            <div class="space-y-5">
                <div>
                    <x-label>Access Tier</x-label>
                    <x-select wire:model.live="accessRole">
                        <option value="Employee">Employee — self-service only</option>
                        <option value="Admin">Admin — may hold the permissions below</option>
                    </x-select>
                    @error('accessRole') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($accessRole === 'Employee')
                    <div class="rounded-lg bg-[#f8fafc] p-3 text-sm font-medium text-[#65758c] dark:bg-neutral-800/50 dark:text-neutral-300">
                        On the Employee tier this account sees only its own profile, attendance and filings —
                        even while holding the {{ $accessUser->employee?->position?->title ?? 'assigned' }} position.
                    </div>
                @else
                    <div>
                        <label class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                            <input type="checkbox" wire:model.live="accessSuperAdmin"
                                   class="mt-0.5 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                            <span>
                                Full administrator
                                <span class="block text-xs font-normal">Passes every permission check, present and future. Keep this to one or two people.</span>
                            </span>
                        </label>
                        @error('accessSuperAdmin') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @unless ($accessSuperAdmin)
                        @if ($positionGrants->isNotEmpty())
                            <div class="rounded-lg bg-[#f8fafc] p-3 dark:bg-neutral-800/50">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">
                                    From the {{ $accessUser->employee?->position?->title }} position
                                </p>
                                <p class="mt-1 text-xs font-medium text-[#778599]">
                                    Already held, and shared by everyone in this position. Change these on the Positions page.
                                </p>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($permissionGroups as $items)
                                        @foreach ($items as $name => $label)
                                            @if ($positionGrants->contains($name))
                                                <li class="flex items-start gap-2 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                                                    <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> {{ $label }}
                                                </li>
                                            @endif
                                        @endforeach
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div>
                            <x-label>Grant on top of the position</x-label>
                            <p class="mt-1 text-xs font-medium text-[#778599]">Extra access for this person only. Does not affect anyone else in their position.</p>

                            <div class="mt-3 space-y-5">
                                @foreach ($permissionGroups as $group => $items)
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">{{ $group }}</p>
                                        <div class="mt-2 space-y-1.5">
                                            @foreach ($items as $name => $label)
                                                @continue($positionGrants->contains($name))
                                                <label class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 text-sm font-medium text-[#65758c] transition hover:bg-[#f8fafc] dark:text-neutral-300 dark:hover:bg-white/5">
                                                    <input type="checkbox" wire:model="accessGrants" value="{{ $name }}"
                                                           class="mt-0.5 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @error('accessGrants.*') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endunless
                @endif

                <div class="flex gap-2 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                    <x-button wire:click="saveAccess">Save Access</x-button>
                    <x-button variant="secondary" wire:click="closeAccess">Cancel</x-button>
                </div>
            </div>
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
