<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Employee;
use App\Models\FinalPay;
use App\Services\Payroll\FinalPayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settling up with somebody who has left.
 *
 * Their thirteenth month, earned up to their last day, less anything they
 * still owe. Held until clearance is signed off, which is the company's rule.
 *
 * Their final salary is not here: the payroll run already paid the days they
 * worked in their last cutoff, pro-rated to the separation date.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public ?int $deductingId = null;
    public string $otherAmount = '';
    public string $otherLabel = '';
    public string $releaseDate = '';

    public function mount(): void
    {
        $this->releaseDate = now()->toDateString();
    }

    protected function guard(): void
    {
        abort_unless(Auth::user()->can('payroll.final_pay.manage'), 403, 'You cannot settle final pay.');
    }

    /** Everything the service refuses lands on the screen, not an error page. */
    protected function attempt(callable $action): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;

        try {
            $action();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function start(int $employeeId, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($employeeId, $service) {
            $final = $service->record(Employee::findOrFail($employeeId), Auth::user());

            $this->statusMessage = 'Settlement worked out and held for clearance — ₱'
                . number_format((float) $final->net_amount, 2) . '.';
        });
    }

    public function recalculate(int $id, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($id, $service) {
            $service->recalculate(FinalPay::findOrFail($id));
            $this->statusMessage = 'Brought up to date with the payroll runs finalized since.';
        });
    }

    public function clear(int $id, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($id, $service) {
            $service->clear(FinalPay::findOrFail($id), Auth::user());
            $this->statusMessage = 'Cleared. It can be released now.';
        });
    }

    public function hold(int $id, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($id, $service) {
            $service->hold(FinalPay::findOrFail($id));
            $this->statusMessage = 'Put back on hold.';
        });
    }

    public function release(int $id, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($id, $service) {
            $final = $service->release(FinalPay::findOrFail($id), $this->releaseDate ?: now());

            // The one thing they will chase if it never arrives, so it goes
            // with the release rather than waiting for somebody to remember.
            $sent = $service->emailStatement($final);

            $this->statusMessage = 'Released. ' . $sent['message'];
        });
    }

    public function emailStatement(int $id, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($id, $service) {
            $this->statusMessage = $service->emailStatement(FinalPay::findOrFail($id))['message'];
        });
    }

    public function cancel(int $id, FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($id, $service) {
            $service->cancel(FinalPay::findOrFail($id));
            $this->statusMessage = 'Settlement cancelled.';
        });
    }

    public function editDeduction(int $id): void
    {
        $this->guard();

        $final = FinalPay::findOrFail($id);

        $this->deductingId = $final->id;
        $this->otherAmount = (string) (float) $final->other_deduction;
        $this->otherLabel = (string) $final->other_deduction_label;
    }

    public function saveDeduction(FinalPayService $service): void
    {
        $this->guard();

        $data = $this->validate([
            'otherAmount' => ['required', 'numeric', 'min:0'],
            'otherLabel' => ['nullable', 'string', 'max:120', 'required_unless:otherAmount,0'],
        ], [
            'otherLabel.required_unless' => 'Say what the deduction is for.',
        ]);

        $this->attempt(function () use ($data, $service) {
            $service->setOtherDeduction(
                FinalPay::findOrFail($this->deductingId),
                (float) $data['otherAmount'],
                $data['otherLabel'] ?: null,
            );

            $this->reset(['deductingId', 'otherAmount', 'otherLabel']);
            $this->statusMessage = 'Deduction saved.';
        });
    }

    /**
     * Releases and sends everything clearance has signed off.
     *
     * The same button as a payroll run's Send Payslips, and the same rule:
     * only what is ready goes, so pressing it twice cannot pay anybody twice.
     */
    public function sendCleared(FinalPayService $service): void
    {
        $this->guard();

        $this->attempt(function () use ($service) {
            $sent = 0;
            $skipped = [];

            foreach (FinalPay::with('employee')->where('status', FinalPay::CLEARED)->get() as $final) {
                $released = $service->release($final, $this->releaseDate ?: now());
                $result = $service->emailStatement($released);

                $result['sent'] ? $sent++ : $skipped[] = $final->employee?->fullName();
            }

            $this->statusMessage = $sent . ' settlement(s) released and sent.'
                . ($skipped ? ' No personal email for ' . implode(', ', array_filter($skipped)) . '.' : '');
        });
    }

    public function with(FinalPayService $service): array
    {
        $open = FinalPay::with('employee')->whereIn('status', [FinalPay::HELD, FinalPay::CLEARED])->get();

        return [
            'awaiting' => $service->awaitingSettlement(),
            'heldCount' => $open->where('status', FinalPay::HELD)->count(),
            'clearedCount' => $open->where('status', FinalPay::CLEARED)->count(),
            'clearedTotal' => $open->where('status', FinalPay::CLEARED)->sum(fn (FinalPay $f) => (float) $f->net_amount),
            'openTotal' => $open->sum(fn (FinalPay $f) => (float) $f->net_amount),
            'settlements' => FinalPay::with(['employee', 'clearedBy'])
                ->orderByRaw("CASE status WHEN 'held' THEN 0 WHEN 'cleared' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700 dark:text-brand-400">Payroll</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-[#0f172a] dark:text-white">Final Pay</h1>
        <p class="mt-2 text-sm font-medium leading-6 text-[#65758c] dark:text-neutral-400">
            What somebody is owed after their last day: 13th month earned up to then, less anything they still owe. Held until clearance.
        </p>
    </div>

    @if ($statusMessage)
        <div class="flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-900/30 dark:text-emerald-300">
            <x-icon name="check" class="h-4 w-4 shrink-0" />
            {{ $statusMessage }}
        </div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 dark:border-red-400/20 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    {{-- The same strip a payroll run opens with: what is in front of you, and
         the buttons that move it on. --}}
    <x-card :padding="false">
        <div class="grid grid-cols-2 divide-x divide-y divide-neutral-100 sm:grid-cols-4 sm:divide-y-0 dark:divide-neutral-800">
            <div class="p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-[#65758c] dark:bg-white/5 dark:text-neutral-300">
                        <x-icon name="clock" class="h-5 w-5" />
                    </span>
                    <p class="text-xs font-bold uppercase tracking-wide text-[#65758c]">To calculate</p>
                </div>
                <p class="mt-4 text-2xl font-bold text-[#0f172a] tabular-nums dark:text-white">{{ $awaiting->count() }}</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">Awaiting final figures</p>
            </div>
            <div class="p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300">
                        <x-icon name="lock" class="h-5 w-5" />
                    </span>
                    <p class="text-xs font-bold uppercase tracking-wide text-[#65758c]">On hold</p>
                </div>
                <p class="mt-4 text-2xl font-bold text-[#0f172a] tabular-nums dark:text-white">{{ $heldCount }}</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">Waiting for clearance</p>
            </div>
            <div class="p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
                        <x-icon name="check" class="h-5 w-5" />
                    </span>
                    <p class="text-xs font-bold uppercase tracking-wide text-[#65758c]">Ready</p>
                </div>
                <p class="mt-4 text-2xl font-bold text-[#0f172a] tabular-nums dark:text-white">{{ $clearedCount }}</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">Cleared for release</p>
            </div>
            <div class="p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300">
                        <x-icon name="money" class="h-5 w-5" />
                    </span>
                    <p class="text-xs font-bold uppercase tracking-wide text-[#65758c]">Net to release</p>
                </div>
                <p class="mt-4 text-2xl font-bold text-brand-700 tabular-nums dark:text-brand-400">₱{{ number_format($openTotal, 2) }}</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">Across open settlements</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-neutral-100 bg-[#f8fafc] px-5 py-4 dark:border-neutral-800 dark:bg-white/[0.02]">
            @if ($clearedCount > 0)
                <div>
                    <p class="text-sm font-bold text-[#0f172a] dark:text-white">{{ $clearedCount }} settlement(s) ready for release</p>
                    <p class="mt-0.5 text-xs font-medium text-[#778599]">Statements will be emailed to each employee's personal address.</p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <x-label>Release date</x-label>
                        <x-input wire:model="releaseDate" type="date" />
                    </div>
                    <x-button wire:click="sendCleared"
                              wire:confirm="Release ₱{{ number_format($clearedTotal, 2) }} to {{ $clearedCount }} person/people and email each statement to their personal address?">
                        <span wire:loading.remove wire:target="sendCleared">Release &amp; Send Statements ({{ $clearedCount }})</span>
                        <span wire:loading wire:target="sendCleared">Sending…</span>
                    </x-button>
                </div>
            @elseif ($heldCount > 0)
                <div class="flex items-center gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300">
                        <x-icon name="lock" class="h-4 w-4" />
                    </span>
                    <div>
                        <p class="text-sm font-bold text-[#0f172a] dark:text-white">{{ $heldCount }} settlement(s) waiting on clearance</p>
                        <p class="mt-0.5 text-xs font-medium text-[#778599]">Open a record to verify the figures before marking it cleared.</p>
                    </div>
                </div>
            @else
                <p class="text-sm font-semibold text-[#65758c]">Nothing waiting to be released.</p>
            @endif
        </div>
    </x-card>

    @if ($awaiting->isNotEmpty())
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Waiting to be settled</h2>
                <p class="mt-1 text-xs font-medium text-[#778599]">
                    People with a separation date and nothing worked out yet. Finalize their last payroll run first, so the 13th month includes it.
                </p>
            </div>

            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @foreach ($awaiting as $employee)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3" wire:key="await-{{ $employee->id }}">
                        <div>
                            <p class="text-sm font-bold text-[#0f172a] dark:text-white">{{ $employee->fullName() }}</p>
                            <p class="text-xs font-medium text-[#778599]">
                                {{ $employee->employee_id }} · last day {{ $employee->separation_date->format('M j, Y') }}
                                @if (blank($employee->personal_email))
                                    · <span class="font-semibold text-amber-600 dark:text-amber-400">no personal email on file</span>
                                @endif
                            </p>
                        </div>
                        <x-button wire:click="start({{ $employee->id }})">Work out final pay</x-button>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <div>
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Final pay settlements</h2>
                <p class="mt-1 text-xs font-medium text-[#778599]">Review amounts, record deductions, clear, and release each settlement.</p>
            </div>
            <span class="rounded-lg bg-neutral-100 px-3 py-1.5 text-xs font-bold text-[#526783] dark:bg-white/5 dark:text-neutral-300">
                {{ $settlements->total() }} total
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[1120px] divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Employee</th>
                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Last day</th>
                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Last days</th>
                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-[#65758c]">13th month</th>
                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Deductions</th>
                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Net pay</th>
                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Status</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-[#65758c]">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($settlements as $final)
                        <tr wire:key="final-{{ $final->id }}" class="transition hover:bg-[#fbfcfd] dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-4">
                                <p class="font-bold text-[#0f172a] dark:text-white">{{ $final->employee?->fullName() ?: '—' }}</p>
                                <span class="mt-0.5 block text-xs font-medium text-[#778599]">{{ $final->employee?->employee_id }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-[#65758c]">{{ $final->separation_date->format('M j, Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold text-[#526783] tabular-nums">
                                <span class="text-[#0f172a] dark:text-white">₱{{ number_format($final->unpaidTotal(), 2) }}</span>
                                @if ((float) $final->unpaid_days > 0)
                                    <span class="mt-0.5 block text-xs font-medium text-[#778599]">{{ rtrim(rtrim(number_format((float) $final->unpaid_days, 2), '0'), '.') }} day(s) worked</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold text-[#526783] tabular-nums">₱{{ number_format((float) $final->thirteenth_month, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold text-[#526783] tabular-nums">
                                ₱{{ number_format($final->totalDeductions(), 2) }}
                                @if ($final->other_deduction_label)
                                    <span class="mt-0.5 block max-w-36 whitespace-normal text-xs font-medium text-[#778599]">{{ $final->other_deduction_label }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-right text-base font-bold text-[#0f172a] tabular-nums dark:text-white">₱{{ number_format((float) $final->net_amount, 2) }}</td>
                            <td class="px-4 py-4">
                                <x-badge :color="$final->statusColor()">{{ $final->statusLabel() }}</x-badge>
                                @if ($final->expected_release_on && $final->status !== 'released')
                                    <span class="mt-1 block text-xs font-medium text-[#778599]">Due about {{ $final->expected_release_on->format('M j') }}</span>
                                @endif
                                @if ($final->emailed_at)
                                    <span class="mt-1 block text-xs font-medium text-[#778599]">Emailed {{ $final->emailed_at->format('M j') }}</span>
                                @endif
                            </td>
                            <td class="w-72 px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('payroll.final-pay-slip', $final) }}" wire:navigate class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 text-xs font-bold text-[#526783] shadow-sm transition hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/10 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-white/5">
                                        <x-icon name="eye" class="h-4 w-4" /> Open
                                    </a>
                                    @if ($final->status === 'held')
                                        <button wire:click="clear({{ $final->id }})" wire:confirm="Clearance signed off for {{ $final->employee?->fullName() }}?"
                                                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-700 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-brand-800">
                                            <x-icon name="check" class="h-4 w-4" /> Mark cleared
                                        </button>
                                    @elseif ($final->status === 'cleared')
                                        <button wire:click="release({{ $final->id }})"
                                                wire:confirm="Release ₱{{ number_format((float) $final->net_amount, 2) }} to {{ $final->employee?->fullName() }} and email the statement to their personal address?"
                                                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-700 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-brand-800">
                                            <x-icon name="mail" class="h-4 w-4" /> Release &amp; email
                                        </button>
                                    @elseif ($final->status === 'released')
                                        <button wire:click="emailStatement({{ $final->id }})" wire:confirm="Send the statement again?"
                                                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3 text-xs font-bold text-brand-800 transition hover:bg-brand-100 dark:border-brand-400/20 dark:bg-brand-400/10 dark:text-brand-300">
                                            <x-icon name="mail" class="h-4 w-4" /> Email again
                                        </button>
                                    @endif
                                </div>
                                @if ($final->status === 'held')
                                    <div class="mt-2 flex items-center justify-end gap-3 text-xs font-semibold">
                                        <button wire:click="recalculate({{ $final->id }})" class="text-[#65758c] hover:text-[#0f172a] dark:text-neutral-400 dark:hover:text-white">Recalculate</button>
                                        <button wire:click="editDeduction({{ $final->id }})" class="text-[#65758c] hover:text-[#0f172a] dark:text-neutral-400 dark:hover:text-white">Deduction</button>
                                        <button wire:click="cancel({{ $final->id }})" wire:confirm="Cancel this settlement?" class="text-red-600 hover:text-red-700 dark:text-red-400">Cancel</button>
                                    </div>
                                @elseif ($final->status === 'cleared')
                                    <button wire:click="hold({{ $final->id }})" class="mt-2 text-xs font-semibold text-amber-700 hover:text-amber-800 dark:text-amber-400">Put back on hold</button>
                                @endif
                            </td>
                        </tr>

                        @if ($deductingId === $final->id)
                            <tr wire:key="deduct-{{ $final->id }}">
                                <td colspan="8" class="bg-[#f8fafc] px-4 py-4 dark:bg-neutral-800/50">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                        <div class="sm:col-span-2">
                                            <x-label>What is still owed?</x-label>
                                            <x-input wire:model="otherLabel" type="text" maxlength="120" placeholder="e.g. Laptop not returned" />
                                            @error('otherLabel') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <x-label>Amount</x-label>
                                            <x-input wire:model="otherAmount" type="number" step="0.01" />
                                            @error('otherAmount') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="flex items-end gap-2">
                                            <x-button wire:click="saveDeduction">Save</x-button>
                                            <x-button variant="secondary" wire:click="$set('deductingId', null)">Cancel</x-button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center font-medium text-[#778599]">Nobody has been settled yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($settlements->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $settlements->links('components.pagination', ['noun' => 'settlements']) }}
            </div>
        @endif
    </x-card>
</div>
