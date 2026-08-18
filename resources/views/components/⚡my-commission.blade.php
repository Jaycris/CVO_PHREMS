<?php

use App\Services\Crm\CommissionSlip;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * An agent's own commission slip, read from the CRM.
 *
 * The page owns none of these figures. When the CRM cannot be reached it says
 * so plainly rather than rendering zeros — an agent seeing a confident 0.00
 * would reasonably believe they had earned nothing.
 */
new #[Layout('layouts.app')] class extends Component
{
    public string $month = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->employee, 403, 'No employee profile is linked to your account.');

        $this->month = now('Asia/Manila')->format('Y-m');
    }

    public function updatedMonth(): void
    {
        if (! array_key_exists($this->month, $this->months())) {
            $this->month = now('Asia/Manila')->format('Y-m');
        }
    }

    /** @return array<string, string> */
    public function months(): array
    {
        return app(CommissionSlipService::class)->selectableMonths(Auth::user()->employee);
    }

    public function refreshSlip(CommissionSlipService $service): void
    {
        $service->forget(Auth::user()->employee, $this->month);
    }

    public function with(CommissionSlipService $service): array
    {
        $employee = Auth::user()->employee;
        $slip = null;
        $error = null;

        if (! $service->isConfigured()) {
            $error = 'The CRM connection has not been set up yet. Ask IT to fill in CRM_API_BASE_URL and CRM_HRIS_API_TOKEN.';
        } else {
            try {
                $slip = $service->forEmployee($employee, $this->month);
            } catch (CrmUnavailable $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'slip' => $slip,
            'error' => $error,
            'monthOptions' => $this->months(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Commission</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Worked out in the CRM and shown here. Queries about any amount go to your team lead.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <label for="commission-month" class="sr-only">Month</label>
            <select id="commission-month" wire:model.live="month"
                    class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-semibold text-[#526783] shadow-sm transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <x-button wire:click="refreshSlip" wire:loading.attr="disabled" wire:target="refreshSlip" variant="secondary" class="h-10 px-4">
                <span wire:loading.remove wire:target="refreshSlip">Refresh</span>
                <span wire:loading wire:target="refreshSlip">Fetching…</span>
            </x-button>

            @if ($slip)
                <x-button as="a" href="{{ route('my-commission.download', ['month' => $month]) }}" class="h-10 px-4">
                    <x-icon name="document" class="h-4 w-4" /> Download PDF
                </x-button>
            @endif
        </div>
    </div>

    @if ($error)
        <x-card>
            <div class="py-8 text-center">
                <p class="text-sm font-bold text-ink-900 dark:text-white">Commission data is not available right now.</p>
                <p class="mx-auto mt-2 max-w-xl text-sm font-medium text-ink-500 dark:text-ink-400">{{ $error }}</p>
                <p class="mx-auto mt-4 max-w-xl text-xs font-medium text-ink-500 dark:text-ink-400">
                    Nothing is shown rather than zeros, because a zero here would look like you had earned nothing.
                </p>
            </div>
        </x-card>
    @else
        <x-commission-slip :slip="$slip" />
    @endif
</div>
