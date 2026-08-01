<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public User $user;
    public string $password = '';
    public string $password_confirmation = '';
    public bool $activated = false;

    public function mount(User $user): void
    {
        abort_unless(request()->hasValidSignature(), 403);
        abort_if($user->password_set_at, 403, 'This account has already been activated.');

        $this->user = $user;
    }

    public function submit(): void
    {
        $this->validate([
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $this->user->update([
            'password' => Hash::make($this->password),
            'password_set_at' => now(),
        ]);

        session()->flash('status', 'Password set successfully. You can now log in.');

        $this->redirect(route('login'), navigate: true);
    }
};
?>

<div class="relative flex min-h-screen items-center justify-center px-4">
    <div class="absolute right-4 top-4">
        <x-theme-toggle />
    </div>

    <div class="w-full max-w-sm">
        <div class="mb-8 flex justify-center">
            <img src="{{ asset('images/logo.png') }}" alt="CreatiVision" class="h-12 w-auto object-contain">
        </div>

        <x-card class="p-8">
            @if ($activated)
                <h1 class="mb-2 text-lg font-semibold text-[#0f172a] dark:text-white">Account activated!</h1>
                <p class="mb-6 text-sm font-medium text-[#778599] dark:text-neutral-400">Your password has been set.</p>
                <x-button as="a" href="{{ route('login') }}" class="w-full">Go to Login</x-button>
            @else
                <h1 class="mb-1 text-xl font-semibold text-[#0f172a] dark:text-white">Set Your Password</h1>
                <p class="mb-6 text-sm font-medium text-[#778599] dark:text-neutral-400">Welcome, {{ $user->name }}. Choose a password to activate your account.</p>

                <form wire:submit="submit" class="space-y-4">
                    <div>
                        <x-label>Password</x-label>
                        <x-input wire:model="password" type="password" />
                        @error('password') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Confirm Password</x-label>
                        <x-input wire:model="password_confirmation" type="password" />
                    </div>
                    <x-button type="submit" class="w-full">Activate Account</x-button>
                </form>
            @endif
        </x-card>
    </div>
</div>