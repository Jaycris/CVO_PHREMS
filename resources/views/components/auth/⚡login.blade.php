<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $this->remember)) {
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        request()->session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

<div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-ink-950 px-4 py-10">
    <div class="absolute inset-0"
         style="background: radial-gradient(ellipse 52% 48% at 12% 8%, rgba(21,122,82,0.42), transparent 62%), radial-gradient(ellipse 46% 42% at 88% 28%, rgba(37,99,235,0.18), transparent 62%), linear-gradient(135deg, #020617 0%, #0f172a 52%, #052e23 100%);">
    </div>

    <img src="{{ asset('images/logo-mark.png') }}" alt=""
         class="pointer-events-none absolute -bottom-20 -left-16 h-[28rem] w-[28rem] object-contain opacity-[0.08]">

    <div class="relative z-10 w-full max-w-4xl overflow-hidden rounded-lg border border-white/10 bg-white shadow-2xl shadow-black/30 dark:bg-ink-900">
        <div class="flex flex-col md:flex-row">
            <div class="relative hidden w-full overflow-hidden bg-ink-950 p-10 md:flex md:w-5/12 md:flex-col md:justify-between">
                <div class="pointer-events-none absolute inset-0 overflow-hidden">
                    <div class="absolute inset-0" style="background: radial-gradient(circle at 20% 20%, rgba(35,155,104,.42), transparent 18rem);"></div>
                    <div class="absolute bottom-0 left-0 right-0 h-px bg-white/10"></div>
                </div>

                <div class="relative z-10">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-200">CreatiVision HRIS</p>
                    <h1 class="mt-4 text-3xl font-bold leading-tight text-white">Professional HR operations for your BPO team.</h1>
                    <p class="mt-4 text-sm leading-6 text-ink-300">
                        Manage attendance, leave, employee records, and payroll readiness from one secure workspace.
                    </p>
                </div>

                <div class="relative z-10 rounded-lg border border-white/10 bg-white/10 p-4 text-sm text-ink-300 backdrop-blur">
                    Built for Philippine HR workflows, approvals, and night-shift operations.
                </div>
            </div>

            <div class="w-full bg-[#f8fafc] p-8 dark:bg-ink-900 sm:p-12 md:w-7/12">
                <div class="mx-auto max-w-sm">
                    <div class="mb-6 flex justify-center md:justify-start">
                        <img src="{{ asset('images/logo.png') }}" alt="CreatiVision" class="w-full max-w-[22rem] object-contain">
                    </div>

                    <h2 class="mb-1 text-center text-2xl font-bold text-ink-950 dark:text-white md:text-left">Welcome back</h2>
                    <p class="mb-8 text-center text-sm font-medium text-ink-500 dark:text-ink-400 md:text-left">Sign in to continue to the HRIS workspace.</p>

                    <form wire:submit="login" class="space-y-6">
                        <div>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 font-medium text-ink-400">
                                    <x-icon name="user-circle" class="h-5 w-5" />
                                </span>
                                <input wire:model="email" id="email" type="email" autofocus placeholder="Email address"
                                    class="block w-full rounded-lg border border-ink-300 bg-white py-3 pl-11 pr-4 text-sm font-medium text-ink-800 shadow-md shadow-ink-200/70 placeholder:text-ink-400 focus:border-brand-600 focus:bg-white focus:ring-2 focus:ring-brand-600/20 dark:border-white/10 dark:bg-ink-800 dark:text-white dark:shadow-black/20 dark:focus:bg-ink-800">
                            </div>
                            <div class="mt-1.5 min-h-[1.25rem] pl-4">
                                @error('email') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 font-medium text-ink-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                </span>
                                <input wire:model="password" id="password" type="password" placeholder="Password"
                                    class="block w-full rounded-lg border border-ink-300 bg-white py-3 pl-11 pr-4 text-sm font-medium text-ink-800 shadow-md shadow-ink-200/70 placeholder:text-ink-400 focus:border-brand-600 focus:bg-white focus:ring-2 focus:ring-brand-600/20 dark:border-white/10 dark:bg-ink-800 dark:text-white dark:shadow-black/20 dark:focus:bg-ink-800">
                            </div>
                            <div class="mt-1.5 min-h-[1.25rem] pl-4">
                                @error('password') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <label class="flex items-center gap-2 pl-2 text-sm font-medium text-ink-500 dark:text-ink-400">
                            <input wire:model="remember" type="checkbox" class="rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-800">
                            Remember me
                        </label>

                        <button type="submit" class="w-full rounded-lg bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-900/20 transition hover:bg-brand-800">
                            Login
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
