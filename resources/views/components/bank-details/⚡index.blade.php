<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\BankDetailRequest;
use App\Services\BankDetailService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * HR's side of the bank detail flow.
 *
 * Approving here is what writes the new account onto the employee record.
 * Nothing before this point has moved where the salary goes, which is why the
 * page shows the old and new side by side rather than only the new.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public ?string $statusMessage = null;
    public string $search = '';

    #[Locked]
    public ?int $decidingId = null;
    public string $decisionNote = '';
    public bool $showDecision = false;

    public function updatedSearch(): void
    {
        $this->resetPage('history');
    }

    public function review(int $id): void
    {
        $this->decidingId = BankDetailRequest::pending()->findOrFail($id)->id;
        $this->decisionNote = '';
        $this->resetValidation();
        $this->showDecision = true;
    }

    public function closeDecision(): void
    {
        $this->reset(['decidingId', 'decisionNote']);
        $this->resetValidation();
        $this->showDecision = false;
    }

    public function decide(bool $approved, BankDetailService $service): void
    {
        if (! $approved) {
            $this->validate(
                ['decisionNote' => ['required', 'string', 'max:200']],
                ['decisionNote.required' => 'Tell the employee why this was declined.'],
            );
        }

        $request = $service->decide(
            BankDetailRequest::with('employee')->findOrFail($this->decidingId),
            Auth::user(),
            $approved,
            $this->decisionNote ?: null,
        );

        $name = $request->employee->fullName() ?: $request->employee->employee_id;

        $this->statusMessage = $approved
            ? "Approved. {$name}'s salary now goes to {$request->bank_name} {$request->maskedAccount()}."
            : 'Declined. The account on file is unchanged.';

        $this->closeDecision();
    }

    public function with(): array
    {
        return [
            'queue' => BankDetailRequest::with('employee')->pending()->oldest()
                ->paginate($this->perPage(), pageName: 'queue'),
            'history' => BankDetailRequest::with(['employee', 'decidedBy'])
                ->whereIn('status', ['approved', 'declined', 'cancelled'])
                ->when($this->search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e
                    ->where('first_name', 'like', "%{$this->search}%")
                    ->orWhere('last_name', 'like', "%{$this->search}%")
                    ->orWhere('employee_id', 'like', "%{$this->search}%")))
                ->latest()
                ->paginate($this->perPage(), pageName: 'history'),
            'deciding' => $this->decidingId
                ? BankDetailRequest::with('employee')->find($this->decidingId)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Bank Details</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            Changes to where an employee's salary is paid. Check each one with the employee before approving.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($queue->total() > 0)
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Awaiting Your Approval</h2>
                <p class="mt-0.5 text-xs font-medium text-[#778599]">
                    Confirm with the employee in person or by phone. A request on its own is not proof it came from them.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">From</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">To</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Reason</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Filed</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($queue as $request)
                            <tr wire:key="queue-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                    {{ $request->employee->fullName() ?: $request->employee->employee_id }}
                                    <span class="block text-xs font-medium text-[#778599]">{{ $request->employee->employee_id }}</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">
                                    {{ $request->previous_bank_name ?: '—' }}
                                    <span class="block text-xs tabular-nums">{{ $request->maskedPreviousAccount() }}</span>
                                </td>
                                <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">
                                    {{ $request->bank_name }}
                                    <span class="block text-xs font-medium tabular-nums text-[#778599]">{{ $request->maskedAccount() }}</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $request->reason ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599]">{{ $request->created_at->format('M j, Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button wire:click="review({{ $request->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Review</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($queue->hasPages())
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    {{ $queue->links('components.pagination', ['noun' => 'changes waiting']) }}
                </div>
            @endif
        </x-card>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Decided</h2>

            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employees..."
                       class="h-10 w-64 rounded-xl border border-ink-200 bg-white py-2 pl-9 pr-4 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-500 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Changed to</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Decided by</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($history as $request)
                        <tr wire:key="hist-{{ $request->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $request->employee->fullName() ?: $request->employee->employee_id }}
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">
                                {{ $request->bank_name }}
                                <span class="text-xs tabular-nums">{{ $request->maskedAccount() }}</span>
                            </td>
                            <td class="px-4 py-3"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $request->decidedBy?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599]">
                                {{ ($request->decided_at ?? $request->updated_at)->format('M j, Y') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center font-medium text-[#778599]">Nothing decided yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($history->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $history->links('components.pagination', ['noun' => 'decided requests']) }}
            </div>
        @endif
    </x-card>

    <x-modal :show="$showDecision" onClose="closeDecision" maxWidth="lg">
        @if ($deciding)
            <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
                {{ $deciding->employee->fullName() ?: $deciding->employee->employee_id }}
            </h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">
                Filed {{ $deciding->created_at->format('F j, Y') }}.
            </p>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-800">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#778599]">Currently paid to</p>
                    <p class="mt-1 text-sm font-semibold text-[#65758c] dark:text-neutral-300">{{ $deciding->previous_bank_name ?: '—' }}</p>
                    <p class="text-sm font-medium tabular-nums text-[#778599]">{{ $deciding->previous_bank_account_name ?: '—' }}</p>
                    <p class="text-sm font-medium tabular-nums text-[#778599]">{{ $deciding->maskedPreviousAccount() }}</p>
                </div>

                <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-500/20 dark:bg-brand-500/10">
                    <p class="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-300">Wants it paid to</p>
                    <p class="mt-1 text-sm font-bold text-[#0f172a] dark:text-white">{{ $deciding->bank_name }}</p>
                    <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $deciding->bank_account_name }}</p>
                    {{-- The whole number, only here. HR has to read it back to
                         the employee to confirm it, and cannot do that from the
                         masked version shown everywhere else. --}}
                    <p class="text-sm font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $deciding->bank_account_number }}</p>
                </div>
            </div>

            @if ($deciding->reason)
                <div class="mt-4 rounded-xl bg-[#f8fafc] p-4 dark:bg-neutral-800/50">
                    <p class="text-xs font-bold uppercase tracking-wide text-[#778599]">Their reason</p>
                    <p class="mt-1 text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $deciding->reason }}</p>
                </div>
            @endif

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                Confirm with {{ $deciding->employee->first_name ?: 'the employee' }} directly before approving. Approving sends every future
                salary to this account.
            </div>

            <div class="mt-4">
                <x-label>Note (required to decline)</x-label>
                <x-textarea wire:model="decisionNote" rows="2" placeholder="e.g. Confirmed by phone on Aug 16" />
                @error('decisionNote') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <x-button wire:click="decide(true)" wire:confirm="Approve this change? Future salary goes to the new account.">Approve</x-button>
                <x-button wire:click="decide(false)" variant="secondary">Decline</x-button>
                <x-button wire:click="closeDecision" variant="secondary">Cancel</x-button>
            </div>
        @endif
    </x-modal>
</div>
