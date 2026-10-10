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
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Final Pay</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            What somebody is owed after their last day: 13th month earned up to then, less anything they still owe. Held until clearance.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    {{-- The same strip a payroll run opens with: what is in front of you, and
         the buttons that move it on. --}}
    <x-card>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-xs font-medium text-[#778599]">Waiting to be worked out</p>
                <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $awaiting->count() }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-[#778599]">Held for clearance</p>
                <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $heldCount }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-[#778599]">Cleared, ready to pay</p>
                <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $clearedCount }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-[#778599]">Net to release</p>
                <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format($openTotal, 2) }}</p>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap items-end gap-2 border-t border-neutral-100 pt-5 dark:border-neutral-800">
            @if ($clearedCount > 0)
                <div>
                    <x-label>Release date</x-label>
                    <x-input wire:model="releaseDate" type="date" class="w-44" />
                </div>

                <x-button wire:click="sendCleared"
                          wire:confirm="Release ₱{{ number_format($clearedTotal, 2) }} to {{ $clearedCount }} person/people and email each statement to their personal address?">
                    <span wire:loading.remove wire:target="sendCleared">Release &amp; Send Statements ({{ $clearedCount }})</span>
                    <span wire:loading wire:target="sendCleared">Sending…</span>
                </x-button>
            @elseif ($heldCount > 0)
                <p class="text-sm font-medium text-[#778599]">
                    {{ $heldCount }} settlement(s) waiting on clearance. Open one to check the figures and finalize it.
                </p>
            @else
                <p class="text-sm font-medium text-[#778599]">Nothing waiting to be released.</p>
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
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Last day</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Last days</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">13th month</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Owed to us</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Net</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($settlements as $final)
                        <tr wire:key="final-{{ $final->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $final->employee?->fullName() ?: '—' }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $final->employee?->employee_id }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $final->separation_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">
                                ₱{{ number_format($final->unpaidTotal(), 2) }}
                                @if ((float) $final->unpaid_days > 0)
                                    <span class="block text-xs">{{ rtrim(rtrim(number_format((float) $final->unpaid_days, 2), '0'), '.') }} day(s) worked</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $final->thirteenth_month, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">
                                ₱{{ number_format($final->totalDeductions(), 2) }}
                                @if ($final->other_deduction_label)
                                    <span class="block text-xs">{{ $final->other_deduction_label }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $final->net_amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$final->statusColor()">{{ $final->statusLabel() }}</x-badge>
                                @if ($final->expected_release_on && $final->status !== 'released')
                                    <span class="block text-xs font-medium text-[#778599]">Due about {{ $final->expected_release_on->format('M j') }}</span>
                                @endif
                                @if ($final->emailed_at)
                                    <span class="block text-xs font-medium text-[#778599]">Emailed {{ $final->emailed_at->format('M j') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <a href="{{ route('payroll.final-pay-slip', $final) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Open</a>
                                    @if ($final->status === 'held')
                                        <button wire:click="recalculate({{ $final->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Recalculate</button>
                                        <button wire:click="editDeduction({{ $final->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Deduction</button>
                                        <button wire:click="clear({{ $final->id }})" wire:confirm="Clearance signed off for {{ $final->employee?->fullName() }}?"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Mark cleared</button>
                                        <button wire:click="cancel({{ $final->id }})" wire:confirm="Cancel this settlement?"
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Cancel</button>
                                    @elseif ($final->status === 'cleared')
                                        <button wire:click="release({{ $final->id }})"
                                                wire:confirm="Release ₱{{ number_format((float) $final->net_amount, 2) }} to {{ $final->employee?->fullName() }} and email the statement to their personal address?"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Release &amp; email</button>
                                        <button wire:click="hold({{ $final->id }})" class="font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400">Put back on hold</button>
                                    @elseif ($final->status === 'released')
                                        <button wire:click="emailStatement({{ $final->id }})" wire:confirm="Send the statement again?"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Email again</button>
                                    @endif
                                </div>
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
