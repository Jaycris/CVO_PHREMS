<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Announcement;
use App\Models\Employee;
use App\Notifications\AnnouncementPosted;
use App\Models\SmsBroadcast;
use App\Services\Sms\PhoneNumber;
use App\Services\Sms\SmsBroadcaster;
use App\Services\Sms\SmsGateway;
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

    /**
     * Whether to text everybody as well.
     *
     * Its own decision, not a consequence of marking the notice Important. A
     * text interrupts somebody wherever they are and costs a credit per person,
     * so it is worth ticking deliberately while looking at what it will say and
     * who it will reach — both of which the form shows before you send.
     */
    public bool $notifyBySms = false;

    /*
     * Texting the company, with nothing else attached.
     *
     * Its own act, not an announcement with the board and the email switched
     * off. "Office closed, do not come in" has to reach phones in the next two
     * minutes and has no business becoming a dashboard item somebody re-reads
     * in March.
     */

    public bool $showBlast = false;

    public string $blastMessage = '';

    public bool $blastConfirmed = false;

    /** 'all' for the whole company, 'some' for a chosen few. */
    public string $blastAudience = 'all';

    /** @var list<int> */
    public array $blastEmployeeIds = [];

    public string $blastSearch = '';

    public function openBlast(): void
    {
        $this->guardManage();

        $this->reset(['blastMessage', 'blastConfirmed', 'blastEmployeeIds', 'blastSearch']);
        $this->blastAudience = 'all';
        $this->resetValidation();
        $this->showBlast = true;
    }

    /**
     * Who this one is for, as the broadcaster wants it.
     *
     * Null means everybody. Choosing "some" and then ticking nobody is not
     * treated as everybody — that mistake would text the whole company.
     */
    protected function blastRecipients(): ?array
    {
        return $this->blastAudience === 'all' ? null : $this->blastEmployeeIds;
    }

    public function sendBlast(SmsBroadcaster $broadcaster): void
    {
        $this->guardManage();

        abort_unless($this->smsAvailable(), 403, 'Texting is switched off under Settings.');

        $this->validate([
            'blastMessage' => ['required', 'string', 'max:1000'],
            'blastAudience' => ['required', 'in:all,some'],
            // Choosing "some" and ticking nobody would otherwise fall through
            // to an empty list, and an empty list must never mean everybody.
            'blastEmployeeIds' => [$this->blastAudience === 'some' ? 'required' : 'nullable', 'array'],
            // A second, deliberate action. Everything else on this screen can
            // be undone; a phone buzzing cannot.
            'blastConfirmed' => ['accepted'],
        ], [
            'blastMessage.required' => 'Type the message first.',
            'blastEmployeeIds.required' => 'Choose at least one person, or send it to everybody.',
            'blastConfirmed.accepted' => 'Tick the box to confirm you want to send it.',
        ]);

        $broadcast = $broadcaster->send($this->blastMessage, Auth::user(), $this->blastRecipients());

        $this->showBlast = false;
        $this->reset(['blastMessage', 'blastConfirmed', 'blastEmployeeIds', 'blastSearch']);

        /*
         * Still says plainly when nothing left the building.
         *
         * The technical reason is gone — this screen is HR's, not an
         * administrator's — but "sent" when nothing was sent is the one lie
         * this page must never tell.
         */
        $this->statusMessage = app(SmsGateway::class)->isLive()
            ? 'Text sent. ' . $broadcast->outcomeLabel() . '.'
            : 'Nothing was sent — text messaging is not switched on yet. Ask your administrator to set it up.';
    }

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

        // notifyBySms included: left set from a previous notice it would arm a
        // text blast on the next one without anybody ticking anything.
        $this->reset(['editingId', 'title', 'body', 'isPinned', 'notifyEveryone', 'notifyBySms']);

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
        $this->notifyBySms = false;

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

        // Texting without emailing is not offered: a text is 160 characters
        // that cannot be re-read later, so the email is what stays on file.
        $bySms = $this->notifyBySms && $this->smsAvailable();

        if ($this->notifyEveryone && $publishedAt !== null) {
            $told = $this->tellEveryone($announcement, $bySms);
        }

        $this->showForm = false;
        $this->statusMessage = match (true) {
            $publishedAt === null => 'Saved as a draft. Nobody sees it until you post it.',
            $told > 0 && $bySms => 'Posted. ' . $told . ' ' . ($told === 1 ? 'person was' : 'people were')
                . ' emailed, and texted where a mobile number is on file.',
            $told > 0 => 'Posted, and ' . $told . ' ' . ($told === 1 ? 'person was' : 'people were') . ' emailed.',
            default => $this->editingId ? 'Announcement updated.' : 'Announcement posted.',
        };

        $this->reset(['editingId', 'title', 'body', 'isPinned', 'notifyEveryone', 'notifyBySms']);
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
    protected function tellEveryone(Announcement $announcement, bool $bySms = false): int
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
            $user->notify(new AnnouncementPosted($announcement, $bySms));
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
            'smsAvailable' => $canManage && $this->smsAvailable(),
            'smsLive' => $canManage && app(SmsGateway::class)->isLive(),
            // Exactly what the handset will show, cut and character-converted
            // by the same code that sends it. No guessing at the length.
            'smsPreview' => $canManage ? $this->smsPreview() : '',
            'smsReach' => $canManage ? $this->smsReach() : ['total' => 0, 'reachable' => 0],
            // Composed by the same code that sends it, so the count on screen
            // is the real one rather than a guess at the typed length.
            'blastPreview' => $canManage && trim($this->blastMessage) !== ''
                ? app(SmsGateway::class)->compose($this->blastMessage)
                : '',
            // Counts the chosen few when a few are chosen, so the confirmation
            // never says "all 52" next to a list of three ticks.
            'blastReach' => $canManage
                ? app(SmsBroadcaster::class)->reach($this->blastRecipients())
                : ['total' => 0, 'reachable' => 0],
            'blastStaff' => $canManage && $this->showBlast && $this->blastAudience === 'some'
                ? app(SmsBroadcaster::class)->selectableStaff($this->blastSearch)
                : collect(),
            'blasts' => $canManage ? SmsBroadcast::with('sentBy')->recent()->limit(5)->get() : collect(),
        ];
    }

    /** Whether announcements may be texted at all, per the Settings switch. */
    protected function smsAvailable(): bool
    {
        return SmsGateway::enabledFor(SmsGateway::URGENT_ANNOUNCEMENT);
    }

    protected function smsPreview(): string
    {
        if (trim($this->title) === '' && trim($this->body) === '') {
            return '';
        }

        return app(SmsGateway::class)->compose(
            SmsGateway::SENDER . ': ' . $this->title . '. ' . $this->body
        );
    }

    /**
     * How many staff a text would actually reach.
     *
     * Worth showing before sending, because the two numbers differ: the contact
     * number has never been a required field and a landline cannot receive a
     * text. "45 of 52" is the difference between believing everyone was told
     * and knowing seven were not.
     *
     * @return array{total: int, reachable: int}
     */
    protected function smsReach(): array
    {
        $numbers = Employee::query()
            ->whereNotNull('user_id')
            ->whereNull('separation_date')
            ->pluck('personal_contact_number');

        return [
            'total' => $numbers->count(),
            'reachable' => $numbers->filter(fn ($n) => PhoneNumber::isSendable($n))->count(),
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
            <div class="flex flex-wrap items-center gap-2">
                @if ($smsAvailable)
                    {{-- Its own button, because it is its own act: no board
                         entry, no email, no pin. Just phones. --}}
                    <x-button type="button" variant="secondary" wire:click="openBlast" @click="$dispatch('open-phrems-modal', 'showBlast')">
                        <x-icon name="phone" class="h-4 w-4" /> Send a Text
                    </x-button>
                @endif

            <x-button type="button" wire:click="create" @click="$dispatch('open-phrems-modal', 'showForm')">
                <x-icon name="plus" class="h-4 w-4" /> Post Announcement
            </x-button>
            </div>
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

    {{-- A text leaves no copy anywhere. Without this the only record that the
         company messaged everybody is on their handsets and a gateway bill. --}}
    @if ($canManage && $blasts->isNotEmpty())
        <x-card :padding="false">
            <div class="border-b border-ink-200 px-6 py-4 dark:border-white/10">
                <h2 class="text-base font-bold text-ink-950 dark:text-white">Texts Sent</h2>
                <p class="mt-0.5 text-sm font-medium text-ink-500 dark:text-ink-400">
                    Straight-to-phone messages. These were never posted or emailed.
                </p>
            </div>

            <div class="divide-y divide-ink-100 dark:divide-white/10">
                @foreach ($blasts as $blast)
                    <div class="px-6 py-4" wire:key="blast-{{ $blast->id }}">
                        <p class="break-words font-mono text-xs leading-relaxed text-ink-800 dark:text-ink-200">{{ $blast->message }}</p>
                        <p class="mt-2 text-xs font-semibold {{ $blast->reachedEverybody() ? 'text-ink-500 dark:text-ink-400' : 'text-amber-700 dark:text-amber-300' }}">
                            {{ $blast->audienceLabel() }} · {{ $blast->outcomeLabel() }}
                            · {{ $blast->created_at->format('M j, Y g:ia') }}
                            @if ($blast->sentBy)
                                · {{ $blast->sentBy->name }}
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    @if ($canManage)
        <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="2xl">
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
                    {{-- Held in Alpine as well as Livewire so the text option
                         appears the instant the box is ticked. Bound only to
                         wire:model.live it waited on a server round-trip, which
                         reads as the checkbox not working. --}}
                    <div class="contents" x-data="{ emailing: $wire.entangle('notifyEveryone').live }">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-400/20 dark:bg-amber-400/10">
                            <input type="checkbox" x-model="emailing" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm">
                                <span class="font-bold text-ink-800 dark:text-white">Email everybody as well</span>
                                <span class="mt-0.5 block font-medium text-ink-600 dark:text-ink-300">
                                    Sends this to every employee with an account, straight away.
                                </span>
                            </span>
                        </label>

                    {{-- Only offered on top of the email. A text is 160
                         characters nobody can go back and re-read, so the email
                         is what stays on file. --}}
                    @if ($smsAvailable)
                        <label x-show="emailing" x-cloak class="flex cursor-pointer items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-400/20 dark:bg-red-400/10">
                            <input type="checkbox" wire:model.live="notifyBySms" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                            <span class="min-w-0 text-sm">
                                <span class="font-bold text-ink-800 dark:text-white">Text everybody as well</span>
                                <span class="mt-0.5 block font-medium text-ink-600 dark:text-ink-300">
                                    Reaches people away from a screen.
                                </span>

                                @if ($notifyBySms)
                                    <span class="mt-2 block rounded-lg border border-red-200 bg-white p-2.5 dark:border-red-400/20 dark:bg-ink-900">
                                        <span class="block text-[11px] font-bold uppercase tracking-[0.12em] text-ink-500 dark:text-ink-400">
                                            What the phone will show — {{ mb_strlen($smsPreview) }}/{{ \App\Services\Sms\SmsGateway::SEGMENT }} characters
                                        </span>
                                        <span class="mt-1 block break-words font-mono text-xs leading-relaxed text-ink-800 dark:text-ink-200">
                                            {{ $smsPreview !== '' ? $smsPreview : 'Type a title and message above.' }}
                                        </span>
                                        <span class="mt-2 block text-xs font-semibold text-ink-600 dark:text-ink-300">
                                            Reaches {{ $smsReach['reachable'] }} of {{ $smsReach['total'] }} staff
                                            @if ($smsReach['total'] > $smsReach['reachable'])
                                                — {{ $smsReach['total'] - $smsReach['reachable'] }} have no usable mobile number on file
                                            @endif
                                        </span>
                                    </span>
                                @endif
                            </span>
                        </label>
                    @elseif ($canManage)
                        <p x-show="emailing" x-cloak class="text-xs font-medium text-ink-500 dark:text-ink-400">
                            To text this out as well, switch on <span class="font-bold">Allow announcements to be texted</span>
                            under Settings → Text Messages.
                        </p>
                    @endif
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-button>
                <x-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    {{ $publishNow ? ($editingId ? 'Save' : 'Post') : 'Save Draft' }}
                </x-button>
            </div>
        </x-modal>

        <x-modal wire="showBlast" onClose="$set('showBlast', false)" maxWidth="lg">
            <h2 class="text-lg font-bold text-ink-950 dark:text-white">Send a Text to Everybody</h2>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">
                Goes straight to phones. Nothing is posted to the board, nothing is emailed, and there is no
                copy anyone can go back and read — so say the whole thing here.
            </p>

            <div class="mt-5 space-y-4">
                <div>
                    <x-label>Send to</x-label>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach (['all' => 'Everybody', 'some' => 'Choose people'] as $key => $label)
                            <button type="button" wire:click="$set('blastAudience', '{{ $key }}')"
                                    class="rounded-lg border px-3 py-2 text-xs font-bold transition {{ $blastAudience === $key ? 'border-brand-700 bg-brand-700 text-white shadow-sm' : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($blastAudience === 'some')
                    <div>
                        <label class="directory-search relative block">
                            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                            <input type="text" wire:model.live.debounce.300ms="blastSearch" placeholder="Search staff…"
                                   class="block h-10 w-full rounded-lg border border-ink-200 bg-white pl-9 pr-3.5 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-400 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                        </label>

                        <div class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-ink-200 p-2 dark:border-white/10">
                            @forelse ($blastStaff as $staff)
                                @php $usable = \App\Services\Sms\PhoneNumber::isSendable($staff->personal_contact_number); @endphp
                                <label class="flex items-center gap-3 rounded-lg px-2 py-1.5 {{ $usable ? 'cursor-pointer hover:bg-ink-50 dark:hover:bg-white/5' : 'opacity-50' }}"
                                       wire:key="blast-staff-{{ $staff->id }}">
                                    <input type="checkbox" value="{{ $staff->id }}" wire:model.live="blastEmployeeIds"
                                           @disabled(! $usable)
                                           class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                                    <span class="min-w-0 text-sm">
                                        <span class="block truncate font-semibold text-ink-800 dark:text-white">{{ $staff->fullName() ?: $staff->employee_id }}</span>
                                        <span class="block truncate text-xs font-medium text-ink-500 dark:text-ink-400">
                                            {{ $usable ? \App\Services\Sms\PhoneNumber::mask($staff->personal_contact_number) : 'No usable mobile number on file' }}
                                        </span>
                                    </span>
                                </label>
                            @empty
                                <p class="px-2 py-6 text-center text-sm font-medium text-ink-500">Nobody matches that search.</p>
                            @endforelse
                        </div>
                        @error('blastEmployeeIds') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <x-label>Message</x-label>
                    <x-textarea wire:model.live.debounce.400ms="blastMessage" rows="4"
                                placeholder="e.g. Office closed today, flooding on Ortigas Ave. Work from home." />
                    @error('blastMessage') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-lg border border-ink-200 bg-ink-50 p-3 dark:border-white/10 dark:bg-white/5">
                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-ink-500 dark:text-ink-400">
                        What the phone will show — {{ mb_strlen($blastPreview) }}/{{ \App\Services\Sms\SmsGateway::SEGMENT }} characters
                    </p>
                    <p class="mt-1 break-words font-mono text-xs leading-relaxed text-ink-800 dark:text-ink-200">
                        {{ $blastPreview !== '' ? $blastPreview : 'Type a message above.' }}
                    </p>
                    <p class="mt-2 text-xs font-semibold text-ink-600 dark:text-ink-300">
                        Reaches {{ $blastReach['reachable'] }} of {{ $blastReach['total'] }}
                        {{ $blastAudience === 'all' ? 'staff' : 'chosen' }}
                        @if ($blastReach['total'] > $blastReach['reachable'])
                            — {{ $blastReach['total'] - $blastReach['reachable'] }} have no usable mobile number on file
                        @endif
                    </p>
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-400/20 dark:bg-red-400/10">
                    <input type="checkbox" wire:model="blastConfirmed" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                    <span class="text-sm">
                        <span class="font-bold text-ink-800 dark:text-white">
                            {{ $blastAudience === 'all'
                                ? 'Yes, text all ' . $blastReach['reachable'] . ' of them now'
                                : 'Yes, text the ' . $blastReach['reachable'] . ' ' . ($blastReach['reachable'] === 1 ? 'person' : 'people') . ' I chose' }}
                        </span>
                        <span class="mt-0.5 block font-medium text-ink-600 dark:text-ink-300">
                            This cannot be undone or recalled.
                        </span>
                    </span>
                </label>
                @error('blastConfirmed') <p class="text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-button type="button" variant="secondary" wire:click="$set('showBlast', false)">Cancel</x-button>
                <x-button type="button" variant="danger" wire:click="sendBlast" wire:loading.attr="disabled" wire:target="sendBlast">
                    <span wire:loading.remove wire:target="sendBlast">Send Text</span>
                    <span wire:loading wire:target="sendBlast">Sending…</span>
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
