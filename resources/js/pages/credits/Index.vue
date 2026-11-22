<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { SlidersHorizontal, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import BalancePanel from '@/components/generations/BalancePanel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as creditsIndex } from '@/routes/credits';
import { create, index as historyIndex } from '@/routes/generations';
import type { Balance, LedgerRow, Page } from '@/types';

type Filters = {
    from: string | null;
    to: string | null;
    min: string | null;
    max: string | null;
};

const props = defineProps<{
    balance: Balance;
    entries: Page<LedgerRow>;
    filters: Filters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: creditsIndex() }],
    },
});

const page = usePage();
const filtering = computed(() =>
    Object.values(props.filters).some((value) => value !== null),
);
const showFilters = ref(filtering.value);
const form = ref({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    min: props.filters.min ?? '',
    max: props.filters.max ?? '',
});

function apply(): void {
    // Only send the filters that are set.
    const query = Object.fromEntries(
        Object.entries(form.value).filter(([, value]) => value !== ''),
    );

    router.get(creditsIndex({ query }).url, {}, { preserveScroll: true });
}

function clear(): void {
    form.value = { from: '', to: '', min: '', max: '' };
    router.get(creditsIndex().url);
}

function localTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

// Money in is green, money spent is red. Holds and returned holds are
// reservations, not spending, so they stay muted.
function amountClass(row: LedgerRow): string {
    switch (row.type) {
        case 'purchase':
            return 'text-emerald-600 dark:text-emerald-400';
        case 'charge':
            return 'text-red-600 dark:text-red-400';
        default:
            return 'text-muted-foreground';
    }
}

const firstError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.from ?? errors?.to ?? errors?.min ?? errors?.max;
});
</script>

<template>
    <Head title="Transaction history" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Transaction history"
                description="Track your credit purchases and usage. Every row is one entry in your ledger; your balance is their sum."
            />
            <Button
                variant="outline"
                size="sm"
                :aria-pressed="showFilters"
                data-test="toggle-filters"
                @click="showFilters = !showFilters"
            >
                <SlidersHorizontal /> Filters
            </Button>
        </div>

        <BalancePanel :balance="balance" :show-history-link="false" />

        <form
            v-if="showFilters"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <Label for="from">From (UTC)</Label>
                <Input id="from" v-model="form.from" type="date" class="w-40" />
            </div>
            <div class="grid gap-1">
                <Label for="to">To (UTC)</Label>
                <Input id="to" v-model="form.to" type="date" class="w-40" />
            </div>
            <div class="grid gap-1">
                <Label for="min">Min amount ($)</Label>
                <Input
                    id="min"
                    v-model="form.min"
                    inputmode="decimal"
                    placeholder="Any"
                    class="w-28"
                />
            </div>
            <div class="grid gap-1">
                <Label for="max">Max amount ($)</Label>
                <Input
                    id="max"
                    v-model="form.max"
                    inputmode="decimal"
                    placeholder="Any"
                    class="w-28"
                />
            </div>
            <Button type="submit">Apply</Button>
            <Button
                v-if="filtering"
                type="button"
                variant="ghost"
                @click="clear"
            >
                Clear
            </Button>
        </form>
        <InputError :message="firstError" />

        <div
            v-if="entries.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center"
        >
            <p class="font-medium">
                {{
                    filtering
                        ? 'Nothing matches these filters'
                        : 'No transactions yet'
                }}
            </p>
            <p class="mb-4 text-sm text-muted-foreground">
                Your credit purchases and generations will show up here.
            </p>
            <Button v-if="!filtering" variant="outline" as-child>
                <Link :href="create()"><Sparkles /> Start generating</Link>
            </Button>
        </div>

        <!-- Phones: one row per entry, amount and balance on the right. -->
        <ul
            v-if="entries.data.length"
            class="divide-y rounded-xl border md:hidden"
        >
            <li
                v-for="row in entries.data"
                :key="row.id"
                class="flex items-start justify-between gap-3 p-3"
                data-test="ledger-item"
            >
                <div class="min-w-0">
                    <div class="text-sm font-medium">{{ row.label }}</div>
                    <Link
                        v-if="row.generationId && row.prompt"
                        :href="`${historyIndex().url}#generation-${row.generationId}`"
                        class="line-clamp-1 text-xs text-muted-foreground"
                    >
                        {{ row.prompt }}
                    </Link>
                    <time
                        :datetime="row.createdAt"
                        class="text-xs text-muted-foreground"
                        >{{ localTime(row.createdAt) }}</time
                    >
                </div>
                <div class="shrink-0 text-right tabular-nums">
                    <div
                        class="text-sm font-medium"
                        :class="amountClass(row)"
                        :title="row.amount.exact"
                    >
                        {{ row.amount.display }}
                    </div>
                    <div
                        class="text-xs text-muted-foreground"
                        :title="row.balanceAfter.exact"
                    >
                        Balance {{ row.balanceAfter.display }}
                    </div>
                </div>
            </li>
        </ul>

        <!-- Wider screens: the full table. -->
        <div
            v-if="entries.data.length"
            class="hidden overflow-x-auto rounded-xl border md:block"
        >
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Transaction</th>
                        <th class="px-3 py-2 text-right font-medium">Amount</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Balance after
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in entries.data"
                        :key="row.id"
                        class="border-t"
                        data-test="ledger-row"
                    >
                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            <time :datetime="row.createdAt">{{
                                localTime(row.createdAt)
                            }}</time>
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ row.label }}</div>
                            <Link
                                v-if="row.generationId && row.prompt"
                                :href="`${historyIndex().url}#generation-${row.generationId}`"
                                class="line-clamp-1 text-xs text-muted-foreground underline-offset-2 hover:underline"
                            >
                                {{ row.prompt }}
                            </Link>
                        </td>
                        <td
                            class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                            :class="amountClass(row)"
                            :title="row.amount.exact"
                        >
                            {{ row.amount.display }}
                        </td>
                        <td
                            class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                            :title="row.balanceAfter.exact"
                        >
                            {{ row.balanceAfter.display }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="entries.last_page > 1"
            class="flex items-center justify-between"
            aria-label="Pages"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!entries.prev_page_url"
                as-child
            >
                <Link
                    v-if="entries.prev_page_url"
                    :href="entries.prev_page_url"
                    preserve-scroll
                    >Newer</Link
                >
                <span v-else>Newer</span>
            </Button>
            <span class="text-sm text-muted-foreground">
                Page {{ entries.current_page }} of {{ entries.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!entries.next_page_url"
                as-child
            >
                <Link
                    v-if="entries.next_page_url"
                    :href="entries.next_page_url"
                    preserve-scroll
                    >Older</Link
                >
                <span v-else>Older</span>
            </Button>
        </nav>

        <p class="text-xs text-muted-foreground">
            When you generate, the most it can cost is held from your balance;
            when it finishes the hold is returned and the actual price is
            charged. Failed generations only have a hold and its return, so they
            cost nothing.
        </p>
    </div>
</template>
