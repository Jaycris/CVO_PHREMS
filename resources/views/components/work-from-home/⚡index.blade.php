<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\WorkFromHomeRequest;
use App\Services\WorkFromHomeService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Asking to work from home, and deciding those asks.
 *
 * One page for both, the same way overtime works: whether an approval queue
 * appears depends on whether anyone reports to you, which is a fact about the
 * org chart rather than a permission.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    /** @var list<string> The days picked, as Y-m-d. */
    public array $dates = [];
    public string $newDate = '';
    public string $rangeEnd = '';
    public string $reason = '';

    #[Locked]
    public ?int $decidingId = null;
    public bool $showDecision = false;
    public string $decisionNote = '';

    public function createRequest(): void
    {
        $this->reset(['dates', 'newDate', 'rangeEnd', 'reason']);
        $this->resetValidation();
        $this->errorMessage = null;
        $this->newDate = Carbon::tomorrow('Asia/Manila')->toDateString();
        $this->showForm = true;
    }

    /** Adds one day, or every day in a range when an end date is given. */
    public function addDate(): void
    {
        $this->errorMessage = null;

        if ($this->newDate === '') {
            $this->errorMessage = 'Pick a date first.';

            return;
        }

        try {
            $start = Carbon::parse($this->newDate)->startOfDay();
            $end = $this->rangeEnd !== '' ? Carbon::parse($this->rangeEnd)->startOfDay() : $start;
        } catch (\Throwable) {
            $this->errorMessage = 'That does not look like a date.';

            return;
        }

        if ($end->lt($start)) {
            $this->errorMessage = 'The end date is before the start date.';

            return;
        }

        if ($start->diffInDays($end) > 30) {
            $this->errorMessage = 'That range is longer than a month. Add it in smaller pieces.';

            return;
        }

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            // Weekends are skipped when adding a range, since a range is a
            // shorthand for "the working days between these two" — but a
            // single date is taken at face value, because a Saturday shift is
            // somebody's normal week.
            if ($this->rangeEnd !== '' && $day->isWeekend()) {
                continue;
            }

            $this->dates[] = $day->toDateString();
        }

        $this->dates = collect($this->dates)->unique()->sort()->values()->all();
        $this->rangeEnd = '';
    }

    public function removeDate(string $date): void
    {
        $this->dates = array_values(array_filter($this->dates, fn ($d) => $d !== $date));
    }

    public function submit(WorkFromHomeService $service): void
    {
        $this->errorMessage = null;

        $this->validate(
            ['reason' => ['required', 'string', 'max:300']],
            ['reason.required' => 'Say why you need to work from home.'],
        );

        if ($this->dates === []) {
            $this->errorMessage = 'Add at least one day.';

            return;
        }

        try {
            $service->submit(Auth::user()->employee, $this->dates, $this->reason);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showForm = false;
        $this->reset(['dates', 'newDate', 'rangeEnd', 'reason']);
        $this->statusMessage = 'Sent. Your manager will decide it.';
    }

    public function withdraw(int $id, WorkFromHomeService $service): void
    {
        $service->cancel(WorkFromHomeRequest::findOrFail($id), Auth::user()->employee);
        $this->statusMessage = 'Request withdrawn.';
    }

    public function review(int $id): void
    {
        $this->decidingId = WorkFromHomeRequest::pending()->findOrFail($id)->id;
        $this->decisionNote = '';
        $this->resetValidation();
        $this->errorMessage = null;
        $this->showDecision = true;
    }

    public function closeDecision(): void
    {
        $this->reset(['decidingId', 'decisionNote']);
        $this->showDecision = false;
    }

    public function decide(bool $approved, WorkFromHomeService $service): void
    {
        $this->errorMessage = null;

        if (! $approved) {
            $this->validate(
                ['decisionNote' => ['required', 'string', 'max:300']],
                ['decisionNote.required' => 'Tell them why it was declined.'],
            );
        }

        try {
            $service->decide(
                WorkFromHomeRequest::with(['employee', 'days'])->findOrFail($this->decidingId),
                Auth::user()->employee,
                $approved,
                $this->decisionNote ?: null,
            );
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->statusMessage = $approved ? 'Approved.' : 'Declined.';
        $this->closeDecision();
    }

    public function with(WorkFromHomeService $service): array
    {
        $user = Auth::user();
        $employee = $user->employee;
        $seesAll = $user->can('wfh.view_all');

        $empty = fn (string $name) => new LengthAwarePaginator([], 0, $this->perPage(), 1, ['pageName' => $name]);

        // What this person may decide: their own reports, plus the unassigned
        // ones when they oversee this company-wide.
        $queue = $empty('queue');

        if ($employee) {
            $queue = WorkFromHomeRequest::with(['employee', 'days'])
                ->pending()
                ->where(fn ($q) => $q
                    ->where('manager_id', $employee->id)
                    ->when($seesAll, fn ($w) => $w->orWhereNull('manager_id')))
                ->oldest()
                ->paginate($this->perPage(), pageName: 'queue');
        }

        $deciding = $this->decidingId
            ? WorkFromHomeRequest::with(['employee', 'days'])->find($this->decidingId)
            : null;

        return [
            'queue' => $queue,
            'mine' => $employee
                ? WorkFromHomeRequest::with('days')->where('employee_id', $employee->id)->latest()
                    ->paginate($this->perPage(), pageName: 'mine')
                : $empty('mine'),
            'all' => $seesAll
                ? WorkFromHomeRequest::with(['employee', 'days'])->latest()
                    ->paginate($this->perPage(), pageName: 'all')
                : $empty('all'),
            'deciding' => $deciding,
            'coverage' => $deciding ? $service->coverageFor($deciding) : [],
            'seesAll' => $seesAll,
            'hasEmployee' => $employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Work From Home</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Ask in advance for the days you need. A full working day either way — you still clock in as usual.
            </p>
        </div>

        @if ($hasEmployee)
            <x-button wire:click="createRequest" @click="$wire.showForm = true" pill>
                <x-icon name="plus" class="h-4 w-4" /> Request Work From Home
            </x-button>
        @endif
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($queue->total() > 0)
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Awaiting Your Approval</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Days</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Reason</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Filed</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($queue as $request)
                            <tr wire:key="q-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                    {{ $request->employee->fullName() ?: $request->employee->employee_id }}
                                    <span class="block text-xs font-medium text-[#778599]">{{ $request->employee->employee_id }}</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-neutral-300">
                                    {{ $request->dateLabel() }}
                                    <span class="block text-xs text-[#778599]">{{ $request->dayCount() }} day(s)</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $request->reason }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-[#778599]">{{ $request->created_at->format('M j') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button wire:click="review({{ $request->id }})" @click="$wire.showDecision = true"
                                            class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Review</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($queue->hasPages())
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    {{ $queue->links('components.pagination', ['noun' => 'requests waiting']) }}
                </div>
            @endif
        </x-card>
    @endif

    @if ($hasEmployee)
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">My Requests</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Days</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Reason</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Note</th>
                            <th class="px-4 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($mine as $request)
                            <tr wire:key="m-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                    {{ $request->dateLabel() }}
                                    <span class="block text-xs text-[#778599]">{{ $request->dayCount() }} day(s)</span>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $request->reason }}</td>
                                <td class="px-4 py-3"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                                <td class="px-4 py-3 font-medium text-[#778599]">{{ $request->decision_note ?: '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($request->isPending())
                                        <x-button wire:click="withdraw({{ $request->id }})"
                                                  wire:confirm="Withdraw this request?"
                                                  variant="secondary" class="h-9 px-3 text-xs">Withdraw</x-button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center font-medium text-[#778599]">
                                Nothing asked for yet.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($mine->hasPages())
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    {{ $mine->links('components.pagination', ['noun' => 'of your requests']) }}
                </div>
            @endif
        </x-card>
    @endif

    @if ($seesAll)
        <x-card :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">All Requests</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                    <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Employee</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Days</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                            <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Decided by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($all as $request)
                            <tr wire:key="a-{{ $request->id }}">
                                <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                    {{ $request->employee->fullName() ?: $request->employee->employee_id }}
                                </td>
                                <td class="px-4 py-3 font-medium text-[#778599]">
                                    {{ $request->dateLabel() }}
                                    <span class="block text-xs">{{ $request->dayCount() }} day(s)</span>
                                </td>
                                <td class="px-4 py-3"><x-badge :color="$request->statusColor()">{{ $request->statusLabel() }}</x-badge></td>
                                <td class="px-4 py-3 font-medium text-[#778599]">
                                    {{ $request->manager?->fullName() ?: '—' }}
                                    @if ($request->decided_at)
                                        <span class="block text-xs">{{ $request->decided_at->format('M j, Y') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center font-medium text-[#778599]">Nothing filed yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($all->hasPages())
                <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    {{ $all->links('components.pagination', ['noun' => 'requests']) }}
                </div>
            @endif
        </x-card>
    @endif

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">Request Work From Home</h2>
        <p class="mt-1 text-sm font-medium text-[#778599]">
            Add each day you need. Give an end date as well to add a whole run of working days at once.
        </p>

        @if ($errorMessage)
            <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
        @endif

        <div class="mt-5 space-y-4">
            <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <x-label>Day</x-label>
                    <x-input wire:model="newDate" type="date" />
                </div>
                <div>
                    <x-label>Until <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="rangeEnd" type="date" />
                </div>
                <x-button wire:click="addDate" variant="secondary" class="h-11">Add</x-button>
            </div>

            <div>
                <x-label>Days picked</x-label>
                @if ($dates === [])
                    <p class="mt-1 rounded-lg border border-dashed border-ink-300 px-3 py-4 text-center text-sm font-medium text-[#778599] dark:border-white/15">
                        None yet.
                    </p>
                @else
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($dates as $date)
                            <span wire:key="d-{{ $date }}"
                                  class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 py-1 pl-3 pr-1.5 text-sm font-semibold text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
                                {{ \Illuminate\Support\Carbon::parse($date)->format('D, M j') }}
                                <button type="button" wire:click="removeDate('{{ $date }}')"
                                        class="rounded-full p-0.5 text-brand-700 transition hover:bg-brand-200/60 dark:text-brand-300 dark:hover:bg-white/10"
                                        title="Remove">
                                    <x-icon name="x-mark" class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        @endforeach
                    </div>
                    <p class="mt-1.5 text-xs font-medium text-[#778599]">{{ count($dates) }} day(s)</p>
                @endif
            </div>

            <div>
                <x-label>Why?</x-label>
                <x-textarea wire:model="reason" rows="2" placeholder="e.g. Waiting for a delivery at home" />
                @error('reason') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                <span wire:loading.remove wire:target="submit">Send to Manager</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>

    <x-modal wire="showDecision" onClose="closeDecision" maxWidth="lg">
        @if ($deciding)
            <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
                {{ $deciding->employee->fullName() ?: $deciding->employee->employee_id }}
            </h2>
            <p class="mt-1 text-sm font-medium text-[#778599]">Filed {{ $deciding->created_at->format('F j, Y') }}.</p>

            @if ($errorMessage)
                <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
            @endif

            <div class="mt-4 rounded-xl bg-[#f8fafc] p-4 dark:bg-neutral-800/50">
                <p class="text-xs font-bold uppercase tracking-wide text-[#778599]">Reason</p>
                <p class="mt-1 text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $deciding->reason }}</p>
            </div>

            {{-- How many desks are already empty on each day. "Can you work from
                 home on Tuesday" is rarely a question about one person. --}}
            <div class="mt-4">
                <p class="text-xs font-bold uppercase tracking-wide text-[#778599]">Days asked for</p>
                <ul class="mt-2 divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($deciding->days as $day)
                        @php($others = $coverage[$day->work_date->toDateString()] ?? 0)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="cov-{{ $day->id }}">
                            <span class="text-sm font-semibold text-ink-900 dark:text-white">{{ $day->work_date->format('l, M j') }}</span>
                            <span class="text-xs font-medium {{ $others > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-[#778599]' }}">
                                {{ $others === 0 ? 'nobody else at home' : $others . ' other(s) already approved' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="mt-4">
                <x-label>Note (required to decline)</x-label>
                <x-textarea wire:model="decisionNote" rows="2" placeholder="e.g. We need someone on the floor that day" />
                @error('decisionNote') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <x-button wire:click="decide(true)" wire:loading.attr="disabled" wire:target="decide">
                    <span wire:loading.remove wire:target="decide">Approve</span>
                    <span wire:loading wire:target="decide">Saving…</span>
                </x-button>
                <x-button wire:click="decide(false)" wire:loading.attr="disabled" wire:target="decide" variant="secondary">Decline</x-button>
                <x-button wire:click="closeDecision" @click="modalOpen = false" variant="secondary">Cancel</x-button>
            </div>
        @endif
    </x-modal>
</div>
