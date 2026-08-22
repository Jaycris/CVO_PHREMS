<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CommissionSlip;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * An agent's own commission slips.
 *
 * Reads the slips this app wrote down when the run was computed — not the CRM.
 * That is the point of the run: the figure an agent is shown is the figure that
 * was locked and sent, and it does not move afterwards because someone edited a
 * sale in the CRM.
 *
 * Nothing appears until HR presses Send, exactly as with a payslip.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    #[Locked]
    public ?int $openId = null;

    public function mount(?CommissionSlip $slip = null): void
    {
        abort_unless(Auth::user()->employee, 403, 'No employee profile is linked to your account.');

        if ($slip?->exists) {
            $this->authorizeOwn($slip);
            $this->openId = $slip->id;
        }
    }

    /**
     * A slip belongs to its agent, and only once it has been sent.
     *
     * The gate is notified_at rather than the run's status: finalizing locks
     * the figures, but it is HR pressing Send that decides the agent may see
     * them.
     */
    protected function authorizeOwn(CommissionSlip $slip): void
    {
        $employee = Auth::user()?->employee;

        abort_unless($employee && $slip->employee_id === $employee->id, 403, 'That commission slip is not yours.');
        abort_unless($slip->notified_at !== null, 403, 'That commission slip has not been sent yet.');
    }

    public function open(int $id): void
    {
        $this->authorizeOwn(CommissionSlip::findOrFail($id));
        $this->openId = $id;
    }

    public function closeSlip(): void
    {
        $this->openId = null;
    }

    public function with(): array
    {
        $employee = Auth::user()->employee;

        $mine = fn () => CommissionSlip::where('employee_id', $employee->id)->released();

        return [
            'slips' => $mine()->with('commissionRun')
                ->join('commission_runs', 'commission_runs.id', '=', 'commission_slips.commission_run_id')
                ->orderByDesc('commission_runs.period_start')
                ->orderByDesc('commission_runs.id')
                ->select('commission_slips.*')
                ->paginate($this->perPage()),
            // Looked up on its own rather than from the page in view, so paging
            // away does not close it.
            'open' => $this->openId
                ? $mine()->with(['lines', 'commissionRun', 'employee'])->find($this->openId)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Commission</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            Worked out in the CRM. Check each slip and tell your team lead within three working days if something looks wrong.
        </p>
    </div>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Month</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">MTD</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">USD Total</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">PHP Total</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Card Hold</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Net Commission</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @php($money = fn ($v, $p = '') => $v === null ? '—' : $p . number_format((float) $v, 2))
                    @forelse ($slips as $slip)
                        <tr wire:key="mine-{{ $slip->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                            <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">{{ $slip->monthLabel() }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $money($slip->mtd, '$') }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $money($slip->usd_total, '$') }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $money($slip->php_total, '₱') }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $money($slip->card_hold_amount, '₱') }}</td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $money($slip->net_commission, '₱') }}</td>
                            <td class="px-4 py-3 text-right">
                                <button wire:click="open({{ $slip->id }})" @click="$wire.openId = {{ $slip->id }}"
                                        class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center font-medium text-[#778599]">
                            No commission slips yet. They appear here once HR sends them.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($slips->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $slips->links('components.pagination', ['noun' => 'slips']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="openId" onClose="closeSlip" maxWidth="4xl">
        @if ($open)
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Commission Slip</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">{{ $open->monthLabel() }}</h2>
                    <p class="text-sm font-medium text-[#778599]">Sent {{ $open->notified_at?->format('F j, Y') }}</p>
                </div>
                <x-button as="a" href="{{ route('my-commission.download', ['slip' => $open->id]) }}" variant="secondary" class="h-9 px-3 text-xs">
                    <x-icon name="document" class="h-4 w-4" /> Download PDF
                </x-button>
            </div>

            <x-commission-slip-detail :slip="$open" />
        @endif
    </x-modal>
</div>
