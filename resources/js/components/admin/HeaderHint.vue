<script setup lang="ts">
import { Info } from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

// A label with an explanation on hover, keyboard focus, or tap (the ⓘ is a
// real button, so touch screens and keyboards can reach it too).
withDefaults(
    defineProps<{ label: string; hint: string; align?: 'start' | 'end' }>(),
    {
        align: 'start',
    },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1"
        :class="align === 'end' ? 'justify-end' : ''"
    >
        {{ label }}
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="rounded-full text-muted-foreground/70 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="`What is ${label}?`"
                >
                    <Info class="size-3.5" />
                </button>
            </TooltipTrigger>
            <TooltipContent
                class="max-w-64 text-left leading-snug font-normal normal-case"
            >
                {{ hint }}
            </TooltipContent>
        </Tooltip>
    </span>
</template>
