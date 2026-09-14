<?php

use App\Models\User;
use App\Services\Auth\PasswordResetLink;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Choosing a new password from a link HR sent.
 *
 * Separate from set-password, which activates a brand new account and refuses
 * anybody who already has one. The two read differently to the person in front
 * of them — "welcome, set up your account" against "choose a new password" —
 * and they guard different things.
 *
 * Nobody at the company ever sees what is typed here. HR starts the reset and
 * the employee finishes it, which is what keeps an account provably theirs when
 * the same login approves payroll.
 */
new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public int $userId;

    public string $employeeName = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(User $user): void
    {
        /*
         * The signature itself is enforced by the 'signed' middleware on the
         * route, not repeated here — and mount only ever runs on that first
         * request. Everything afterwards arrives at Livewire's own endpoint and
         * hydrates from the snapshot, which is why the user is #[Locked]: it is
         * the only thing standing between a crafted update and a reset aimed at
         * somebody else's account.
         */

        /*
         * The signature alone only says the app issued this and it has not
         * expired. The fingerprint says it was issued against the password the
         * account still has — so a link stops working the moment a newer one is
         * used, and an old email cannot be dug out weeks later.
         */
        abort_unless(
            PasswordResetLink::matches($user, request()->query('fp')),
            403,
            'This reset link has already been used, or a newer one was sent. Ask HR for another.',
        );

        // A disabled account cannot sign in even with a fresh password, so
        // letting somebody set one would only waste their time.
        abort_unless($user->is_active, 403, 'Your PHREMS access is currently disabled. Please contact Human Resources.');

        $this->userId = $user->id;
        $this->employeeName = $user->employee?->first_name ?: $user->name;
    }

    public function submit(): void
    {
        $this->validate([
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $user = User::findOrFail($this->userId);

        $user->update([
            'password' => Hash::make($this->password),
            // Stamped even though it is already set: it is what the fingerprint
            // is built from, so refreshing it retires every link issued before
            // this one, including this one.
            'password_set_at' => now(),
        ]);

        session()->flash('status', 'Password changed. You can now sign in with your new password.');

        $this->redirect(route('login'), navigate: true);
    }
};
?>

<div class="relative flex min-h-screen items-center justify-center px-4">
    <div class="absolute right-4 top-4">
        <x-theme-toggle />
    </div>

    <div class="w-full max-w-md">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/logo.png') }}" alt="CreatiVision" class="mx-auto h-auto w-44 object-contain">
        </div>

        <div class="professional-panel p-6 sm:p-8">
            <h1 class="text-xl font-bold text-ink-950 dark:text-white">Choose a new password</h1>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">
                Hi {{ $employeeName }} — pick something only you know. Nobody at CreatiVision can see it.
            </p>

            <form wire:submit="submit" class="mt-6 space-y-4">
                <div>
                    <x-label>New password</x-label>
                    <x-input wire:model="password" type="password" autocomplete="new-password" autofocus />
                    @error('password') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Confirm new password</x-label>
                    <x-input wire:model="password_confirmation" type="password" autocomplete="new-password" />
                </div>

                <p class="text-xs font-medium text-ink-500 dark:text-ink-400">
                    At least 8 characters. Your old password keeps working until you save this one.
                </p>

                <x-button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Save New Password</span>
                    <span wire:loading wire:target="submit">Saving…</span>
                </x-button>
            </form>
        </div>
    </div>
</div>
