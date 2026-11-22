<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import CreditController from '@/actions/App/Http/Controllers/CreditController';
import Price from '@/components/generations/Price.vue';
import { Button } from '@/components/ui/button';
import type { Balance } from '@/types';

defineProps<{ balance: Balance }>();

const amounts = [5, 10, 20];
const form = useForm({ amount: 0 });

function buy(amount: number): void {
    // The buttons are disabled while a purchase is in flight, but a fast
    // double click can land before Vue re-renders, so check here too.
    if (form.processing) {
        return;
    }

    form.amount = amount;
    form.submit(CreditController.store(), { preserveScroll: true });
}
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
            <span class="text-sm text-muted-foreground">Buy credits</span>
            <Button
                v-for="amount in amounts"
                :key="amount"
                variant="outline"
                size="sm"
                :disabled="form.processing"
                :data-test="`buy-${amount}`"
                @click="buy(amount)"
            >
                ${{ amount }}
            </Button>
        </div>
    </section>
</template>
