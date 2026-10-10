<?php

use App\Models\FinalPay;
use App\Services\Payroll\FinalPayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One leaver's settlement, laid out as the payslip it stands in for.
 *
 * The same shape as a payroll run: the figures are worked out, locked, then
 * sent. The difference is where it goes — their personal address, because the
 * company mailbox and the PHREMS login are both gone by the time it is paid.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public int $finalPayId;

    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public string $releaseDate = '';
    public string $otherAmount = '';
    public string $otherLabel = '';

    public function mount(FinalPay $finalPay): void
    {
        abort_unless(Auth::user()->can('payroll.final_pay.manage'), 403, 'You cannot settle final pay.');

        $this->finalPayId = $finalPay->id;
        $this->releaseDate = now()->toDateString();
        $this->otherAmount = (string) (float) $finalPay->other_deduction;
        $this->otherLabel = (string) $finalPay->other_deduction_label;
    }

    protected function finalPay(): FinalPay
    {
        return FinalPay::with(['employee', 'clearedBy'])->findOrFail($this->finalPayId);
    }

    /** Every refusal lands on the screen rather than an error page. */
    protected function attempt(callable $action): void
    {
        abort_unless(Auth::user()->can('payroll.final_pay.manage'), 403, 'You cannot settle final pay.');

        $this->statusMessage = null;
        $this->errorMessage = null;

        try {
            $action();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function recalculate(FinalPayService $service): void
    {
        $this->attempt(function () use ($service) {
            $service->recalculate($this->finalPay());
            $this->statusMessage = 'Brought up to date with the payroll runs finalized since.';
        });
    }

    public function saveDeduction(FinalPayService $service): void
    {
        $data = $this->validate([
            'otherAmount' => ['required', 'numeric', 'min:0'],
            'otherLabel' => ['nullable', 'string', 'max:120', 'required_unless:otherAmount,0'],
        ], ['otherLabel.required_unless' => 'Say what the deduction is for.']);

        $this->attempt(function () use ($data, $service) {
            $service->setOtherDeduction($this->finalPay(), (float) $data['otherAmount'], $data['otherLabel'] ?: null);
            $this->statusMessage = 'Deduction saved.';
        });
    }

    /** Clearance signed off. Locks the figures, the way finalizing a run does. */
    public function finalize(FinalPayService $service): void
    {
        $this->attempt(function () use ($service) {
            $service->clear($this->finalPay(), Auth::user());
            $this->statusMessage = 'Figures locked. Send it when the money is released.';
        });
    }

    public function reopen(FinalPayService $service): void
    {
        $this->attempt(function () use ($service) {
            $service->hold($this->finalPay());
            $this->statusMessage = 'Reopened. The figures can be changed again.';
        });
    }

    /** Tells them what they are owed. No money moves. */
    public function send(FinalPayService $service): void
    {
        $this->attempt(function () use ($service) {
            $this->statusMessage = $service->emailStatement($this->finalPay())['message']
                . ' Mark it paid when the money goes out.';
        });
    }

    /** The money has gone out, usually on the 15th or the 30th. */
    public function markPaid(FinalPayService $service): void
    {
        $this->attempt(function () use ($service) {
            $final = $service->release($this->finalPay(), $this->releaseDate ?: now());

            $this->statusMessage = 'Marked as paid on ' . $final->released_on->format('M j, Y') . '.';
        });
    }

    public function with(): array
    {
        $final = $this->finalPay();

        return [
            'final' => $final,
            'employee' => $final->employee,
            'locked' => $final->status !== FinalPay::HELD,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('payroll.final-pay') }}" wire:navigate class="text-sm font-medium text-[#778599] hover:text-[#65758c]">&larr; All final pay</a>
            <h1 class="mt-1 text-xl font-bold text-[#0f172a] dark:text-white">{{ $employee?->fullName() ?: 'Final pay' }}</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                {{ $employee?->employee_id }} · last day {{ $final->separation_date->format('M j, Y') }}
                @if ($final->unpaid_from)
                    · days from {{ $final->unpaid_from->format('M j') }}
                @endif
            </p>
        </div>
        <x-badge :color="$final->statusColor()">{{ $final->statusLabel() }}</x-badge>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    <x-card>
        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            @foreach ([
                'Days worked' => rtrim(rtrim(number_format((float) $final->unpaid_days, 2), '0'), '.'),
                'Basic earned this year' => '₱' . number_format((float) $final->basic_earned, 2),
                'Expected release' => $final->released_on
                    ? $final->released_on->format('M j, Y')
                    : ($final->expected_release_on?->format('M j, Y') ?? '—'),
                'Statement sent' => $final->emailed_at?->format('M j, Y') ?? 'Not yet',
            ] as $label => $value)
                <div>
                    <p class="text-xs font-medium text-[#778599]">{{ $label }}</p>
                    <p class="mt-1 text-lg font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </x-card>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Owed to them</h2>
            </div>
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ([
                    ['Pay for last days worked', $final->unpaid_salary, $final->unpaid_from ? $final->unpaid_from->format('M j') . ' to ' . $final->separation_date->format('M j, Y') : null],
                    ['Night differential', $final->unpaid_night_differential, null],
                    ['Overtime', $final->unpaid_overtime, null],
                    ['13th month pay for ' . $final->for_year, $final->thirteenth_month, '₱' . number_format((float) $final->basic_earned, 2) . ' ÷ 12'],
                ] as [$label, $amount, $note])
                    @continue((float) $amount <= 0 && $label !== 'Pay for last days worked')
                    <div class="flex items-start justify-between gap-3 px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $label }}</p>
                            @if ($note)
                                <p class="text-xs font-medium text-[#778599]">{{ $note }}</p>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $amount, 2) }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Owed to us</h2>
            </div>
            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @forelse (array_values(array_filter([
                    (float) $final->cash_advance_balance > 0 ? ['Cash advance still owed', $final->cash_advance_balance] : null,
                    (float) $final->commission_advance_balance > 0 ? ['Commission advance still owed', $final->commission_advance_balance] : null,
                    (float) $final->other_deduction > 0 ? [$final->other_deduction_label ?: 'Other deduction', $final->other_deduction] : null,
                ])) as [$label, $amount])
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <p class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $label }}</p>
                        <p class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $amount, 2) }}</p>
                    </div>
                @empty
                    <div class="px-5 py-6 text-center text-sm font-medium text-[#778599]">Nothing deducted.</div>
                @endforelse
            </div>

            @unless ($locked)
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-label>Anything else still owed?</x-label>
                            <x-input wire:model="otherLabel" type="text" maxlength="120" placeholder="e.g. Laptop not returned" />
                            @error('otherLabel') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-label>Amount</x-label>
                            <x-input wire:model="otherAmount" type="number" step="0.01" />
                            @error('otherAmount') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mt-3">
                        <x-button wire:click="saveDeduction" variant="secondary">Save deduction</x-button>
                    </div>
                </div>
            @endunless
        </x-card>
    </div>

    <x-card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-[#0f172a] dark:text-white">Net final pay</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">
                    ₱{{ number_format((float) $final->thirteenth_month + $final->unpaidTotal(), 2) }} owed less ₱{{ number_format($final->totalDeductions(), 2) }} deducted.
                </p>
            </div>
            <p class="text-3xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format((float) $final->net_amount, 2) }}</p>
        </div>

        <div class="mt-5 flex flex-wrap items-end gap-2 border-t border-neutral-100 pt-5 dark:border-neutral-800">
            @if ($final->status === 'held')
                <x-button wire:click="recalculate" variant="secondary">Recalculate</x-button>
                <x-button wire:click="finalize" wire:confirm="Lock these figures? Clearance should be signed off first.">Finalize</x-button>
            @elseif ($final->status === 'cleared')
                {{-- Two steps, in this order: they read the figures, then the
                     money follows on the next payday. --}}
                <x-button wire:click="send"
                          wire:confirm="Email the statement to {{ $employee?->personal_email ?: 'their personal address' }}? No money moves yet."
                          :variant="$final->emailed_at ? 'secondary' : 'primary'">
                    <span wire:loading.remove wire:target="send">{{ $final->emailed_at ? 'Send statement again' : 'Send statement' }}</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </x-button>

                @if ($final->emailed_at)
                    <div>
                        <x-label>Paid on</x-label>
                        <x-input wire:model="releaseDate" type="date" class="w-44" />
                    </div>
                    <x-button wire:click="markPaid"
                              wire:confirm="Mark ₱{{ number_format((float) $final->net_amount, 2) }} as paid to {{ $employee?->fullName() }}?">
                        Mark as paid
                    </x-button>
                @endif

                <x-button wire:click="reopen" variant="secondary">Reopen</x-button>
            @elseif ($final->status === 'released')
                <x-button wire:click="send" variant="secondary" wire:confirm="Send the statement again?">Send again</x-button>
            @endif
        </div>

        @if (blank($employee?->personal_email))
            <p class="mt-3 text-xs font-semibold text-amber-600 dark:text-amber-400">
                No personal email on file, so nothing can be sent. Add one on their profile — their company address stops working when they leave.
            </p>
        @endif
    </x-card>
</div>
