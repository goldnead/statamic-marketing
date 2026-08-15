<script setup>
/**
 * The one chart this addon draws, in three places.
 *
 * Hand-drawn, and deliberately: the Control Panel's CSP allows no CDN script,
 * a charting library is a dependency the whole addon would carry for three
 * pictures, and `goldnead/statamic-automations` already draws its 14-day trend
 * this way — this is the house form, not an invention. It is CSS boxes rather
 * than SVG for one more reason: `ui.inline-svg-icons` reports an inline vector
 * element anywhere in a CP template, and a chart is not worth spending the
 * addon's lint score on. (The rule scans text, so naming the tag in this
 * sentence would report it too — which is why the sentence does not.)
 *
 * The shape is a column per time bucket, one or more bars per column, one or
 * more stacked segments per bar:
 *
 *     groups: [{ key, xLabel, ariaLabel, stacks: [[{series, value}, …], …] }]
 *     series: [{ key, label, class }]
 *
 * A segment names its series and the series carries the colour, so a legend is
 * never a second list that can drift from the bars.
 *
 * Two things are load-bearing rather than decorative:
 *
 *  - **Every column is announced.** The bars carry no text at all, so without
 *    a per-column label a screen reader finds an empty group where the chart
 *    is. `ariaLabel` is the column as a sentence, and `summary` is the whole
 *    chart as one — the statement somebody who cannot see it should walk away
 *    with.
 *  - **The scale never divides by zero.** A campaign nobody opened and a week
 *    nobody joined are ordinary, and a chart that renders `NaN%` for them
 *    looks broken in exactly the situation where the truthful answer is
 *    "nothing happened yet".
 */
import { computed } from 'vue';

const props = defineProps({
    /** Named for the reader: what the chart as a whole is. */
    label: { type: String, required: true },
    /** The chart's message in one sentence, for anybody who cannot see it. */
    summary: { type: String, default: '' },
    /** [{ key, label, class }] — class is the bar colour, in both themes. */
    series: { type: Array, required: true },
    /** [{ key, xLabel, ariaLabel, stacks }] */
    groups: { type: Array, required: true },
    /** Track height. A Tailwind class so the caller keeps the say. */
    height: { type: String, default: 'h-36' },
    /**
     * The top of the axis, where the caller knows it better than the data does.
     *
     * Left unset the axis is the tallest stack, which is right wherever the
     * stacks are commensurable. They are not always: on the activity curve one
     * series is Apple's proxy fetching the pixel for half a campaign inside a
     * single hour, and sharing an axis with it squashes ten hours of actual
     * reading into three pixels of hairline — the tallest human hour lands at
     * a tenth of the axis, a typical one at a fiftieth, and the difference
     * between two of them is a rounding error. A ceiling set to the human
     * maximum gives that question its axis back and lets the machine bar run
     * into the ceiling, which is the honest picture: it does not fit. What the
     * bar then no longer says, the caller has to say in words.
     */
    max: { type: Number, default: null },
});

/**
 * Two pixels' worth of a 144px track.
 *
 * Below roughly this, a bar is a subpixel line nobody can see, and the chart
 * would be claiming an empty bucket where there was one open. A hairline is
 * lifted to the floor rather than left honest-but-invisible, because the
 * distinction the reader needs here is "something/nothing" long before it is
 * "how much".
 */
const MIN_SHARE = 0.015;

/** The top of the axis: the caller's ceiling, or the tallest stack. Never zero. */
const scale = computed(() => {
    if (Number(props.max) > 0) return Number(props.max);

    const totals = props.groups.flatMap((group) =>
        group.stacks.map((stack) => stack.reduce((sum, segment) => sum + (Number(segment.value) || 0), 0))
    );

    return Math.max(1, ...totals);
});

const colours = computed(() => Object.fromEntries(props.series.map((s) => [s.key, s.class])));

/**
 * One stack as drawn: top segment first, heights already worked out.
 *
 * The arithmetic is here rather than in CSS, and that is the load-bearing part
 * of it. Clamping each segment to 100% on its own is enough only while the
 * axis is the tallest stack — with a ceiling the caller chose, a stack may
 * exceed it, and flex children whose percentage heights add up to more than
 * the track do not get cut off: they SHRINK, proportionally, all of them. The
 * stack would quietly rescale itself and every value in it would be wrong,
 * with nothing on the screen to show for it. `overflow-hidden` is no answer
 * either — the segments are rendered top-down, so it is the wrong end that
 * would go.
 *
 * So the stack is filled from the bottom up, each segment gets what is left
 * under the ceiling, and the one that runs out of room is the one that is
 * shortened. Segments below it keep exactly the height they would have had
 * without the overshoot.
 */
function stackSegments(stack) {
    const cap = scale.value;
    let used = 0;

    const drawn = stack.map((segment) => {
        const value = Math.max(0, Number(segment.value) || 0);
        const room = Math.max(0, cap - used);
        used += value;

        return { series: segment.series, share: Math.min(value, room) / cap };
    });

    for (const segment of drawn) {
        if (segment.share > 0 && segment.share < MIN_SHARE) segment.share = MIN_SHARE;
    }

    // Lifting a hairline can push a stack that already filled the track over
    // it, which is the shrink above by another road. The tallest segment pays
    // for it: it is the one where a fraction of a percent cannot be read off
    // the picture anyway.
    const total = drawn.reduce((sum, segment) => sum + segment.share, 0);

    if (total > 1) {
        const tallest = drawn.reduce((a, b) => (b.share > a.share ? b : a));
        tallest.share = Math.max(0, tallest.share - (total - 1));
    }

    // The array is written bottom-up because that is how a stack is read; a
    // `flex-col` renders its first child at the top, so it is turned over here
    // rather than at every call site.
    return drawn.reverse();
}

function barHeight(share) {
    return `${+(share * 100).toFixed(2)}%`;
}
</script>

<template>
    <div>
        <p v-if="summary" class="sr-only">{{ summary }}</p>

        <!-- `max-w-24` on the columns, and it earns its place at the other end
             of the range from the one this component was drawn for: `flex-1`
             alone gives seventy-two buckets fifteen pixels each, and gives a
             single campaign the entire width of the panel — one bar as wide as
             a hand, which reads as a design accident rather than as one
             measurement. Capped, a lone column is a bar and the row simply
             starts on the left. -->
        <div class="flex items-end gap-px" role="list" :aria-label="label">
            <div
                v-for="group in groups"
                :key="group.key"
                role="listitem"
                :aria-label="group.ariaLabel"
                :title="group.ariaLabel"
                class="flex min-w-0 max-w-24 flex-1 flex-col items-center gap-1"
            >
                <div class="flex w-full items-end justify-center gap-px" :class="height">
                    <!-- The track behind each bar. It is what makes an empty
                         bucket visible AS an empty bucket: without it a week
                         nobody joined is indistinguishable from a week that
                         was never drawn. -->
                    <div
                        v-for="(stack, index) in group.stacks"
                        :key="index"
                        class="flex h-full min-w-0 flex-1 flex-col justify-end overflow-hidden rounded-t-sm bg-gray-50 dark:bg-gray-800"
                        aria-hidden="true"
                    >
                        <div
                            v-for="segment in stackSegments(stack)"
                            :key="segment.series"
                            :class="colours[segment.series]"
                            :style="{ height: barHeight(segment.share) }"
                        ></div>
                    </div>
                </div>
                <!-- Ticks overflow their column rather than being cut off by
                     it. A column is a bar wide and a label is not, so
                     `truncate` here turned every tick into "01:00…"; the
                     caller labels roughly every eighth column, so a label has
                     its neighbours' width to spread into. -->
                <div
                    class="w-full whitespace-nowrap text-center text-[10px] tabular-nums text-gray-400"
                    aria-hidden="true"
                >{{ group.xLabel }}</div>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
            <span v-for="item in series" :key="item.key" class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm" :class="item.class"></span>{{ item.label }}
            </span>
        </div>
    </div>
</template>
