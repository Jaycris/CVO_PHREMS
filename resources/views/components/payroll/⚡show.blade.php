<?php

use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public int $runId;

    public ?string $statusMessage = null;
    public ?string $errorMessage = null;
    public bool $showUnlock = false;
    public string $unlockReason = '';

    public function mount(PayrollRun $run): void
    {
        $this->runId = $run->id;
    }

    protected function run(): PayrollRun
    {
        return PayrollRun::findOrFail($this->runId);
    }

    /**
     * The service raises an HTTP abort for every refusal, which would otherwise
     * end up as an error page over the top of the run. Caught here so the
     * reason lands on the screen the user is already looking at.
     */
    protected function attempt(callable $action): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;

        try {
            $action();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function compute(PayrollService $service): void
    {
        $this->attempt(function () use ($service) {
            $service->compute($this->run(), Auth::user());
            $this->statusMessage = 'Payroll computed. Review the payslips before finalizing.';
        });
    }

    public function finalize(PayrollService $service): void
    {
        $this->attempt(function () use ($service) {
            abort_unless(Auth::user()->can('payroll.runs.finalize'), 403, 'You cannot finalize a payroll run.');
            $service->finalize($this->run(), Auth::user());
            $this->statusMessage = 'Figures locked. Nothing can change now without reopening the run.';
        });
    }

    public function markPaid(PayrollService $service): void
    {
        $this->attempt(function () use ($service) {
            abort_unless(Auth::user()->can('payroll.runs.finalize'), 403, 'You cannot mark a payroll run as paid.');
            $service->markPaid($this->run(), Auth::user());
            $this->statusMessage = 'Marked as paid.';
        });
    }

    public function unlock(PayrollService $service): void
    {
        $this->attempt(function () use ($service) {
            $service->unfinalize($this->run(), Auth::user(), $this->unlockReason);
            $this->showUnlock = false;
            $this->unlockReason = '';
            $this->statusMessage = 'Run reopened. Recompute when the correction is in.';
        });
    }

    public function with(PayrollService $service): array
    {
        $run = $this->run();
        $user = Auth::user();

        return [
            'run' => $run,
            // Preflight is only meaningful while the figures can still change.
            'preflight' => $run->isMutable() ? $service->preflight($run) : null,
            'payslips' => $run->payslips()->with('employee')->get()
                ->sortBy(fn ($p) => $p->employeeName())->values(),
            'logs' => $run->logs()->limit(15)->get(),
            'canFinalize' => $user->can('payroll.runs.finalize'),
            'canUnlock' => $user->can('payroll.runs.unlock'),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('payroll.index') }}" wire:navigate class="text-sm font-medium text-[#778599] hover:text-[#65758c]">&larr; All payroll runs</a>
            <h1 class="mt-1 text-xl font-bold text-[#0f172a] dark:text-white">{{ $run->periodLabel() }}</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                {{ $run->cutoff === 'first' ? 'First cutoff' : 'Second cutoff' }} &middot;
                paid {{ $run->pay_date->format('M j, Y') }}
            </p>
        </div>
        <x-badge :color="$run->statusColor()">{{ $run->statusLabel() }}</x-badge>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    {{-- Preflight: the problems that would quietly produce a wrong figure. --}}
    @if ($preflight)
        @if ($preflight['blocking'])
            <x-card>
                <h2 class="text-[15px] font-bold text-red-700 dark:text-red-400">Fix these before computing</h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($preflight['blocking'] as $issue)
                        <li class="text-sm font-medium text-[#65758c] dark:text-neutral-300">&bull; {{ $issue }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        @if ($preflight['warnings'])
            <x-card>
                <h2 class="text-[15px] font-bold text-amber-700 dark:text-amber-400">Worth checking</h2>
                <p class="mt-1 text-sm font-medium text-[#778599]">These will not stop the payroll, but they are usually a mistake.</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($preflight['warnings'] as $warning)
                        <li class="text-sm font-medium text-[#65758c] dark:text-neutral-300">&bull; {{ $warning }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    @endif

    <x-card>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-xs font-medium text-[#778599]">Employees</p>
                <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $run->employee_count ?: $preflight['employees'] ?? 0 }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-[#778599]">Gross</p>
                <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $run->total_gross, 2) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-[#778599]">Deductions</p>
                <p class="mt-1 text-2xl font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $run->total_deductions, 2) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-[#778599]">Net to release</p>
                <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-400 tabular-nums">₱{{ number_format((float) $run->total_net, 2) }}</p>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-2 border-t border-neutral-100 pt-5 dark:border-neutral-800">
            @if ($run->isMutable())
                <x-button wire:click="compute" :disabled="(bool) ($preflight['blocking'] ?? false)">
                    <span wire:loading.remove wire:target="compute">{{ $run->status === 'draft' ? 'Compute Payroll' : 'Recompute' }}</span>
                    <span wire:loading wire:target="compute">Computing…</span>
                </x-button>
            @endif

            @if ($run->status === 'computed' && $canFinalize)
                <x-button variant="secondary" wire:click="finalize" wire:confirm="Lock these figures? Payslips can no longer be changed.">Finalize</x-button>
            @endif

            @if ($run->status === 'finalized')
                @if ($canFinalize)
                    <x-button wire:click="markPaid" wire:confirm="Confirm the money has been released?">Mark as Paid</x-button>
                @endif
                @if ($canUnlock)
                    <x-button variant="secondary" wire:click="$set('showUnlock', true)">Reopen</x-button>
                @endif
            @endif

            @if ($run->payslips_count ?? $payslips->isNotEmpty())
                <x-button as="a" href="{{ route('payroll.export', $run) }}" variant="secondary">Download Register</x-button>
            @endif
        </div>

        @if ($run->status === 'computed')
            <p class="mt-3 text-xs font-medium text-[#778599]">
                Recomputing is safe — anything typed in by hand survives, and nobody is charged twice for a cash advance.
            </p>
        @elseif ($run->status === 'paid')
            <p class="mt-3 text-xs font-medium text-[#778599]">
                This run is closed. A correction goes on the next payroll as an adjustment, so the change leaves a trail.
            </p>
        @endif
    </x-card>

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Payslips</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Days</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Basic</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Gross</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Deductions</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Net</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($payslips as $payslip)
                        <tr wire:key="slip-{{ $payslip->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $payslip->employeeName() }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $payslip->employeeCode() }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">
                                {{ $payslip->days_present }} / {{ $payslip->days_expected }}
                                @if ($payslip->days_absent)
                                    <span class="block text-xs font-medium text-red-600 dark:text-red-400">{{ $payslip->days_absent }} absent</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $payslip->basic_earned, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $payslip->gross_pay, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-[#778599] tabular-nums">₱{{ number_format((float) $payslip->total_deductions, 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $payslip->net_pay, 2) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('payroll.payslip', $payslip) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center font-medium text-[#778599]">Nothing computed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">What happened to this run</h2>
        </div>
        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @forelse ($logs as $log)
                <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3" wire:key="log-{{ $log->id }}">
                    <div>
                        <span class="text-sm font-bold text-[#0f172a] dark:text-white">{{ str_replace('_', ' ', ucfirst($log->action)) }}</span>
                        <span class="text-sm font-medium text-[#778599]">— {{ $log->note }}</span>
                    </div>
                    <span class="text-xs font-medium text-[#778599]">{{ $log->user_name }} &middot; {{ $log->created_at->format('M j, g:i A') }}</span>
                </div>
            @empty
                <div class="px-5 py-6 text-center text-sm font-medium text-[#778599]">Nothing yet.</div>
            @endforelse
        </div>
    </x-card>

    <x-modal :show="$showUnlock" onClose="$set('showUnlock', false)" maxWidth="lg">
        <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Reopen this payroll</h2>
        <p class="mb-4 text-sm font-medium text-[#778599]">
            The figures unlock so they can be recomputed. The reason is recorded against the run.
        </p>
        <x-label>Why?</x-label>
        <x-textarea wire:model="unlockReason" rows="2" placeholder="e.g. Overtime was approved for the wrong date" />
        <div class="mt-4 flex gap-2">
            <x-button wire:click="unlock">Reopen</x-button>
            <x-button variant="secondary" wire:click="$set('showUnlock', false)">Cancel</x-button>
        </div>
    </x-modal>
</div>
