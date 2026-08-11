<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Shared profile-photo handling so onboarding and the self-service page apply
 * the same rules. Photos go on the "public" disk explicitly — the app's default
 * disk is private, and a profile photo has to be servable by the browser.
 */
trait StoresProfilePhoto
{
    /** @return list<string> */
    protected function photoRules(): array
    {
        // jpg/png/webp only: rejects SVG, which can carry scripts, and caps the
        // upload so a large file cannot exhaust request memory.
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }

    /**
     * Stores the upload and returns the new relative path, removing the file it
     * replaces so orphaned images do not accumulate on disk.
     */
    // Typed against UploadedFile rather than Livewire's TemporaryUploadedFile
    // subclass, so the same method is reachable from tests and any non-Livewire
    // caller without a needless coupling.
    protected function storeProfilePhoto(Employee $employee, ?UploadedFile $photo): ?string
    {
        if (! $photo) {
            return $employee->photo_path;
        }

        $previous = $employee->photo_path;

        $path = $photo->store('employee-photos', 'public');

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
    }
}
