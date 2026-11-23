<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import EconomicsChart from '@/components/admin/EconomicsChart.vue';
import HeaderHint from '@/components/admin/HeaderHint.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report as reportRoute } from '@/routes/admin';
import { exportMethod as exportRoute } from '@/routes/admin/report';
import type { Report } from '@/types';

const props = defineProps<{
    from: string;
    to: string;
    maxDays: number;
    report: Report;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: reportRoute() }],
    },
});

// What each column means, shown on hover, focus or tap.
const hints = {
    day: 'The UTC day Pixelforge says the generation completed. That is how /v1/usage bills, so a generation finishing just after midnight counts on the next day.',
    generations:
        'Succeeded generations, the only kind that are billed. In brackets on each day: the count Pixelforge reports, for context.',
    spend: 'What users were charged: Pixelforge’s cost plus our 25% markup.',
    cost: 'What Pixelforge billed us for these generations, from token usage at each generation’s own rates.',
    profit: 'Credit spend minus provider cost.',
    pixelforgeCost:
        'Pixelforge’s own figure for the same day, from GET /v1/usage for the production key.',
    match: 'Whether our provider cost equals Pixelforge’s to the nanodollar. A mismatch means one of us billed something the other has no record of.',
};

const page = usePage();

// The range on screen, not the one being typed, so the file matches.
const exportUrl = computed(
    () => exportRoute({ query: { from: props.from, to: props.to } }).url,
);
const from = ref(props.from);
const to = ref(props.to);

function apply(): void {
    router.get(
        reportRoute({ query: { from: from.value, to: to.value } }).url,
        {},
        { preserveScroll: true },
    );
}

function matchLabel(matches: boolean | null): string {
    return matches === null ? '—' : matches ? '✓ Matches' : '✗ Mismatch';
}

function matchClass(matches: boolean | null): string {
    if (matches === null) {
        return 'text-muted-foreground';
    }

    return matches
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'font-semibold text-red-600 dark:text-red-400';
}
</script>

<template>
    <Head title="Admin report" />

    <div class="flex min-w-0 flex-col gap-6 p-4">
        <Heading
            title="Admin report"
            description="Billed generations per UTC day of completion and per user, exact to the nanodollar, checked against Pixelforge's own usage."
        />

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div class="grid gap-1">
                <Label for="from">From (UTC)</Label>
                <Input id="from" v-model="from" type="date" class="w-40" />
            </div>
            <div class="grid gap-1">
                <Label for="to">To (UTC)</Label>
                <Input id="to" v-model="to" type="date" class="w-40" />
            </div>
            <Button type="submit">Show</Button>
            <!-- A plain link: the response is a file, not a page. -->
            <Button variant="outline" as-child>
                <a :href="exportUrl" data-test="export-csv">
                    <Download /> Export CSV
                </a>
            </Button>
            <span class="text-xs text-muted-foreground"
                >Up to {{ maxDays }} days, both ends included.</span
            >
        </form>
        <InputError
            :message="page.props.errors?.from ?? page.props.errors?.to"
        />

        <p
            v-if="report.pixelforgeError"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            role="alert"
        >
            {{ report.pixelforgeError }}
        </p>

        <dl
            class="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-3 lg:grid-cols-6"
            data-test="totals"
        >
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    <HeaderHint label="Generations" :hint="hints.generations" />
                </dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.totals.generations }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    <HeaderHint label="Credit spend" :hint="hints.spend" />
                </dt>
                <dd class="font-semibold break-all tabular-nums">
                    {{ report.totals.spend }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    <HeaderHint label="Provider cost" :hint="hints.cost" />
                </dt>
                <dd class="font-semibold break-all tabular-nums">
                    {{ report.totals.cost }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    <HeaderHint label="Profit" :hint="hints.profit" />
                </dt>
                <dd
                    class="font-semibold break-all text-emerald-600 tabular-nums dark:text-emerald-400"
                >
                    {{ report.totals.profit }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    <HeaderHint
                        label="Pixelforge cost"
                        :hint="hints.pixelforgeCost"
                    />
                </dt>
                <dd class="font-semibold break-all tabular-nums">
                    {{ report.totals.pixelforge?.cost ?? '—' }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    <HeaderHint label="Reconciled" :hint="hints.match" />
                </dt>
                <dd
                    :class="matchClass(report.totals.matches)"
                    data-test="total-match"
                >
                    {{ matchLabel(report.totals.matches) }}
                </dd>
            </div>
        </dl>

        <EconomicsChart :totals="report.totals" />

        <p
            v-if="report.days.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-muted-foreground"
        >
            No billed generations in this range.
        </p>

        <template v-else>
            <!-- Phones: one card per day. -->
            <ul class="grid gap-3 md:hidden">
                <li
                    v-for="day in report.days"
                    :key="day.date"
                    class="grid gap-3 rounded-xl border p-4"
                    data-test="day-card"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="font-medium">{{ day.date }}</span>
                        <span
                            class="text-sm whitespace-nowrap"
                            :class="matchClass(day.matches)"
                            >{{ matchLabel(day.matches) }}</span
                        >
                    </div>
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm tabular-nums"
                    >
                        <dt class="text-muted-foreground">Generations</dt>
                        <dd class="text-right">
                            {{ day.generations }}
                            <span
                                v-if="day.pixelforge"
                                class="text-xs text-muted-foreground"
                                >({{ day.pixelforge.generations }} at
                                Pixelforge)</span
                            >
                        </dd>
                        <dt class="text-muted-foreground">Credit spend</dt>
                        <dd class="text-right">{{ day.spend }}</dd>
                        <dt class="text-muted-foreground">Provider cost</dt>
                        <dd class="text-right">{{ day.cost }}</dd>
                        <dt class="text-muted-foreground">Profit</dt>
                        <dd
                            class="text-right text-emerald-600 dark:text-emerald-400"
                        >
                            {{ day.profit }}
                        </dd>
                        <dt class="text-muted-foreground">Pixelforge cost</dt>
                        <dd class="text-right">
                            {{ day.pixelforge?.cost ?? '—' }}
                        </dd>
                    </dl>
                    <ul
                        v-if="day.users.length"
                        class="grid gap-1 border-t pt-2 text-xs text-muted-foreground"
                    >
                        <li
                            v-for="user in day.users"
                            :key="`${day.date}-${user.id}`"
                            class="flex justify-between gap-2"
                        >
                            <span class="truncate" :title="user.email"
                                >{{ user.name }} · {{ user.generations }}</span
                            >
                            <span class="tabular-nums">{{ user.spend }}</span>
                        </li>
                    </ul>
                </li>
            </ul>

            <!-- Wider screens: the full table. -->
            <div class="hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">
                                <HeaderHint
                                    label="Day / user"
                                    :hint="hints.day"
                                />
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                <HeaderHint
                                    label="Generations"
                                    :hint="hints.generations"
                                    align="end"
                                />
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                <HeaderHint
                                    label="Credit spend"
                                    :hint="hints.spend"
                                    align="end"
                                />
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                <HeaderHint
                                    label="Provider cost"
                                    :hint="hints.cost"
                                    align="end"
                                />
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                <HeaderHint
                                    label="Profit"
                                    :hint="hints.profit"
                                    align="end"
                                />
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                <HeaderHint
                                    label="Pixelforge cost"
                                    :hint="hints.pixelforgeCost"
                                    align="end"
                                />
                            </th>
                            <th class="px-3 py-2 font-medium">
                                <HeaderHint label="Match" :hint="hints.match" />
                            </th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <template v-for="day in report.days" :key="day.date">
                            <tr
                                class="border-t bg-muted/20 font-medium"
                                data-test="day"
                            >
                                <td class="px-3 py-2">{{ day.date }}</td>
                                <td class="px-3 py-2 text-right">
                                    {{ day.generations }}
                                    <span
                                        v-if="day.pixelforge"
                                        class="text-xs text-muted-foreground"
                                    >
                                        ({{ day.pixelforge.generations }} at
                                        Pixelforge)
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ day.spend }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ day.cost }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400"
                                >
                                    {{ day.profit }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ day.pixelforge?.cost ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 whitespace-nowrap"
                                    :class="matchClass(day.matches)"
                                >
                                    {{ matchLabel(day.matches) }}
                                </td>
                            </tr>
                            <tr
                                v-for="user in day.users"
                                :key="`${day.date}-${user.id}`"
                                class="border-t text-muted-foreground"
                            >
                                <td
                                    class="py-1.5 pr-3 pl-7"
                                    :title="user.email"
                                >
                                    {{ user.name }}
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    {{ user.generations }}
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    {{ user.spend }}
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    {{ user.cost }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-emerald-600 dark:text-emerald-400"
                                >
                                    {{ user.profit }}
                                </td>
                                <td class="px-3 py-1.5" colspan="2" />
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="text-xs text-muted-foreground">
            A generation counts on the UTC day Pixelforge says it completed, as
            /v1/usage bills it. Only succeeded generations are billed. Matching
            compares cost; Pixelforge's generation count is shown for context.
        </p>
    </div>
</template>
