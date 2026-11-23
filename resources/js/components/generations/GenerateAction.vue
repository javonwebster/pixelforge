<script setup lang="ts">
import BuyCreditsDialog from '@/components/generations/BuyCreditsDialog.vue';
import Price from '@/components/generations/Price.vue';
import { Button } from '@/components/ui/button';
import type { Balance, Quote } from '@/types';

// The Generate button and, right under it, what it will cost and what you
// have: "≈ $0.07 · up to $0.09 · Balance $9.19 · Top up".
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
            class="h-11 bg-lime-400 font-semibold tracking-wide text-lime-950 uppercase shadow-[0_0_24px_-6px] shadow-lime-400/70 hover:bg-lime-300 disabled:shadow-none"
            :disabled="disabled"
            data-test="generate"
            @click="$emit('generate')"
        >
            {{ label }}
            <!-- Keyboard hint: only where there's likely a keyboard. -->
            <kbd
                class="hidden rounded border border-lime-950/20 bg-lime-950/10 px-1.5 py-0.5 text-[0.65rem] font-medium tracking-normal normal-case lg:inline"
                >{{ shortcut }}</kbd
            >
        </Button>

        <p
            class="flex flex-wrap items-center justify-center gap-x-1.5 text-xs text-muted-foreground"
            data-test="price-line"
        >
            <template v-if="quote">
                <span>≈ <Price :money="quote.estimate" /></span>
                <span aria-hidden="true">·</span>
                <span>up to <Price :money="quote.maximum" /></span>
                <span aria-hidden="true">·</span>
            </template>
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
                        class="font-medium text-lime-700 underline underline-offset-2 hover:text-lime-600 dark:text-lime-400 dark:hover:text-lime-300"
                        data-test="top-up"
                    >
                        Top up
                    </button>
                </template>
            </BuyCreditsDialog>
        </p>
    </div>
</template>
