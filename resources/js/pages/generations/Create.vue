<script setup lang="ts">
import { Head, useForm, useHttp } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useEventListener, watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import QuoteController from '@/actions/App/Http/Controllers/QuoteController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import BalancePanel from '@/components/generations/BalancePanel.vue';
import GenerateAction from '@/components/generations/GenerateAction.vue';
import OptionGroup from '@/components/generations/OptionGroup.vue';
import Price from '@/components/generations/Price.vue';
import ReferenceImageInput from '@/components/generations/ReferenceImageInput.vue';
import StepHeading from '@/components/generations/StepHeading.vue';
import { Button } from '@/components/ui/button';
import { create } from '@/routes/generations';
import type { Balance, Quote } from '@/types';

type GenerationInput = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

const props = defineProps<{
    balance: Balance;
    sizes: string[];
    qualities: string[];
    maxPromptCharacters: number;
    maxReferenceImages: number;
    prefill: GenerationInput;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const sizeOptions = computed(() =>
    props.sizes.map((size) => ({
        value: size,
        label: sizeLabels[size] ?? size,
        hint: size.replace('x', ' × '),
    })),
);

const qualityOptions = computed(() =>
    props.qualities.map((quality) => ({
        value: quality,
        label: quality[0].toUpperCase() + quality.slice(1),
    })),
);

const form = useForm({
    prompt: props.prefill.prompt,
    size: props.prefill.size,
    quality: props.prefill.quality,
});

// Kept outside the form object: the form's typed error keys don't model
// array positions well, and these are sent with transform() on submit.
const referenceImages = ref<string[]>([...props.prefill.reference_images]);
const filledReferenceImages = computed(() =>
    referenceImages.value.filter((url) => url.trim() !== ''),
);

// Unicode characters, as Pixelforge and the server count them. Spreading
// the string splits by code point; .length would count UTF-16 units.
const characters = computed(() => [...form.prompt].length);
const tooLong = computed(() => characters.value > props.maxPromptCharacters);

// Errors keyed by array position ("reference_images.0") or by the
// server-only "balance" key aren't in the form's own field types.
function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

const canAddReferenceImage = computed(
    () => referenceImages.value.length < props.maxReferenceImages,
);

function addReferenceImage(): void {
    if (canAddReferenceImage.value) {
        referenceImages.value.push('');
    }
}

function removeReferenceImage(index: number): void {
    referenceImages.value.splice(index, 1);
}

// Live price: the server prices it with the same calculator that places
// the hold, so what's shown is what's held.
const pricing = useHttp<GenerationInput, Quote>({
    prompt: '',
    size: '',
    quality: '',
    reference_images: [],
});

function refreshQuote(): void {
    pricing.cancel();
    pricing.prompt = form.prompt;
    pricing.size = form.size;
    pricing.quality = form.quality;
    pricing.reference_images = filledReferenceImages.value;
    // A half-typed URL fails validation; keep showing the last good quote.
    pricing.post(QuoteController.url()).catch(() => {});
}

watchDebounced(
    () => [
        form.prompt,
        form.size,
        form.quality,
        referenceImages.value.join('\n'),
    ],
    refreshQuote,
    { debounce: 300 },
);
// After buying credits the balance changes; re-check whether it's enough.
watch(() => props.balance.available.nanos, refreshQuote);
onMounted(refreshQuote);

const quote = computed(() => pricing.response);
const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        !tooLong.value &&
        quote.value?.enough !== false &&
        !form.processing,
);

const submitLabel = computed(() =>
    form.processing ? 'Submitting…' : 'Generate',
);

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_images: filledReferenceImages.value,
    })).submit(GenerationController.store());
}

// ⌘↵ on a Mac, Ctrl+↵ elsewhere, from anywhere on the page (but not while
// the Buy credits dialog is open).
const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.userAgent);
const shortcut = isMac ? '⌘↵' : 'Ctrl ↵';

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (
        event.key !== 'Enter' ||
        !(event.metaKey || event.ctrlKey) ||
        document.querySelector('[role="dialog"]')
    ) {
        return;
    }

    event.preventDefault();
    submit();
});
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Generate an image"
            description="Five steps. Your image appears in History when it's ready."
        />

        <BalancePanel :balance="balance" />

        <!-- The composer: one card, lightly lit in a baby-blue accent. -->
        <section
            class="grid gap-6 rounded-2xl border border-sky-400/30 bg-gradient-to-b from-sky-300/[0.07] to-transparent p-4 shadow-[0_0_48px_-24px] shadow-sky-300/50 sm:p-6 lg:grid-cols-[1fr_20rem]"
            aria-label="Compose a generation"
        >
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-end justify-between gap-4">
                        <StepHeading
                            :step="1"
                            title="Describe your image"
                            hint="Say what you want to see: the subject, the setting, the style."
                            for="prompt"
                        />
                        <span
                            class="shrink-0 text-xs whitespace-nowrap tabular-nums"
                            :class="
                                tooLong
                                    ? 'text-red-600'
                                    : 'text-muted-foreground'
                            "
                            data-test="character-count"
                        >
                            {{ characters.toLocaleString() }} /
                            {{ maxPromptCharacters.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        name="prompt"
                        rows="5"
                        required
                        placeholder="A lighthouse on a cliff at dusk, watercolour"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <OptionGroup
                    v-model="form.size"
                    :step="2"
                    legend="Choose a size"
                    name="size"
                    :options="sizeOptions"
                />
                <InputError :message="form.errors.size" />

                <OptionGroup
                    v-model="form.quality"
                    :step="3"
                    legend="Choose a quality"
                    hint="Higher quality looks sharper, takes longer and costs more."
                    name="quality"
                    :options="qualityOptions"
                />
                <InputError :message="form.errors.quality" />

                <div class="grid gap-2">
                    <StepHeading
                        :step="4"
                        title="Add reference images (optional)"
                        :hint="`Paste links to up to ${maxReferenceImages} images for Pixelforge to take inspiration from.`"
                    />
                    <ReferenceImageInput
                        v-for="(_, index) in referenceImages"
                        :key="index"
                        v-model="referenceImages[index]"
                        :position="index + 1"
                        :error="errorFor(`reference_images.${index}`)"
                        @remove="removeReferenceImage(index)"
                    />
                    <Button
                        v-if="canAddReferenceImage"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        @click="addReferenceImage"
                    >
                        <Plus /> Add reference image
                    </Button>
                    <InputError :message="errorFor('reference_images')" />
                </div>
            </form>

            <aside
                class="flex h-fit flex-col gap-4 rounded-xl border border-sky-400/25 bg-background/70 p-4 lg:sticky lg:top-4"
                aria-label="Price"
                aria-live="polite"
            >
                <StepHeading
                    :step="5"
                    title="Check the price and generate"
                    hint="You pay the actual price when it finishes, never more than the maximum. Failed images are free."
                />

                <dl class="grid gap-3">
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-muted-foreground">
                            Estimated price
                        </dt>
                        <dd class="text-xl font-semibold" data-test="estimate">
                            <Price v-if="quote" :money="quote.estimate" />
                            <span v-else class="text-muted-foreground">…</span>
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-muted-foreground">
                            Maximum price
                        </dt>
                        <dd class="font-medium" data-test="maximum">
                            <Price v-if="quote" :money="quote.maximum" />
                            <span v-else class="text-muted-foreground">…</span>
                        </dd>
                    </div>
                    <div
                        class="flex items-baseline justify-between border-t pt-3"
                    >
                        <dt class="text-sm text-muted-foreground">
                            Available balance
                        </dt>
                        <dd class="font-medium">
                            <Price
                                :money="quote?.available ?? balance.available"
                            />
                        </dd>
                    </div>
                </dl>

                <p class="text-xs text-muted-foreground">
                    The maximum is set aside from your balance while it runs.
                </p>

                <!-- The server writes this, so it matches the submit's refusal. -->
                <p
                    v-if="quote?.shortfallMessage"
                    class="text-sm text-red-600 dark:text-red-500"
                    role="alert"
                    data-test="insufficient"
                >
                    {{ quote.shortfallMessage }}
                </p>
                <InputError :message="errorFor('balance')" />

                <!-- Phones get this in the bar pinned to the bottom instead. -->
                <GenerateAction
                    class="hidden lg:grid"
                    :quote="quote"
                    :balance="balance"
                    :disabled="!canSubmit"
                    :label="submitLabel"
                    :shortcut="shortcut"
                    @generate="submit"
                />
            </aside>
        </section>

        <!-- Phones: price, balance and Generate always in reach. -->
        <div
            class="sticky bottom-0 z-10 -mx-4 border-t border-sky-400/30 bg-background/95 px-4 py-3 backdrop-blur lg:hidden"
        >
            <GenerateAction
                :quote="quote"
                :balance="balance"
                :disabled="!canSubmit"
                :label="submitLabel"
                :shortcut="shortcut"
                @generate="submit"
            />
        </div>
    </div>
</template>
