<script setup lang="ts">
// Real radio inputs styled as tiles, so keyboard and screen readers work.
defineProps<{
    legend: string;
    name: string;
    options: { value: string; label: string; hint?: string }[];
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm font-medium">{{ legend }}</legend>
        <div class="grid grid-cols-3 gap-2">
            <label
                v-for="option in options"
                :key="option.value"
                class="flex cursor-pointer flex-col rounded-md border px-3 py-2 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring/50"
            >
                <input
                    v-model="model"
                    type="radio"
                    class="sr-only"
                    :name="name"
                    :value="option.value"
                />
                <span class="font-medium">{{ option.label }}</span>
                <span
                    v-if="option.hint"
                    class="text-xs text-muted-foreground"
                    >{{ option.hint }}</span
                >
            </label>
        </div>
    </fieldset>
</template>
