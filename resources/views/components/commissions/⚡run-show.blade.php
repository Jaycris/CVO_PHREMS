<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Services\Commission\CommissionRunService;
use App\Services\Commission\CommissionSlipNotifier;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One month's run: compute it, check it, finalize it, send it.
 *
 * The three buttons appear one at a time, in order, because that is the order
 * they must happen in and a screen offering all three at once invites the wrong
 * one being pressed.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    #[Locked]
    public int $runId;

    public ?string $statusMessage = null;
    public ?string $errorMessage = null;
    public string $search = '';

    public bool $showUnlock = false;
    public string $unlockReason = '';

    public ?int $viewingSlipId = null;

    public bool $showAgents = false;
    /** @var list<int> */
    public array $selectedAgents = [];
    public string $agentSearch = '';

    public function mount(CommissionRun $run): void
    {
        $this->runId = $run->id;
    }

    public function chooseAgents(): void
    {
        $this->selectedAgents = $this->run()->agents()->pluck('employees.id')->all();
        $this->agentSearch = '';
        $this->errorMessage = null;
        $this->showAgents = true;
    }

    public function saveAgents(CommissionRunService $service): void
    {
        $this->errorMessage = null;

        try {
            $service->setAgents($this->run(), $this->selectedAgents);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showAgents = false;
        $this->statusMessage = count($this->selectedAgents) . ' agent(s) selected for this run.';
    }

    /** Ticks everyone whose commission frequency matches this run's type. */
    public function selectSuggested(CommissionRunService $service): void
    {
        $this->selectedAgents = $service->suggestedAgentsFor($this->run()->run_type)->pluck('id')->all();
    }

    public function selectNone(): void
    {
        $this->selectedAgents = [];
    }

    public function run(): CommissionRun
    {
        return CommissionRun::findOrFail($this->runId);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function compute(CommissionRunService $service): void
    {
        $this->errorMessage = null;

        try {
            $run = $service->compute($this->run(), Auth::user());
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->statusMessage = $run->agent_count . ' agent(s) read from the CRM.'
            . ($run->failed_count ? ' ' . $run->failed_count . ' could not be read — see the rows marked below.' : '');
    }

    public function finalize(CommissionRunService $service): void
    {
        $this->errorMessage = null;

        abort_unless(Auth::user()->can('commissions.runs.finalize'), 403);

        try {
            $service->finalize($this->run(), Auth::user());
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->statusMessage = 'Figures locked. The slips are ready to send.';
    }

    public function send(CommissionSlipNotifier $notifier): void
    {
        $this->errorMessage = null;

        abort_unless(Auth::user()->can('commissions.runs.finalize'), 403);

        try {
            $result = $notifier->sendForRun($this->run());
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->statusMessage = $result['sent'] . ' slip(s) sent.'
            . ($result['skipped'] ? ' ' . $result['skipped'] . ' skipped — no login linked.' : '')
            . ($result['failed'] ? ' ' . $result['failed'] . ' failed to send.' : '');
    }

    public function unlock(CommissionRunService $service): void
    {
        abort_unless(Auth::user()->can('commissions.runs.finalize'), 403);

        try {
            $service->unlock($this->run(), Auth::user(), $this->unlockReason);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showUnlock = false;
        $this->unlockReason = '';
        $this->statusMessage = 'Run reopened. Compute it again to pick up the CRM corrections.';
    }

    public function viewSlip(int $id): void
    {
        $this->viewingSlipId = $id;
    }

    public function closeSlip(): void
    {
        $this->viewingSlipId = null;
    }

    public function with(CommissionSlipNotifier $notifier, CommissionRunService $runs): array
    {
        $run = $this->run();

        return [
            'run' => $run,
            'agentCount' => $run->agents()->count(),
            'selectableAgents' => $this->showAgents
                ? $runs->selectableAgents()->filter(fn ($e) => $this->agentSearch === ''
                    || str_contains(mb_strtolower($e->fullName() . ' ' . $e->employee_id . ' ' . $e->company_email), mb_strtolower($this->agentSearch)))
                : collect(),
            'slips' => $run->slips()
                ->with('employee')
                ->when($this->search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e
                    ->where('first_name', 'like', "%{$this->search}%")
                    ->orWhere('last_name', 'like', "%{$this->search}%")
                    ->orWhere('employee_id', 'like', "%{$this->search}%")))
                ->orderByRaw('CASE WHEN fetch_error IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('net_commission')
                ->paginate($this->perPage()),
            'logs' => $run->logs()->limit(15)->get(),
            'pendingSends' => $run->isFinalized() ? $notifier->pendingCount($run) : 0,
            'canFinalize' => Auth::user()->can('commissions.runs.finalize'),
            'viewingSlip' => $this->viewingSlipId
                ? CommissionSlip::with(['lines', 'employee', 'commissionRun'])->find($this->viewingSlipId)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('commissions.runs') }}" wire:navigate class="text-xs font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-400">&larr; All commission runs</a>
            <h1 class="mt-1 text-xl font-bold text-[#0f172a] dark:text-white">Commissions — {{ $run->periodLabel() }}</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                {{ $run->typeLabel() }} ·
                {{ $run->period_start->format('M j, Y') }} to {{ $run->period_end->format('M j, Y') }}
                ({{ $run->dayCount() }} days) · these are the dates the CRM is asked for.
            </p>
        </div>

        <x-badge :color="$run->statusColor()" class="text-sm">{{ $run->statusLabel() }}</x-badge>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
    @endif

    {{-- The three steps, offered one at a time and in order. --}}
    <x-card>
        <div class="flex flex-wrap items-center gap-3">
            @if ($run->isMutable())
                {{-- Before computing: who the run covers. Frequency pre-ticks
                     the list, but the last word is a person's. --}}
                <x-button wire:click="chooseAgents" @click="$wire.showAgents = true" variant="secondary">
                    <x-icon name="users" class="mr-1 inline h-4 w-4" />
                    Agents ({{ $agentCount }})
                </x-button>
            @endif

            @if ($run->isMutable())
                <x-button wire:click="compute" wire:loading.attr="disabled" wire:target="compute">
                    <span wire:loading.remove wire:target="compute">
                        <x-icon name="chart" class="mr-1 inline h-4 w-4" />
                        {{ $run->status === 'draft' ? 'Compute from CRM' : 'Recompute from CRM' }}
                    </span>
                    <span wire:loading wire:target="compute">Reading the CRM…</span>
                </x-button>
            @endif

            @if ($run->status === 'computed' && $canFinalize)
                <x-button wire:click="finalize"
                          wire:confirm="Lock these figures? Nothing is sent yet — you send the slips as a separate step."
                          wire:loading.attr="disabled" wire:target="finalize" variant="secondary">
                    <span wire:loading.remove wire:target="finalize">Finalize</span>
                    <span wire:loading wire:target="finalize">Locking…</span>
                </x-button>
            @endif

            @if ($run->isFinalized() && $canFinalize && $pendingSends > 0)
                <x-button wire:click="send"
                          wire:confirm="Send {{ $pendingSends }} commission slip(s) to the agents? They will be able to see them straight away."
                          wire:loading.attr="disabled" wire:target="send" variant="success">
                    <span wire:loading.remove wire:target="send">Send {{ $pendingSends }} Commission Slip(s)</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </x-button>
            @endif

            @if ($run->isFinalized() && $pendingSends === 0)
                <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">All slips sent.</span>
            @endif

            @if ($run->isFinalized() && $canFinalize)
                <x-button wire:click="$set('showUnlock', true)" @click="$wire.showUnlock = true" variant="secondary" class="ml-auto">
                    Reopen
                </x-button>
            @endif
        </div>

        @if ($run->status === 'draft')
            <p class="mt-3 text-xs font-medium text-[#778599]">
                @if ($agentCount === 0)
                    <span class="font-bold text-amber-600 dark:text-amber-400">No agents selected.</span>
                    Choose who this run covers before computing.
                @else
                    Nothing has been read yet. Compute reads the {{ $agentCount }} selected agent(s) from the CRM
                    for {{ $run->period_start->format('M j') }}–{{ $run->period_end->format('M j') }} and writes the figures down.
                @endif
            </p>
        @elseif ($run->status === 'computed')
            <p class="mt-3 text-xs font-medium text-[#778599]">
                Read from the CRM {{ $run->computed_at?->diffForHumans() }}{{ $run->computedBy ? ' by ' . $run->computedBy->name : '' }}.
                Check the figures below, then finalize. Recomputing picks up any correction made in the CRM since.
            </p>
        @elseif ($run->isFinalized())
            <p class="mt-3 text-xs font-medium text-[#778599]">
                Locked {{ $run->finalized_at?->diffForHumans() }}{{ $run->finalizedBy ? ' by ' . $run->finalizedBy->name : '' }}.
                @if ($run->sent_at) Sent {{ $run->sent_at->diffForHumans() }}. @endif
            </p>
        @endif
    </x-card>

    @if ($run->status !== 'draft')
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                'Agents' => $run->agent_count,
                'USD total' => '$' . number_format((float) $run->total_usd, 2),
                'PHP total' => '₱' . number_format((float) $run->total_php, 2),
                'Card hold' => '₱' . number_format((float) $run->total_card_hold, 2),
                'Net commission' => '₱' . number_format((float) $run->total_net, 2),
            ] as $label => $value)
                <div class="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-ink-900">
                    <p class="text-xs font-semibold text-[#526783] dark:text-ink-400">{{ $label }}</p>
                    <p class="mt-1.5 text-xl font-bold tabular-nums text-ink-950 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @if ($run->failed_count > 0)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ $run->failed_count }} agent(s) could not be read from the CRM.</p>
            <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-200/90">
                They are listed first below with the reason. Usually the CRM user is missing its HRIS Employee ID.
                Fix it in the CRM, then recompute — nothing else is affected and no slip is sent for them.
            </p>
        </div>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Commission Slips</h2>

            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search agents..."
                       class="h-10 w-64 rounded-xl border border-ink-200 bg-white py-2 pl-9 pr-4 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-500 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Agent</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Team / Type</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">MTD</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">USD</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">PHP</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Card Hold</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Net</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Sent</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @php($dash = fn ($v, $p = '') => $v === null ? '—' : $p . number_format((float) $v, 2))
                    @forelse ($slips as $slip)
                        <tr wire:key="slip-{{ $slip->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5 {{ $slip->failed() ? 'bg-amber-50/60 dark:bg-amber-500/5' : '' }}">
                            <td class="px-4 py-3">
                                <p class="font-medium text-[#65758c] dark:text-white">{{ $slip->employeeName() }}</p>
                                <p class="text-xs font-medium text-[#778599]">{{ $slip->employeeCode() }}</p>
                            </td>

                            @if ($slip->failed())
                                <td colspan="6" class="px-4 py-3 text-sm font-medium text-amber-700 dark:text-amber-300">
                                    {{ $slip->fetch_error }}
                                </td>
                            @else
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $slip->teamLabel() }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">
                                    {{ $dash($slip->mtd, '$') }}
                                    @if ($slip->mtd_percent !== null)
                                        <span class="block text-xs">{{ number_format((float) $slip->mtd_percent, 2) }}%</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $dash($slip->usd_total, '$') }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $dash($slip->php_total, '₱') }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $dash($slip->card_hold_amount, '₱') }}</td>
                                <td class="px-4 py-3 text-right font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $dash($slip->net_commission, '₱') }}</td>
                            @endif

                            <td class="px-4 py-3">
                                @if ($slip->failed())
                                    <x-badge color="amber">Not sent</x-badge>
                                @elseif ($slip->isReleased())
                                    <x-badge color="green">{{ $slip->notified_at->format('M j') }}</x-badge>
                                @else
                                    <x-badge color="neutral">Not yet</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @unless ($slip->failed())
                                    <button wire:click="viewSlip({{ $slip->id }})" @click="$wire.viewingSlipId = {{ $slip->id }}"
                                            class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">View slip</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center font-medium text-[#778599]">
                            @if ($run->status === 'draft')
                                Nothing computed yet. Press Compute from CRM above.
                            @else
                                No agents match that.
                            @endif
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

    {{-- The whole slip, exactly as the agent will see it. --}}
    <x-modal wire="viewingSlipId" onClose="closeSlip" maxWidth="4xl">
        @if ($viewingSlip)
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Commission Slip</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">{{ $viewingSlip->employeeName() }}</h2>
                    <p class="text-sm font-medium text-[#778599]">{{ $viewingSlip->monthLabel() }}</p>
                </div>
                <x-button as="a" href="{{ route('my-commission.download', ['slip' => $viewingSlip->id]) }}" variant="secondary" class="h-9 px-3 text-xs">
                    <x-icon name="document" class="h-4 w-4" /> PDF
                </x-button>
            </div>

            <x-commission-slip-detail :slip="$viewingSlip" />
        @endif
    </x-modal>

    <x-modal wire="showAgents" onClose="$set('showAgents', false)" maxWidth="2xl">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">Agents in this run</h2>
        <p class="mt-1 text-sm font-medium text-[#778599]">
            {{ $run->typeLabel() }} run covering {{ $run->period_start->format('M j') }}–{{ $run->period_end->format('M j, Y') }}.
            Only these agents are read from the CRM.
        </p>

        @if ($errorMessage)
            <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-56">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <input type="text" wire:model.live.debounce.250ms="agentSearch" placeholder="Search name, ID or email"
                       class="h-10 w-full rounded-xl border border-ink-200 bg-white py-2 pl-9 pr-4 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-500 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
            </div>

            <x-button wire:click="selectSuggested" variant="secondary" class="h-10 px-3 text-xs">
                Tick {{ $run->typeLabel() }} agents
            </x-button>
            <x-button wire:click="selectNone" variant="secondary" class="h-10 px-3 text-xs">Clear</x-button>
        </div>

        <p class="mt-2 text-xs font-medium text-[#778599]">
            {{ count($selectedAgents) }} selected.
        </p>

        <div class="mt-3 max-h-80 divide-y divide-ink-100 overflow-y-auto rounded-xl border border-ink-200 dark:divide-white/10 dark:border-white/10">
            @forelse ($selectableAgents as $agent)
                <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition hover:bg-ink-50 dark:hover:bg-white/5"
                       wire:key="ag-{{ $agent->id }}">
                    <input type="checkbox" wire:model="selectedAgents" value="{{ $agent->id }}"
                           class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-800">
                    <span class="flex-1">
                        <span class="block text-sm font-semibold text-ink-900 dark:text-white">
                            {{ $agent->fullName() ?: $agent->employee_id }}
                        </span>
                        <span class="block text-xs font-medium text-[#778599]">
                            {{ $agent->employee_id }} · {{ $agent->department?->name ?? 'No department' }}
                        </span>
                    </span>
                    <x-badge :color="match ($agent->commission_frequency) {
                        'monthly' => 'blue',
                        'biweekly' => 'brand',
                        default => 'neutral',
                    }">
                        {{ match ($agent->commission_frequency) {
                            'monthly' => 'Monthly',
                            'biweekly' => 'Bi-weekly',
                            default => 'No commission',
                        } }}
                    </x-badge>
                </label>
            @empty
                <p class="px-4 py-8 text-center text-sm font-medium text-[#778599]">
                    {{ $agentSearch !== '' ? 'Nobody matches that.' : 'No employees on file.' }}
                </p>
            @endforelse
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
            <x-button wire:click="saveAgents" wire:loading.attr="disabled" wire:target="saveAgents">
                <span wire:loading.remove wire:target="saveAgents">Save Selection</span>
                <span wire:loading wire:target="saveAgents">Saving…</span>
            </x-button>
            <x-button wire:click="$set('showAgents', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>

    <x-modal wire="showUnlock" onClose="$set('showUnlock', false)" maxWidth="lg">
        <h2 class="mb-1 text-lg font-bold text-[#0f172a] dark:text-white">Reopen this run</h2>
        <p class="mb-4 text-sm font-medium text-[#778599]">
            The figures unlock so the run can be computed again. Agents already sent the old slip keep seeing it
            until you send again. The reason is recorded against the run.
        </p>
        <x-label>Why?</x-label>
        <x-textarea wire:model="unlockReason" rows="2" placeholder="e.g. A sale was posted to the wrong agent in the CRM" />
        <div class="mt-4 flex gap-2">
            <x-button wire:click="unlock">Reopen</x-button>
            <x-button wire:click="$set('showUnlock', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
