window.datePicker = function (model) {
    const today = new Date();

    return {
        open: false,
        value: model,
        year: today.getFullYear(),
        month: today.getMonth(),
        monthNames: [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December',
        ],
        dayNames: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        init() {
            this.setCalendarFromValue();
            this.$watch('value', () => this.setCalendarFromValue());
        },
        normalizedValue() {
            return typeof this.value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(this.value)
                ? this.value
                : '';
        },
        calendarValue() {
            return this.normalizedValue() || new Date().toISOString().slice(0, 10);
        },
        setCalendarFromValue() {
            const parsed = new Date(this.calendarValue() + 'T00:00:00');

            if (Number.isNaN(parsed.getTime())) {
                const fallback = new Date();
                this.year = fallback.getFullYear();
                this.month = fallback.getMonth();
                return;
            }

            this.year = parsed.getFullYear();
            this.month = parsed.getMonth();
        },
        pad(number) {
            return String(number).padStart(2, '0');
        },
        iso(day) {
            return `${this.year}-${this.pad(this.month + 1)}-${this.pad(day)}`;
        },
        display() {
            if (!this.normalizedValue()) {
                return 'Select date';
            }

            const [year, month, day] = this.normalizedValue().split('-');

            return `${month} / ${day} / ${year}`;
        },
        firstDay() {
            return new Date(this.year, this.month, 1).getDay();
        },
        daysInMonth() {
            return new Date(this.year, this.month + 1, 0).getDate();
        },
        yearOptions() {
            const currentYear = new Date().getFullYear();
            const firstYear = currentYear - 100;
            const lastYear = currentYear + 20;

            return Array.from(
                { length: lastYear - firstYear + 1 },
                (_, index) => lastYear - index,
            );
        },
        previousMonth() {
            if (this.month === 0) {
                this.month = 11;
                this.year--;
                return;
            }

            this.month--;
        },
        nextMonth() {
            if (this.month === 11) {
                this.month = 0;
                this.year++;
                return;
            }

            this.month++;
        },
        select(day) {
            this.value = this.iso(day);
            this.open = false;
        },
        isSelected(day) {
            return this.normalizedValue() === this.iso(day);
        },
    };
};

let pageLoadingTimer;

document.addEventListener('livewire:navigate', () => {
    document.getElementById('page-content')?.classList.add('is-leaving');
    const loadingBar = document.getElementById('page-loading-bar');
    const skeleton = document.getElementById('page-skeleton');

    window.clearTimeout(pageLoadingTimer);
    loadingBar?.classList.remove('is-complete');
    loadingBar?.classList.add('is-visible');
    skeleton?.classList.add('is-visible');
});

document.addEventListener('livewire:navigated', () => {
    const page = document.getElementById('page-content');
    const loadingBar = document.getElementById('page-loading-bar');
    const skeleton = document.getElementById('page-skeleton');

    if (!page) {
        return;
    }

    if (!window.location.hash) {
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    }

    loadingBar?.classList.add('is-complete');
    loadingBar?.classList.remove('is-visible');
    skeleton?.classList.remove('is-visible');
    page.classList.remove('is-leaving');
    page.classList.add('is-entering');

    window.setTimeout(() => {
        page.classList.remove('is-entering');
    }, 240);

    pageLoadingTimer = window.setTimeout(() => {
        loadingBar?.classList.remove('is-complete');
    }, 320);
});

let activeActionRequests = 0;
let actionLoaderTimer;
let actionLoaderHideTimer;
let actionLoaderShownAt = 0;

const processingActionPattern = /^(?:logout|save|submit|send|approve|decline|delete|remove|withdraw|cancel|decide|amend|compute|finalize|unlock|generate|issue|revoke|assign|grant|revert|mark|timeIn|timeOut|startBreak|endBreak|toggleActive|toggleHold|toggleEligibility|refreshSlip|refreshSuggestion|openRun|addAdjustment|setSelectedAccess|next)/;

function isProcessingAction(call) {
    return typeof call.method === 'string' && processingActionPattern.test(call.method);
}

function actionCalls(payload) {
    try {
        const body = typeof payload === 'string' ? JSON.parse(payload) : payload;

        return (body?.components ?? [])
            .flatMap((component) => component.calls ?? [])
            .filter(isProcessingAction);
    } catch {
        return [];
    }
}

function actionLoaderElements() {
    const loader = document.getElementById('global-action-loader');

    return {
        loader,
        panel: loader?.querySelector('[data-action-loader-panel]'),
    };
}

function showActionLoader() {
    const { loader, panel } = actionLoaderElements();

    if (!loader || activeActionRequests === 0) {
        return;
    }

    window.clearTimeout(actionLoaderHideTimer);
    actionLoaderShownAt = Date.now();
    loader.setAttribute('aria-hidden', 'false');
    loader.classList.remove('pointer-events-none', 'opacity-0');
    loader.classList.add('pointer-events-auto', 'opacity-100');

    panel?.classList.remove('translate-y-2', 'scale-95', 'opacity-0');
    panel?.classList.add('translate-y-0', 'scale-100', 'opacity-100');
}

function hideActionLoader() {
    const { loader, panel } = actionLoaderElements();

    if (!loader) {
        return;
    }

    loader.setAttribute('aria-hidden', 'true');
    loader.classList.remove('pointer-events-auto', 'opacity-100');
    loader.classList.add('pointer-events-none', 'opacity-0');

    panel?.classList.remove('translate-y-0', 'scale-100', 'opacity-100');
    panel?.classList.add('translate-y-2', 'scale-95', 'opacity-0');
}

function scheduleActionLoader() {
    window.clearTimeout(actionLoaderTimer);
    actionLoaderTimer = window.setTimeout(showActionLoader, 160);
}

function finishActionRequest() {
    activeActionRequests = Math.max(0, activeActionRequests - 1);

    if (activeActionRequests > 0) {
        return;
    }

    window.clearTimeout(actionLoaderTimer);

    const elapsed = Date.now() - actionLoaderShownAt;
    const remaining = actionLoaderShownAt ? Math.max(0, 220 - elapsed) : 0;

    window.clearTimeout(actionLoaderHideTimer);
    actionLoaderHideTimer = window.setTimeout(() => {
        hideActionLoader();
        actionLoaderShownAt = 0;
    }, remaining);
}

document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ payload, succeed, fail }) => {
        if (actionCalls(payload).length === 0) {
            return;
        }

        activeActionRequests++;
        scheduleActionLoader();

        let finished = false;
        const finishOnce = () => {
            if (finished) {
                return;
            }

            finished = true;
            finishActionRequest();
        };

        succeed(finishOnce);
        fail(finishOnce);
    });
});

document.addEventListener('livewire:navigate', () => {
    activeActionRequests = 0;
    window.clearTimeout(actionLoaderTimer);
    window.clearTimeout(actionLoaderHideTimer);
    hideActionLoader();
});
