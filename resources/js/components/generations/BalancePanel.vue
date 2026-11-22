<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BuyCreditsDialog from '@/components/generations/BuyCreditsDialog.vue';
import Price from '@/components/generations/Price.vue';
import { Button } from '@/components/ui/button';
import { index as creditsIndex } from '@/routes/credits';
import type { Balance } from '@/types';

// Vue treats a missing boolean prop as false, so default it explicitly.
withDefaults(defineProps<{ balance: Balance; showHistoryLink?: boolean }>(), {
    showHistoryLink: true,
});
</script>

<template>
    <section
        class="flex flex-wrap items-end justify-between gap-4 rounded-xl border p-4"
        aria-label="Balance"
    >
        <dl class="flex gap-8">
            <div>
                <dt class="text-sm text-muted-foreground">Available</dt>
                <dd class="text-2xl font-semibold" data-test="available">
                    <Price :money="balance.available" />
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    Held for running generations
                </dt>
                <dd
                    class="text-2xl font-semibold text-muted-foreground"
                    data-test="held"
                >
                    <Price :money="balance.held" />
                </dd>
            </div>
        </dl>

        <div class="flex items-center gap-2">
            <Button v-if="showHistoryLink" variant="ghost" size="sm" as-child>
                <Link :href="creditsIndex()">Transaction history</Link>
            </Button>
            <BuyCreditsDialog :balance="balance" />
        </div>
    </section>
</template>
