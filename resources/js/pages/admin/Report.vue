<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report as reportRoute } from '@/routes/admin';
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

const page = usePage();
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

    <div class="flex flex-col gap-6 p-4">
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
            <div>
                <dt class="text-xs text-muted-foreground">Generations</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.totals.generations }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Credit spend</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.totals.spend }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Provider cost</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.totals.cost }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Profit</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.totals.profit }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Pixelforge cost</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.totals.pixelforge?.cost ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Reconciled</dt>
                <dd
                    :class="matchClass(report.totals.matches)"
                    data-test="total-match"
                >
                    {{ matchLabel(report.totals.matches) }}
                </dd>
            </div>
        </dl>

        <p
            v-if="report.days.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-muted-foreground"
        >
            No billed generations in this range.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">Day / user</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Credit spend
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Profit</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Pixelforge cost
                        </th>
                        <th class="px-3 py-2 font-medium">Match</th>
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
                            <td class="px-3 py-2 text-right">{{ day.cost }}</td>
                            <td class="px-3 py-2 text-right">
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
                            <td class="py-1.5 pr-3 pl-7" :title="user.email">
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
                            <td class="px-3 py-1.5 text-right">
                                {{ user.profit }}
                            </td>
                            <td class="px-3 py-1.5" colspan="2" />
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            A generation counts on the UTC day Pixelforge says it completed, as
            /v1/usage bills it. Only succeeded generations are billed. Matching
            compares cost; Pixelforge's generation count is shown for context.
        </p>
    </div>
</template>
