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
                : new Date().toISOString().slice(0, 10);
        },
        setCalendarFromValue() {
            const parsed = new Date(this.normalizedValue() + 'T00:00:00');

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
