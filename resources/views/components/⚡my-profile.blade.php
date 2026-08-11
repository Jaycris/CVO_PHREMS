<?php

use App\Support\StoresProfilePhoto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use StoresProfilePhoto, WithFileUploads;

    public $photo = null;
    public ?string $statusMessage = null;

    public function mount(): void
    {
        // Employees cannot reach the HR-only /employees routes, so this is the
        // only place they can maintain their own photo.
        abort_unless(Auth::user()->employee, 403, 'No employee profile is linked to your account.');
    }

    public function save(): void
    {
        $this->validate(['photo' => ['required', ...array_slice($this->photoRules(), 1)]]);

        $employee = Auth::user()->employee;
        $employee->update(['photo_path' => $this->storeProfilePhoto($employee, $this->photo)]);

        $this->reset('photo');
        $this->statusMessage = 'Profile photo updated.';
    }

    public function removePhoto(): void
    {
        $employee = Auth::user()->employee;

        if ($employee->photo_path) {
            Storage::disk('public')->delete($employee->photo_path);
            $employee->update(['photo_path' => null]);
        }

        $this->reset('photo');
        $this->statusMessage = 'Profile photo removed.';
    }

    public function with(): array
    {
        return [
            'employee' => Auth::user()->employee->fresh(['department', 'position']),
        ];
    }
};
?>

<div class="max-w-3xl space-y-6">
    <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Profile</h1>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <x-card>
        <h2 class="mb-4 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">Profile Photo</h2>

        <div class="flex flex-wrap items-center gap-6">
            @if ($photo)
                <img src="{{ $photo->temporaryUrl() }}" alt="Selected photo preview"
                     class="h-24 w-24 shrink-0 rounded-full object-cover ring-1 ring-black/5 dark:ring-white/10">
            @else
                <x-avatar :employee="$employee" size="xl" />
            @endif

            <div class="min-w-[16rem] flex-1 space-y-3">
                <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp"
                       class="block w-full text-sm text-[#65758c] file:mr-3 file:rounded-lg file:border-0 file:bg-brand-700 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-800 dark:text-neutral-300">
                <p class="text-xs font-medium text-[#778599]">JPG, PNG or WEBP, up to 4MB.</p>
                <p wire:loading wire:target="photo" class="text-xs font-semibold text-brand-700 dark:text-brand-300">Uploading...</p>
                @error('photo') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                <div class="flex flex-wrap gap-2">
                    <x-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="photo,save">Save Photo</x-button>
                    @if ($employee->photo_path)
                        <x-button type="button" variant="secondary" wire:click="removePhoto" wire:confirm="Remove your profile photo?">Remove</x-button>
                    @endif
                </div>
            </div>
        </div>
    </x-card>

    <x-card>
        <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-[#778599] dark:text-neutral-400">My Details</h2>
        <p class="mb-3 text-xs font-medium text-[#778599]">Contact HR if any of this needs correcting.</p>
        <dl class="space-y-2 text-sm">
            @foreach ([
                'Employee ID' => $employee->employee_id,
                'Name' => $employee->fullName(),
                'Company Email' => $employee->company_email,
                'Department' => $employee->department?->name ?? '—',
                'Position' => $employee->position?->title ?? '—',
                'Employment Status' => $employee->employment_status,
            ] as $label => $value)
                <div class="flex justify-between gap-4">
                    <dt class="font-medium text-[#778599] dark:text-neutral-400">{{ $label }}</dt>
                    <dd class="text-right text-[#65758c] dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>
</div>
