<script setup lang="ts">
import BuyCreditsDialog from '@/components/generations/BuyCreditsDialog.vue';
import { Button } from '@/components/ui/button';
import type { Balance } from '@/types';

// The Generate button with a "Top up" link under it. The price and the
// balance are in the panel above.
defineProps<{
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

        <div class="flex justify-center text-xs">
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
        </div>
    </div>
</template>
