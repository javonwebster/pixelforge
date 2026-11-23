<script setup lang="ts">
import BuyCreditsDialog from '@/components/generations/BuyCreditsDialog.vue';
import Price from '@/components/generations/Price.vue';
import { Button } from '@/components/ui/button';
import type { Balance, Quote } from '@/types';

// The Generate button and, right under it, what you have and a way to
// add more: "Balance $9.19 · Top up". The price is in the panel above.
defineProps<{
    quote: Quote | null;
    balance: Balance;
    disabled: boolean;
    label: string;
    shortcut: string;
}>();

defineEmits<{ generate: [] }>();
</script>

<template>
    <div class="grid gap-2">
        <Button
            class="h-11 bg-sky-300 font-semibold tracking-wide text-sky-950 uppercase shadow-[0_0_24px_-6px] shadow-sky-300/70 hover:bg-sky-200 disabled:shadow-none"
            :disabled="disabled"
            data-test="generate"
            @click="$emit('generate')"
        >
            {{ label }}
            <!-- Keyboard hint: only where there's likely a keyboard. -->
            <kbd
                class="hidden rounded border border-sky-950/20 bg-sky-950/10 px-1.5 py-0.5 text-[0.65rem] font-medium tracking-normal normal-case lg:inline"
                >{{ shortcut }}</kbd
            >
        </Button>

        <p
            class="flex flex-wrap items-center justify-center gap-x-1.5 text-xs text-muted-foreground"
            data-test="price-line"
        >
            <span
                :class="
                    quote && !quote.enough
                        ? 'font-medium text-red-600 dark:text-red-400'
                        : ''
                "
                >Balance <Price :money="quote?.available ?? balance.available"
            /></span>
            <span aria-hidden="true">·</span>
            <BuyCreditsDialog :balance="balance">
                <template #trigger>
                    <button
                        type="button"
                        class="font-medium text-sky-700 underline underline-offset-2 hover:text-sky-600 dark:text-sky-300 dark:hover:text-sky-200"
                        data-test="top-up"
                    >
                        Top up
                    </button>
                </template>
            </BuyCreditsDialog>
        </p>
    </div>
</template>
