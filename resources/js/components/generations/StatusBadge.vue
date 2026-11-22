<script setup lang="ts">
import { computed } from 'vue';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

// The label itself comes from the server; this only picks a colour.
const classes = computed(
    () =>
        ({
            waiting: 'bg-muted text-muted-foreground',
            busy: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
            running:
                'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
            succeeded:
                'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
            failed: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
        })[props.generation.tone],
);
</script>

<template>
    <span
        class="inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="classes"
        data-test="status"
    >
        <span
            v-if="!generation.finished"
            class="size-1.5 animate-pulse rounded-full bg-current"
            aria-hidden="true"
        />
        {{ generation.label }}
    </span>
</template>
