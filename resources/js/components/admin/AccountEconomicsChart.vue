<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ReportAccount } from '@/types';

/*
 * One bar per account: its length is what the account spent (credits
 * charged), split into what Pixelforge cost us and the profit on top.
 * Cost + profit = spend, so a stacked bar shows all three at once on a
 * single dollar axis.
 *
 * Colours are slots 1 and 2 of the validated categorical palette, checked
 * with the dataviz validator against this app's light (#fff) and dark
 * (#0a0a0a) surfaces: CVD ΔE 24.7 / 26.8, contrast ≥ 3:1. Identity is
 * never colour alone: a legend, the spend label, and hover text.
 */
const props = defineProps<{ accounts: ReportAccount[] }>();

const maxSpend = computed(() =>
    Math.max(1, ...props.accounts.map((account) => account.spendNanos)),
);

// Bar widths only: lengths are proportions, the figures shown are the
// server's exact strings.
function width(nanos: number): string {
    return `${(Math.max(0, nanos) / maxSpend.value) * 100}%`;
}

function share(part: number, whole: number): string {
    return whole > 0 ? `${Math.round((part / whole) * 100)}%` : '—';
}
</script>

<template>
    <section
        class="viz-economics grid gap-4 rounded-xl border p-4"
        aria-labelledby="economics-title"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 id="economics-title" class="font-medium">
                    Economics by account
                </h3>
                <p class="text-xs text-muted-foreground">
                    Bar length is credit spend for the range: what Pixelforge
                    cost us, plus our profit.
                </p>
            </div>
            <ul
                class="flex gap-4 text-xs text-muted-foreground"
                aria-label="Legend"
            >
                <li class="flex items-center gap-1.5">
                    <span
                        class="size-2.5 rounded-sm"
                        style="background: var(--series-cost)"
                    />
                    Provider cost
                </li>
                <li class="flex items-center gap-1.5">
                    <span
                        class="size-2.5 rounded-sm"
                        style="background: var(--series-profit)"
                    />
                    Profit
                </li>
            </ul>
        </div>

        <ol class="grid gap-3" aria-hidden="true">
            <li
                v-for="account in accounts"
                :key="account.id"
                class="grid gap-1 sm:grid-cols-[10rem_1fr] sm:items-center sm:gap-3"
                data-test="economics-row"
            >
                <span class="truncate text-sm" :title="account.email">{{
                    account.name
                }}</span>
                <div class="flex items-center gap-2">
                    <div class="flex h-5 min-w-0 flex-1 items-stretch">
                        <div
                            class="flex h-full gap-[2px]"
                            :style="{ width: width(account.spendNanos) }"
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <div
                                        class="h-full min-w-[2px] cursor-default"
                                        :class="
                                            account.profitNanos > 0
                                                ? ''
                                                : 'rounded-r'
                                        "
                                        :style="{
                                            flexGrow: Math.max(
                                                account.costNanos,
                                                0,
                                            ),
                                            background: 'var(--series-cost)',
                                        }"
                                    />
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ account.name }} · provider cost
                                    {{ account.cost }} ({{
                                        share(
                                            account.costNanos,
                                            account.spendNanos,
                                        )
                                    }}
                                    of spend)
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip v-if="account.profitNanos > 0">
                                <TooltipTrigger as-child>
                                    <div
                                        class="h-full min-w-[2px] cursor-default rounded-r"
                                        :style="{
                                            flexGrow: account.profitNanos,
                                            background: 'var(--series-profit)',
                                        }"
                                    />
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ account.name }} · profit
                                    {{ account.profit }} ({{
                                        share(
                                            account.profitNanos,
                                            account.spendNanos,
                                        )
                                    }}
                                    of spend)
                                </TooltipContent>
                            </Tooltip>
                        </div>
                    </div>
                    <span
                        class="w-24 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                    >
                        {{ account.spend }}
                    </span>
                </div>
            </li>
        </ol>

        <!-- The same figures for screen readers. -->
        <table class="sr-only">
            <caption>
                Economics by account
            </caption>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Generations</th>
                    <th>Credit spend</th>
                    <th>Provider cost</th>
                    <th>Profit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="account in accounts" :key="account.id">
                    <td>{{ account.name }}</td>
                    <td>{{ account.generations }}</td>
                    <td>{{ account.spend }}</td>
                    <td>{{ account.cost }}</td>
                    <td>{{ account.profit }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>

<style>
/* Light and dark steps of the same two palette slots; the kit puts .dark on
   <html> for dark mode (system or chosen). */
.viz-economics {
    --series-cost: #2a78d6;
    --series-profit: #eb6834;
}

.dark .viz-economics {
    --series-cost: #3987e5;
    --series-profit: #d95926;
}
</style>
