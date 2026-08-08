<?php

use App\Services\OvertimeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $work_date = '';
    public string $hours = '';
    public string $reason = '';

    /** Hours implied by the punch record for the chosen date. */
    public float $suggested = 0.0;

    public function mount(): void
    {
        $this->work_date = now()->subDay()->toDateString();
        $this->refreshSuggestion();
    }

    public function updatedWorkDate(): void
    {
        $this->refreshSuggestion();
    }

    public function refreshSuggestion(OvertimeService $overtimeService = null): void
    {
        $employee = Auth::user()->employee;

        if (! $employee || $this->work_date === '') {
            $this->suggested = 0.0;

            return;
        }

        $overtimeService ??= app(OvertimeService::class);
        $this->suggested = $overtimeService->suggestedHours($employee, $this->work_date);

        if ($this->hours === '' && $this->suggested > 0) {
            $this->hours = (string) $this->suggested;
        }
    }

    public function save(OvertimeService $overtimeService): void
    {
        $employee = Auth::user()->employee;
        abort_unless($employee, 403, 'No employee profile is linked to your account.');

        $data = $this->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:16'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $request = $overtimeService->submit($employee, $data['work_date'], (float) $data['hours'], $data['reason']);

        $this->redirect(route('overtime.show', $request), navigate: true);
    }
};
?>

<div class="max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">File Overtime</h1>
        <a href="{{ route('overtime.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">← Back to Overtime</a>
    </div>

    <x-card>
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-label>Date Worked</x-label>
                <x-input wire:model.live="work_date" type="date" max="{{ now()->toDateString() }}" />
                @error('work_date') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Hours</x-label>
                <x-input wire:model="hours" type="number" step="0.25" min="0.25" max="16" />
                <p class="mt-1 text-xs font-medium text-[#778599]">
                    @if ($suggested > 0)
                        Your attendance for this date shows about {{ rtrim(rtrim(number_format($suggested, 2), '0'), '.') }} hour(s) beyond your schedule.
                    @else
                        No extra time was recorded on your attendance for this date. Your manager will review what you file.
                    @endif
                </p>
                @error('hours') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Reason</x-label>
                <x-textarea wire:model="reason" rows="3" placeholder="What required the extra hours?" />
                @error('reason') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2 pt-2">
                <x-button type="submit">Submit for Approval</x-button>
                <x-button as="a" variant="secondary" href="{{ route('overtime.index') }}" wire:navigate>Cancel</x-button>
            </div>
        </form>
    </x-card>
</div>
