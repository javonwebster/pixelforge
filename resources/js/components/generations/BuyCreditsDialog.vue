<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CreditController from '@/actions/App/Http/Controllers/CreditController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatCents, nanosToCents, parseDollars } from '@/lib/money';
import type { Balance } from '@/types';

const props = defineProps<{ balance: Balance }>();

// Quick picks; any amount from $1 to $500 can be typed.
const presets = ['5', '10', '20', '50'];
const MIN_CENTS = 100n;
const MAX_CENTS = 50_000n;

const open = ref(false);
const form = useForm({ amount: '10' });

const cents = computed(() => parseDollars(form.amount));
// The same checks and wording as CreditController::store, shown as you
// type, so an amount the server would refuse never gets as far as Buy.
const amountError = computed((): string | null => {
    if (form.amount.trim() === '') {
        return null;
    }

    if (cents.value === null) {
        return 'Enter an amount in dollars, like 25 or 12.50.';
    }

    if (cents.value < MIN_CENTS || cents.value > MAX_CENTS) {
        return 'Choose between $1 and $500.';
    }

    return null;
});
const valid = computed(
    () => cents.value !== null && amountError.value === null,
);
// Only a valid amount is previewed: "$600.00" would read as allowed.
const credit = computed(() => (valid.value ? cents.value : null));
const after = computed(() =>
    credit.value === null
        ? null
        : nanosToCents(props.balance.available.nanos) + credit.value,
);
const buyLabel = computed(() => {
    if (form.processing) {
        return 'Adding…';
    }

    return credit.value !== null ? `Buy ${formatCents(credit.value)}` : 'Buy';
});

function buy(): void {
    // Disabled while in flight, but a fast double click can land before
    // Vue re-renders, so check here too.
    if (form.processing || !valid.value) {
        return;
    }

    form.submit(CreditController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <!-- Callers can swap the trigger, e.g. a "Top up" link. -->
            <slot name="trigger">
                <Button size="sm" data-test="open-buy-credits">
                    <Plus /> Buy credits
                </Button>
            </slot>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Pay per use · no subscription
                </p>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Credits are dollars, spent per generation. Pick an amount or
                    enter your own. This is a demo: no payment is taken.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="buy">
                <div class="grid gap-2">
                    <span
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                        >Amount</span
                    >
                    <div class="grid grid-cols-4 gap-2">
                        <Button
                            v-for="preset in presets"
                            :key="preset"
                            type="button"
                            variant="outline"
                            :class="
                                form.amount === preset
                                    ? 'border-primary bg-accent'
                                    : ''
                            "
                            :aria-pressed="form.amount === preset"
                            :data-test="`preset-${preset}`"
                            @click="form.amount = preset"
                        >
                            ${{ preset }}
                        </Button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <label
                        for="credit-amount"
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Or enter your own amount (USD)
                    </label>
                    <Input
                        id="credit-amount"
                        v-model="form.amount"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="25.00"
                        :aria-invalid="amountError !== null"
                        :aria-describedby="
                            amountError || form.errors.amount
                                ? 'credit-amount-error'
                                : 'credit-amount-help'
                        "
                        data-test="custom-amount"
                    />
                    <InputError
                        id="credit-amount-error"
                        :message="amountError ?? form.errors.amount"
                        data-test="amount-error"
                    />
                    <p
                        v-if="!amountError && !form.errors.amount"
                        id="credit-amount-help"
                        class="text-xs text-muted-foreground"
                    >
                        Between $1 and $500, like 25 or 12.50.
                    </p>
                </div>

                <dl class="grid gap-2 border-y py-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Credit</dt>
                        <dd class="font-medium tabular-nums">
                            {{ credit !== null ? formatCents(credit) : '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Balance after</dt>
                        <dd
                            class="font-medium tabular-nums"
                            data-test="balance-after"
                        >
                            {{ after !== null ? formatCents(after) : '—' }}
                        </dd>
                    </div>
                </dl>

                <Button
                    type="submit"
                    :disabled="!valid || form.processing"
                    data-test="buy"
                >
                    {{ buyLabel }}
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
