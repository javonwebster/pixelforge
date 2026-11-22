<script setup lang="ts">
import type { Money } from '@/types';

// Users see whole cents; hovering shows the exact nanodollar figure.
defineProps<{ money: Money }>();
</script>

<template>
    <span class="tabular-nums" :title="money.exact">{{ money.display }}</span>
</template>
