<div
    x-data="{
        hasOverflow: false,
        tableWidth: 0,
        syncing: false,
        updateWidth() {
            this.$nextTick(() => {
                const scroller = this.$refs.tableScroller;
                this.tableWidth = scroller.scrollWidth;
                this.hasOverflow = scroller.scrollWidth > scroller.clientWidth + 1;
            });
        },
        syncScroll(source, target) {
            if (this.syncing) return;

            this.syncing = true;
            target.scrollLeft = source.scrollLeft;
            requestAnimationFrame(() => this.syncing = false);
        },
    }"
    x-init="$nextTick(() => {
        updateWidth();

        const observer = new ResizeObserver(() => updateWidth());
        observer.observe($refs.tableScroller);

        if ($refs.tableScroller.firstElementChild) {
            observer.observe($refs.tableScroller.firstElementChild);
        }
    })"
>
    <div
        x-cloak
        x-show="hasOverflow"
        x-ref="topScroller"
        @scroll="syncScroll($refs.topScroller, $refs.tableScroller)"
        class="h-4 overflow-x-auto overflow-y-hidden border-b border-ink-200 bg-ink-50/70 dark:border-white/10 dark:bg-white/[0.03]"
        aria-label="Horizontal table scroll"
    >
        <div class="h-px" :style="{ width: tableWidth + 'px' }"></div>
    </div>

    <div
        x-ref="tableScroller"
        @scroll="syncScroll($refs.tableScroller, $refs.topScroller)"
        class="overflow-x-auto"
    >
        {{ $slot }}
    </div>
</div>