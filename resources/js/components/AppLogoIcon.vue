<script setup lang="ts">
import { ImageIcon } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <ImageIcon :class="className" v-bind="$attrs" aria-hidden="true" />
</template>
