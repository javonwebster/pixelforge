<script setup lang="ts">
import { X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{ position: number; error?: string }>();
defineEmits<{ remove: [] }>();

const url = defineModel<string>({ required: true });
</script>

<template>
    <div class="grid gap-1">
        <div class="flex gap-2">
            <Input
                v-model="url"
                type="url"
                inputmode="url"
                placeholder="https://example.com/photo.jpg"
                :aria-label="`Reference image ${position}`"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :aria-label="`Remove reference image ${position}`"
                @click="$emit('remove')"
            >
                <X />
            </Button>
        </div>
        <InputError :message="error" />
    </div>
</template>
