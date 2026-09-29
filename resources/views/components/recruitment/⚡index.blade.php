<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\Position;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The adverts for the roles the company is hiring for.
 *
 * Written here and read by the company website's Join Our Team page through
 * /api/careers/openings, so HR takes a role down in one place rather than
 * asking whoever owns the website to do it.
 *
 * Draft is invisible to the world. Published is on the site. Closed is off it
 * again, and so is anything past its closing date — which is the point of the
 * date: a role nobody remembered to take down stops advertising itself.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public ?int $editingId = null;
    public bool $showForm = false;
    public ?string $statusMessage = null;

    public string $title = '';
    public ?int $department_id = null;
    public ?int $position_id = null;
    public string $employment_type = '';
    public string $workplace_type = '';
    public string $location = '';
    public string $headcount = '1';
    public string $summary = '';
    public string $description = '';
    public string $responsibilities = '';
    public string $qualifications = '';
    public string $salary_min = '';
    public string $salary_max = '';
    // A string, not a bool: a select bound to a bool property never matches
    // its "1" option, so the dropdown silently snaps back to No.
    public string $salary_visible = '0';
    public string $apply_email = '';
    public string $apply_url = '';
    public string $closes_on = '';

    protected function guard(): void
    {
        abort_unless(Auth::user()->can('recruitment.manage'), 403, 'You cannot manage job postings.');
    }

    public function create(): void
    {
        $this->guard();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();

        $posting = JobPosting::findOrFail($id);

        $this->editingId = $posting->id;
        $this->title = (string) $posting->title;
        $this->department_id = $posting->department_id;
        $this->position_id = $posting->position_id;
        $this->employment_type = (string) $posting->employment_type;
        $this->workplace_type = (string) $posting->workplace_type;
        $this->location = (string) $posting->location;
        $this->headcount = (string) $posting->headcount;
        $this->summary = (string) $posting->summary;
        $this->description = (string) $posting->description;
        $this->responsibilities = (string) $posting->responsibilities;
        $this->qualifications = (string) $posting->qualifications;
        $this->salary_min = $posting->salary_min === null ? '' : (string) (float) $posting->salary_min;
        $this->salary_max = $posting->salary_max === null ? '' : (string) (float) $posting->salary_max;
        $this->salary_visible = $posting->salary_visible ? '1' : '0';
        $this->apply_email = (string) $posting->apply_email;
        $this->apply_url = (string) $posting->apply_url;
        $this->closes_on = $posting->closes_on?->format('Y-m-d') ?? '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->guard();

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'employment_type' => ['nullable', 'in:Full-time,Part-time'],
            'workplace_type' => ['nullable', 'in:Onsite,Hybrid,Remote'],
            'location' => ['nullable', 'string', 'max:255'],
            'headcount' => ['required', 'integer', 'min:1', 'max:999'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['required', 'string'],
            'responsibilities' => ['nullable', 'string'],
            'qualifications' => ['nullable', 'string'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            'salary_visible' => ['in:0,1'],
            // One of the two has to exist, or the advert tells nobody how to apply.
            'apply_email' => ['nullable', 'email', 'required_without:apply_url'],
            'apply_url' => ['nullable', 'url', 'required_without:apply_email'],
            'closes_on' => ['nullable', 'date'],
        ], [
            'apply_email.required_without' => 'Give an email address or a link, so people know how to apply.',
            'apply_url.required_without' => 'Give a link or an email address, so people know how to apply.',
        ]);

        $data['salary_visible'] = $data['salary_visible'] === '1';

        foreach (['employment_type', 'workplace_type', 'location', 'summary', 'responsibilities',
            'qualifications', 'salary_min', 'salary_max', 'apply_email', 'apply_url', 'closes_on'] as $optional) {
            if (($data[$optional] ?? '') === '') {
                $data[$optional] = null;
            }
        }

        if ($this->editingId) {
            JobPosting::findOrFail($this->editingId)->update($data);
            $this->statusMessage = 'Posting saved.';
        } else {
            JobPosting::create($data + [
                'status' => JobPosting::DRAFT,
                'created_by_user_id' => Auth::id(),
            ]);
            $this->statusMessage = 'Draft saved. Publish it when you are ready for it to appear on the website.';
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function publish(int $id): void
    {
        $this->guard();

        $posting = JobPosting::findOrFail($id);

        $posting->update([
            'status' => JobPosting::PUBLISHED,
            // Kept from the first time, so republishing after a correction does
            // not make an old advert look new.
            'published_at' => $posting->published_at ?? now(),
        ]);

        $this->statusMessage = 'Published. It is on the website now.';
    }

    public function close(int $id): void
    {
        $this->guard();

        JobPosting::findOrFail($id)->update(['status' => JobPosting::CLOSED]);

        $this->statusMessage = 'Closed. It is off the website.';
    }

    /** Only a draft can be deleted; anything published has been seen. */
    public function deleteDraft(int $id): void
    {
        $this->guard();

        $posting = JobPosting::findOrFail($id);

        abort_unless($posting->status === JobPosting::DRAFT, 403, 'Close it instead — this posting has been published.');

        $posting->delete();
        $this->statusMessage = 'Draft deleted.';
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'title', 'department_id', 'position_id', 'employment_type', 'workplace_type',
            'location', 'summary', 'description', 'responsibilities', 'qualifications',
            'salary_min', 'salary_max', 'salary_visible', 'apply_email', 'apply_url', 'closes_on',
        ]);

        $this->headcount = '1';
        $this->salary_visible = '0';
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'postings' => JobPosting::with(['department', 'position'])
                ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->paginate($this->perPage()),
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('title')->get(),
            'liveCount' => JobPosting::live()->count(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Recruitment</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Write a role here and it appears on the company website's Join Our Team page.
                {{ $liveCount }} {{ Str::plural('role', $liveCount) }} showing there now.
            </p>
        </div>

        <x-button wire:click="create" pill>
            <x-icon name="plus" class="h-4 w-4" /> New Posting
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @if ($showForm)
        <x-card>
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">{{ $editingId ? 'Edit posting' : 'New posting' }}</h2>

            <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-label>Job title</x-label>
                    <x-input wire:model="title" type="text" placeholder="e.g. Sales Agent (Graveyard)" />
                    @error('title') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>How many are you hiring?</x-label>
                    <x-input wire:model="headcount" type="number" min="1" />
                    @error('headcount') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Department</x-label>
                    <x-select wire:model="department_id">
                        <option value="">Not set</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div>
                    <x-label>Position</x-label>
                    <x-select wire:model="position_id">
                        <option value="">Not set</option>
                        @foreach ($positions as $position)
                            <option value="{{ $position->id }}">{{ $position->title }}</option>
                        @endforeach
                    </x-select>
                    <p class="mt-1 text-xs font-medium text-[#778599]">For your own records. The website shows the job title above.</p>
                </div>

                <div>
                    <x-label>Employment type</x-label>
                    <x-select wire:model="employment_type">
                        <option value="">Not set</option>
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                    </x-select>
                </div>

                <div>
                    <x-label>Work setup</x-label>
                    <x-select wire:model="workplace_type">
                        <option value="">Not set</option>
                        <option value="Onsite">Onsite</option>
                        <option value="Hybrid">Hybrid</option>
                        <option value="Remote">Remote</option>
                    </x-select>
                </div>

                <div>
                    <x-label>Location</x-label>
                    <x-input wire:model="location" type="text" placeholder="e.g. Cebu City" />
                </div>

                <div>
                    <x-label>Closing date <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="closes_on" type="date" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">It comes off the website by itself after this date.</p>
                </div>

                <div class="sm:col-span-3">
                    <x-label>One-line summary</x-label>
                    <x-input wire:model="summary" type="text" maxlength="500" placeholder="Shown on the list of roles, before somebody clicks in." />
                    @error('summary') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-3">
                    <x-label>About the role</x-label>
                    <textarea wire:model="description" rows="5"
                              class="mt-1 block w-full rounded-lg border-ink-200 bg-white px-3.5 py-2.5 text-sm font-medium text-ink-800 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white"></textarea>
                    @error('description') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-3">
                    <x-label>Responsibilities <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <textarea wire:model="responsibilities" rows="4"
                              class="mt-1 block w-full rounded-lg border-ink-200 bg-white px-3.5 py-2.5 text-sm font-medium text-ink-800 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white"></textarea>
                </div>

                <div class="sm:col-span-3">
                    <x-label>Qualifications <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <textarea wire:model="qualifications" rows="4"
                              class="mt-1 block w-full rounded-lg border-ink-200 bg-white px-3.5 py-2.5 text-sm font-medium text-ink-800 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white"></textarea>
                </div>

                <div>
                    <x-label>Salary from <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="salary_min" type="number" step="100" />
                </div>

                <div>
                    <x-label>Salary to <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="salary_max" type="number" step="100" />
                    @error('salary_max') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Show the salary publicly?</x-label>
                    <x-select wire:model="salary_visible">
                        <option value="0">No — keep it internal</option>
                        <option value="1">Yes — show it on the website</option>
                    </x-select>
                    <p class="mt-1 text-xs font-medium text-[#778599]">Left off, the website never receives it at all.</p>
                </div>

                <div>
                    <x-label>Applications go to this email</x-label>
                    <x-input wire:model="apply_email" type="email" placeholder="hr@creativisionoutsourcing.com" />
                    @error('apply_email') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <x-label>Or an application link</x-label>
                    <x-input wire:model="apply_url" type="url" placeholder="https://..." />
                    @error('apply_url') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-2 border-t border-neutral-100 pt-5 dark:border-neutral-800">
                <x-button wire:click="save">{{ $editingId ? 'Save changes' : 'Save as draft' }}</x-button>
                <x-button variant="secondary" wire:click="cancel">Cancel</x-button>
            </div>
        </x-card>
    @endif

    <x-card :padding="false">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            <h2 class="text-[15px] font-bold text-[#0f172a] dark:text-white">Postings</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Role</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Department</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Setup</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Hiring</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($postings as $posting)
                        <tr wire:key="posting-{{ $posting->id }}">
                            <td class="px-4 py-3 font-medium text-[#65758c] dark:text-white">
                                {{ $posting->title }}
                                <span class="block text-xs font-medium text-[#778599]">/{{ $posting->slug }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $posting->department?->name ?: '—' }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599]">
                                {{ $posting->workplace_type ?: '—' }}
                                @if ($posting->employment_type)
                                    <span class="block text-xs">{{ $posting->employment_type }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599] tabular-nums">{{ $posting->headcount }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$posting->statusColor()">{{ $posting->statusLabel() }}</x-badge>
                                @if ($posting->hasExpired())
                                    <span class="block text-xs font-medium text-[#778599]">Closed on {{ $posting->closes_on->format('M j, Y') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <button wire:click="edit({{ $posting->id }})" class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Edit</button>

                                    @if ($posting->status !== 'published')
                                        <button wire:click="publish({{ $posting->id }})"
                                                wire:confirm="Put this role on the company website?"
                                                class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Publish</button>
                                    @else
                                        <button wire:click="close({{ $posting->id }})"
                                                wire:confirm="Take this role off the website?"
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Close</button>
                                    @endif

                                    @if ($posting->status === 'draft')
                                        <button wire:click="deleteDraft({{ $posting->id }})"
                                                wire:confirm="Delete this draft? It has never been published."
                                                class="font-medium text-red-600 hover:text-red-700 dark:text-red-400">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center font-medium text-[#778599]">No roles posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($postings->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $postings->links('components.pagination', ['noun' => 'postings']) }}
            </div>
        @endif
    </x-card>
</div>
