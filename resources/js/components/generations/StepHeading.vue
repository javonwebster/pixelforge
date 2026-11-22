<script setup lang="ts">
// "Step 1 · Describe your image", with an optional line of help below.
defineProps<{ step: number; title: string; hint?: string; for?: string }>();
</script>

<template>
    <div class="grid gap-0.5">
        <component
            :is="$props.for ? 'label' : 'span'"
            :for="$props.for"
            class="text-sm font-medium"
        >
            <span class="text-muted-foreground">Step {{ step }} ·</span>
            {{ title }}
        </component>
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
