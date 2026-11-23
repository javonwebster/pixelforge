<script setup lang="ts">
// A numbered step: a lime badge with the number, a small "Step N" kicker,
// the step's title, and an optional line of help below.
defineProps<{ step: number; title: string; hint?: string; for?: string }>();
</script>

<template>
    <div class="flex items-start gap-3">
        <span
            class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-lime-400 text-xs font-bold text-lime-950 shadow-[0_0_12px_-2px] shadow-lime-400/60"
            aria-hidden="true"
            >{{ step }}</span
        >
        <div class="grid gap-0.5">
            <component
                :is="$props.for ? 'label' : 'span'"
                :for="$props.for"
                class="grid"
            >
                <span
                    class="text-[0.7rem] font-semibold tracking-wider text-lime-700 uppercase dark:text-lime-400"
                    >Step {{ step }}</span
                >
                <span class="text-base font-semibold">{{ title }}</span>
            </component>
            <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        </div>
    </div>
</template>
