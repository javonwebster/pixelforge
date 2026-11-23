<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ReportTotals } from '@/types';

/*
 * One bar for all accounts together over the range: its full length is
 * the credit spend, split into what Pixelforge cost us and the profit on
 * top. Cost + profit = spend, so the split shows all three at once.
 *
 * Colours: the palette's blue for cost and its green for profit (green is
 * profit everywhere on this page), checked with the dataviz validator
 * against this app's light (#fff) and dark (#0a0a0a) surfaces: CVD ΔE
 * 26.5 / 27.9, contrast ≥ 3:1. Their tritan separation is lower (7.6 /
 * 5.1), so identity is never colour alone: a legend with the figures, a
 * 2px gap between segments, and hover text.
 */
defineProps<{ totals: ReportTotals }>();

function share(part: number, whole: number): string {
    return whole > 0 ? `${Math.round((part / whole) * 100)}%` : '—';
}
</script>

<template>
    <section
        class="viz-economics grid gap-4 rounded-xl border p-4"
        aria-labelledby="economics-title"
        data-test="economics"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 id="economics-title" class="font-medium">
                    Economics, all accounts
                </h3>
                <p class="text-xs text-muted-foreground">
                    The whole bar is credit spend for the range: what Pixelforge
                    cost us, plus our profit.
                </p>
            </div>
            <span class="text-sm font-medium tabular-nums">{{
                totals.spend
            }}</span>
        </div>

        <div
            class="flex h-6 items-stretch overflow-hidden rounded bg-muted/40"
            aria-hidden="true"
            data-test="economics-bar"
        >
            <div
                v-if="totals.spendNanos > 0"
                class="flex h-full w-full gap-[2px]"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <div
                            class="h-full min-w-[2px] cursor-default"
                            :style="{
                                flexGrow: Math.max(totals.costNanos, 0),
                                background: 'var(--series-cost)',
                            }"
                        />
                    </TooltipTrigger>
                    <TooltipContent>
                        Provider cost {{ totals.cost }} ({{
                            share(totals.costNanos, totals.spendNanos)
                        }}
                        of spend)
                    </TooltipContent>
                </Tooltip>
                <Tooltip v-if="totals.profitNanos > 0">
                    <TooltipTrigger as-child>
                        <div
                            class="h-full min-w-[2px] cursor-default"
                            :style="{
                                flexGrow: totals.profitNanos,
                                background: 'var(--series-profit)',
                            }"
                        />
                    </TooltipTrigger>
                    <TooltipContent>
                        Profit {{ totals.profit }} ({{
                            share(totals.profitNanos, totals.spendNanos)
                        }}
                        of spend)
                    </TooltipContent>
                </Tooltip>
            </div>
        </div>

        <!-- Legend, with the figures: also what screen readers get. -->
        <ul
            class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted-foreground"
            aria-label="Credit spend split"
        >
            <li class="flex items-center gap-1.5">
                <span
                    class="size-2.5 rounded-sm"
                    style="background: var(--series-cost)"
                    aria-hidden="true"
                />
                Provider cost
                <span class="text-foreground tabular-nums">{{
                    totals.cost
                }}</span>
                <span class="tabular-nums"
                    >({{ share(totals.costNanos, totals.spendNanos) }})</span
                >
            </li>
            <li class="flex items-center gap-1.5">
                <span
                    class="size-2.5 rounded-sm"
                    style="background: var(--series-profit)"
                    aria-hidden="true"
                />
                Profit
                <span class="text-foreground tabular-nums">{{
                    totals.profit
                }}</span>
                <span class="tabular-nums"
                    >({{ share(totals.profitNanos, totals.spendNanos) }})</span
                >
            </li>
        </ul>
    </section>
</template>

<style>
/* Light and dark steps of the palette's blue and green; the kit puts .dark
   on <html> for dark mode (system or chosen). */
.viz-economics {
    --series-cost: #2a78d6;
    --series-profit: #008300;
}

.dark .viz-economics {
    --series-cost: #3987e5;
    --series-profit: #0ca30c;
}
</style>
