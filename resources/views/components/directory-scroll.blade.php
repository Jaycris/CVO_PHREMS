<div
    x-data="{
        hasOverflow: false,
        tableWidth: 0,
        floatingVisible: false,
        floatingLeft: 0,
        floatingWidth: 0,
        syncing: false,
        resizeObserver: null,
        mutationObserver: null,
        positionListener: null,
        init() {
            this.positionListener = () => this.updatePosition();

            this.$nextTick(() => {
                this.updatePosition();

                this.resizeObserver = new ResizeObserver(this.positionListener);
                this.resizeObserver.observe(this.$refs.tableScroller);

                if (this.$refs.tableScroller.firstElementChild) {
                    this.resizeObserver.observe(this.$refs.tableScroller.firstElementChild);
                }

                this.mutationObserver = new MutationObserver(this.positionListener);
                this.mutationObserver.observe(this.$refs.tableScroller, {
                    childList: true,
                    subtree: true,
                });

                window.addEventListener('scroll', this.positionListener, { passive: true });
                window.addEventListener('resize', this.positionListener, { passive: true });
            });
        },
        destroy() {
            this.resizeObserver?.disconnect();
            this.mutationObserver?.disconnect();
            window.removeEventListener('scroll', this.positionListener);
            window.removeEventListener('resize', this.positionListener);
        },
        updatePosition() {
            this.$nextTick(() => {
                const scroller = this.$refs.tableScroller;
                const floatingScroller = this.$refs.floatingScroller;

                if (! scroller || ! floatingScroller) return;

                const bounds = scroller.getBoundingClientRect();
                const left = Math.max(0, bounds.left);
                const right = Math.min(window.innerWidth, bounds.right);

                this.tableWidth = scroller.scrollWidth;
                this.hasOverflow = scroller.scrollWidth > scroller.clientWidth + 1;
                this.floatingLeft = Math.round(left);
                this.floatingWidth = Math.max(0, Math.round(right - left));
                this.floatingVisible = this.hasOverflow
                    && bounds.bottom > 0
                    && bounds.top < window.innerHeight
                    && this.floatingWidth > 0;

                if (! this.syncing && floatingScroller.scrollLeft !== scroller.scrollLeft) {
                    floatingScroller.scrollLeft = scroller.scrollLeft;
                }
            });
        },
        syncScroll(source, target) {
            if (this.syncing) return;

            this.syncing = true;
            target.scrollLeft = source.scrollLeft;
            requestAnimationFrame(() => this.syncing = false);
        },
    }"
>
    <div
        x-ref="tableScroller"
        @scroll.passive="syncScroll($refs.tableScroller, $refs.floatingScroller)"
        class="overflow-x-auto"
    >
        {{ $slot }}
    </div>

    <template x-teleport="body">
        <div
            x-cloak
            x-show="floatingVisible"
            x-transition.opacity.duration.150ms
            x-ref="floatingScroller"
            @scroll.passive="syncScroll($refs.floatingScroller, $refs.tableScroller)"
            :style="{
                left: floatingLeft + 'px',
                width: floatingWidth + 'px',
            }"
            class="fixed bottom-3 z-40 h-5 overflow-x-auto overflow-y-hidden rounded-md border border-ink-300 bg-white/95 shadow-lg backdrop-blur-sm dark:border-white/20 dark:bg-ink-900/95"
            role="region"
            tabindex="0"
            aria-label="Floating horizontal table scroll"
        >
            <div class="h-px" :style="{ width: tableWidth + 'px' }"></div>
        </div>
    </template>
</div>
