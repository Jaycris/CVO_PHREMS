<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Announcement;
use App\Models\Employee;
use App\Notifications\AnnouncementPosted;
use App\Services\TodayBoard;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The company noticeboard.
 *
 * Open to everybody, because the whole point is that everybody reads it. The
 * page decides what to offer: anyone signed in sees today's board and the
 * notices that are running, and only announcements.manage sees the drafts, the
 * expired ones, and the buttons.
 *
 * Notices are dated, so this list clears itself. An exhibit posted for 8 to 13
 * September stops appearing on the 14th without anybody going back to tidy up,
 * which is the failure mode of every noticeboard that is just a list of posts.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showForm = false;

    public bool $showDelete = false;

    public ?int $editingId = null;

    public ?int $deleteId = null;

    public ?string $statusMessage = null;

    /** Which slice of the list to show. Only meaningful to a manager. */
    public string $filter = 'current';

    public string $title = '';

    public string $body = '';

    public string $kind = Announcement::NEWS;

    public string $startsOn = '';

    public string $endsOn = '';

    public bool $isPinned = false;

    /**
     * Whether to publish it now, or keep it as a draft.
     *
     * Separate from the dates on purpose. Writing Monday's notice on Friday is
     * normal; having it appear before anybody has checked it is not.
     */
    public bool $publishNow = true;

    /**
     * Whether to email everybody as well as post it.
     *
     * Off by default. A noticeboard that emails everyone about everything is
     * one people filter away, and then the notice that mattered goes unread
     * with the rest.
     */
    public bool $notifyEveryone = false;

    protected function canManage(): bool
    {
        return Auth::user()?->can('announcements.manage') ?? false;
    }

    protected function guardManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->guardManage();

        $this->reset(['editingId', 'title', 'body', 'isPinned', 'notifyEveryone']);

        $this->kind = Announcement::NEWS;
        $this->publishNow = true;
        $this->startsOn = now('Asia/Manila')->toDateString();
        $this->endsOn = '';

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guardManage();

        $announcement = Announcement::findOrFail($id);

        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->body = $announcement->body;
        $this->kind = $announcement->kind;
        $this->startsOn = $announcement->starts_on->toDateString();
        $this->endsOn = $announcement->ends_on?->toDateString() ?? '';
        $this->isPinned = $announcement->is_pinned;
        $this->publishNow = ! $announcement->isDraft();

        // Never carried over from a previous edit. Re-emailing everybody
        // because somebody fixed a typo is its own kind of failure.
        $this->notifyEveryone = false;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->guardManage();

        $data = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],
            'kind' => ['required', 'in:' . implode(',', array_keys(Announcement::KINDS))],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'isPinned' => ['boolean'],
        ], [
            'endsOn.after_or_equal' => 'The last day cannot be before the first day.',
        ], [
            'title' => 'title',
            'body' => 'message',
            'startsOn' => 'first day',
            'endsOn' => 'last day',
        ]);

        $existing = $this->editingId ? Announcement::findOrFail($this->editingId) : null;

        /*
         * Publishing stamps the time once and never restamps it.
         *
         * The stamp is the record of when it first went out, and editing a live
         * notice must not move it — that would reorder the list and make an old
         * notice look new to everyone who had already read it.
         */
        $publishedAt = match (true) {
            ! $this->publishNow => null,
            $existing?->published_at !== null => $existing->published_at,
            default => now(),
        };

        $announcement = Announcement::updateOrCreate(['id' => $this->editingId], [
            'title' => $data['title'],
            'body' => $data['body'],
            'kind' => $data['kind'],
            'starts_on' => $data['startsOn'],
            'ends_on' => $data['endsOn'] ?: null,
            'is_pinned' => $this->isPinned,
            'published_at' => $publishedAt,
            'created_by_user_id' => $existing?->created_by_user_id ?? Auth::id(),
        ]);

        $told = 0;

        if ($this->notifyEveryone && $publishedAt !== null) {
            $told = $this->tellEveryone($announcement);
        }

        $this->showForm = false;
        $this->statusMessage = match (true) {
            $publishedAt === null => 'Saved as a draft. Nobody sees it until you post it.',
            $told > 0 => 'Posted, and ' . $told . ' ' . ($told === 1 ? 'person was' : 'people were') . ' emailed.',
            default => $this->editingId ? 'Announcement updated.' : 'Announcement posted.',
        };

        $this->reset(['editingId', 'title', 'body', 'isPinned', 'notifyEveryone']);
    }

    public function togglePin(int $id): void
    {
        $this->guardManage();

        $announcement = Announcement::findOrFail($id);
        $announcement->update(['is_pinned' => ! $announcement->is_pinned]);

        $this->statusMessage = $announcement->is_pinned
            ? 'Pinned to the top of the board.'
            : 'Unpinned.';
    }

    public function publish(int $id): void
    {
        $this->guardManage();

        $announcement = Announcement::findOrFail($id);

        if ($announcement->isDraft()) {
            $announcement->update(['published_at' => now()]);
        }

        $this->statusMessage = 'Posted. It shows on the board from ' . $announcement->starts_on->format('M d') . '.';
    }

    /**
     * Takes a notice off the board without deleting it.
     *
     * Wanted more often than deleting: a notice posted by mistake, or one whose
     * dates were wrong, should come down now and be fixed rather than retyped.
     */
    public function unpublish(int $id): void
    {
        $this->guardManage();

        Announcement::findOrFail($id)->update(['published_at' => null]);

        $this->statusMessage = 'Taken down. It is a draft again and nobody can see it.';
    }

    public function prepareDelete(int $id): void
    {
        $this->guardManage();

        $this->deleteId = $id;
        $this->showDelete = true;
    }

    public function deleteConfirmed(): void
    {
        $this->guardManage();

        Announcement::whereKey($this->deleteId)->delete();

        $this->deleteId = null;
        $this->showDelete = false;
        $this->statusMessage = 'Announcement deleted.';
    }

    /**
     * Emails everybody who has an account, and counts who was reachable.
     *
     * Staff with no sign-in account are skipped rather than treated as an
     * error. Somebody onboarding today has an employee record before they have
     * an account, and a notice must not fail to post because of that.
     */
    protected function tellEveryone(Announcement $announcement): int
    {
        $users = Employee::query()
            ->whereNotNull('user_id')
            ->whereNull('separation_date')
            ->with('user')
            ->get()
            ->map(fn (Employee $employee) => $employee->user)
            ->filter()
            ->unique('id');

        foreach ($users as $user) {
            $user->notify(new AnnouncementPosted($announcement));
        }

        return $users->count();
    }

    public function with(): array
    {
        $user = Auth::user();
        $today = now('Asia/Manila');
        $canManage = $this->canManage();

        $query = Announcement::query()->forBoard();

        if (! $canManage) {
            // Everyone else sees what is running and what is coming, never a
            // draft and never something that has already ended.
            $query->whereNotNull('published_at')
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()));
        } else {
            $query = match ($this->filter) {
                'drafts' => $query->whereNull('published_at'),
                'past' => $query->whereNotNull('published_at')->whereDate('ends_on', '<', $today->toDateString()),
                'all' => $query,
                default => $query->whereNotNull('published_at')
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString())),
            };
        }

        return [
            'today' => $today,
            'canManage' => $canManage,
            'board' => app(TodayBoard::class)->for($user, $today),
            'announcements' => $query->paginate($this->perPage()),
            'draftCount' => $canManage ? Announcement::whereNull('published_at')->count() : 0,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-3xl">
            <h1 class="text-xl font-bold text-ink-950 dark:text-white">Announcements</h1>
            <p class="mt-1 text-sm font-medium leading-6 text-ink-500 dark:text-ink-400">
                Company news, reminders and events, together with whatever else is happening today.
            </p>
        </div>

        @if ($canManage)
            <x-button type="button" wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')">
                <x-icon name="plus" class="h-4 w-4" /> Post Announcement
            </x-button>
        @endif
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <x-today-board :items="$board" :today="$today" />

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 px-6 py-4 dark:border-white/10">
            <div>
                <h2 class="text-base font-bold text-ink-950 dark:text-white">
                    {{ $canManage ? 'All Notices' : 'Current Notices' }}
                </h2>
                <p class="mt-0.5 text-sm font-medium text-ink-500 dark:text-ink-400">
                    {{ $canManage
                        ? 'Drafts are only visible here. Nobody else sees them until you post.'
                        : 'What is running now, and what is coming up.' }}
                </p>
            </div>

            @if ($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ([
                        'current' => 'Current',
                        'drafts' => 'Drafts' . ($draftCount > 0 ? ' (' . $draftCount . ')' : ''),
                        'past' => 'Ended',
                        'all' => 'All',
                    ] as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('filter', '{{ $key }}')"
                            class="rounded-lg border px-3 py-2 text-xs font-bold transition {{ $filter === $key ? 'border-brand-700 bg-brand-700 text-white shadow-sm' : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="divide-y divide-ink-100 dark:divide-white/10">
            @forelse ($announcements as $announcement)
                <div wire:key="ann-{{ $announcement->id }}" class="px-6 py-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge :color="$announcement->kindColor()">{{ $announcement->kindLabel() }}</x-badge>

                                @if ($announcement->isDraft())
                                    <x-badge color="neutral">Draft</x-badge>
                                @endif

                                @if ($announcement->is_pinned)
                                    <x-badge color="brand">Pinned</x-badge>
                                @endif

                                <span class="text-xs font-semibold text-ink-500 dark:text-ink-400">{{ $announcement->timing($today) }}</span>
                            </div>

                            <h3 class="mt-2 text-base font-bold text-ink-900 dark:text-white">{{ $announcement->title }}</h3>

                            <p class="mt-1.5 whitespace-pre-line text-sm font-medium leading-relaxed text-ink-600 dark:text-ink-300">{{ $announcement->body }}</p>

                            <p class="mt-3 text-xs font-semibold text-ink-500 dark:text-ink-400">
                                {{ $announcement->rangeLabel() }}
                                @if ($announcement->createdBy)
                                    · Posted by {{ $announcement->createdBy->name }}
                                @endif
                            </p>
                        </div>

                        @if ($canManage)
                            <div class="flex shrink-0 items-center gap-1.5">
                                <button
                                    type="button"
                                    wire:click="togglePin({{ $announcement->id }})"
                                    title="{{ $announcement->is_pinned ? 'Unpin from the top' : 'Pin to the top' }}"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:hover:bg-white/10 {{ $announcement->is_pinned ? 'text-brand-700 dark:text-brand-300' : 'text-ink-500 dark:text-ink-400' }}"
                                >
                                    <x-icon name="tag" class="h-4 w-4" />
                                </button>

                                @if ($announcement->isDraft())
                                    <button
                                        type="button"
                                        wire:click="publish({{ $announcement->id }})"
                                        title="Post it to the board"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-bold text-emerald-700 shadow-sm transition hover:bg-emerald-100 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300"
                                    >
                                        <x-icon name="check" class="h-4 w-4" /> Post
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="unpublish({{ $announcement->id }})"
                                        title="Take it off the board, keeping the text"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 text-xs font-bold text-ink-600 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10"
                                    >
                                        Take down
                                    </button>
                                @endif

                                <button
                                    type="button"
                                    wire:click="edit({{ $announcement->id }})"
                                    @click="$dispatch('open-phrems-modal', 'showForm')"
                                    title="Edit"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-400 dark:hover:bg-white/10"
                                >
                                    <x-icon name="pencil" class="h-4 w-4" />
                                </button>

                                <button
                                    type="button"
                                    wire:click="prepareDelete({{ $announcement->id }})"
                                    @click="$dispatch('open-phrems-modal', 'showDelete')"
                                    title="Delete"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 shadow-sm transition hover:bg-red-100 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-300"
                                >
                                    <x-icon name="trash" class="h-4 w-4" />
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                        <x-icon name="bell" class="h-7 w-7" />
                    </div>
                    <p class="mt-3 text-sm font-bold text-ink-800 dark:text-white">Nothing posted.</p>
                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">
                        {{ $canManage ? 'Post an announcement and it appears here and on everyone\'s dashboard.' : 'There are no company notices right now.' }}
                    </p>
                </div>
            @endforelse
        </div>

        @if ($announcements->hasPages())
            <div class="border-t border-ink-200 px-6 py-4 dark:border-white/10">
                {{ $announcements->links() }}
            </div>
        @endif
    </x-card>

    @if ($canManage)
        <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
            <h2 class="text-lg font-bold text-ink-950 dark:text-white">
                {{ $editingId ? 'Edit Announcement' : 'Post Announcement' }}
            </h2>

            <div class="mt-5 space-y-4">
                <div>
                    <x-label>Title</x-label>
                    <x-input wire:model="title" type="text" placeholder="e.g. Trade exhibit at SMX, 8–13 September" />
                    @error('title') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Message</x-label>
                    <x-textarea wire:model="body" rows="5" placeholder="What people need to know." />
                    @error('body') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-label>Kind</x-label>
                    <x-select wire:model="kind">
                        @foreach (\App\Models\Announcement::KINDS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <p class="mt-1 text-xs font-medium text-ink-500 dark:text-ink-400">Changes the colour it is shown in. Nothing else.</p>
                    @error('kind') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-label>First day on the board</x-label>
                        <x-input wire:model="startsOn" type="date" />
                        @error('startsOn') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-label>Last day <span class="font-medium text-ink-500">(optional)</span></x-label>
                        <x-input wire:model="endsOn" type="date" />
                        @error('endsOn') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="text-xs font-medium text-ink-500 dark:text-ink-400">
                    Leave the last day empty for a notice that stays up until you take it down. For an event, use the
                    event's own dates and it clears itself the day after.
                </p>

                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-3 dark:border-white/10">
                    <input type="checkbox" wire:model="isPinned" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                    <span class="text-sm">
                        <span class="font-bold text-ink-800 dark:text-white">Pin to the top</span>
                        <span class="mt-0.5 block font-medium text-ink-500 dark:text-ink-400">Holds it above everything else on the board.</span>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-3 dark:border-white/10">
                    <input type="checkbox" wire:model.live="publishNow" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                    <span class="text-sm">
                        <span class="font-bold text-ink-800 dark:text-white">Post it now</span>
                        <span class="mt-0.5 block font-medium text-ink-500 dark:text-ink-400">
                            Unticked, it is saved as a draft only you can see. It still will not appear before its first day.
                        </span>
                    </span>
                </label>

                @if ($publishNow)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-400/20 dark:bg-amber-400/10">
                        <input type="checkbox" wire:model="notifyEveryone" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                        <span class="text-sm">
                            <span class="font-bold text-ink-800 dark:text-white">Email everybody as well</span>
                            <span class="mt-0.5 block font-medium text-ink-600 dark:text-ink-300">
                                Sends this to every employee with an account, straight away — even if the first day is
                                still ahead. Keep it for things nobody can afford to miss.
                            </span>
                        </span>
                    </label>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-button>
                <x-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    {{ $publishNow ? ($editingId ? 'Save' : 'Post') : 'Save Draft' }}
                </x-button>
            </div>
        </x-modal>

        <x-modal wire="showDelete" onClose="$set('showDelete', false)" maxWidth="sm">
            <h2 class="text-lg font-bold text-ink-950 dark:text-white">Delete this announcement?</h2>
            <p class="mt-2 text-sm font-medium text-ink-500 dark:text-ink-400">
                It is removed for good. To take it off the board but keep the text, use <span class="font-bold text-ink-700 dark:text-ink-200">Take down</span> instead.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-button>
                <x-button type="button" variant="danger" wire:click="deleteConfirmed">Delete</x-button>
            </div>
        </x-modal>
    @endif
</div>
