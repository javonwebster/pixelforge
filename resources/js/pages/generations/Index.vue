<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Download, ImageOff, RotateCcw } from '@lucide/vue';
import { computed, onMounted, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import BalancePanel from '@/components/generations/BalancePanel.vue';
import Price from '@/components/generations/Price.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Balance, Generation, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    balance: Balance;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

// Live status: while anything on the page is unfinished, reload just the
// list and the balance every 3 seconds. Stop once everything is done, and
// start again if something new appears (e.g. an automatic retry).
const anyUnfinished = computed(() =>
    props.generations.data.some((generation) => !generation.finished),
);
const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'balance'] },
    { autoStart: false },
);

watch(anyUnfinished, (unfinished) => (unfinished ? start() : stop()));
onMounted(() => {
    if (anyUnfinished.value) {
        start();
    }
});

function localTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="History"
            description="Your generations, newest first."
        />

        <BalancePanel :balance="balance" />

        <div
            v-if="generations.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center"
        >
            <p class="mb-4 text-muted-foreground">No generations yet.</p>
            <Button as-child
                ><Link :href="create()">Generate your first image</Link></Button
            >
        </div>

        <ul v-else class="grid gap-4">
            <li
                v-for="generation in generations.data"
                :id="`generation-${generation.id}`"
                :key="generation.id"
                class="grid gap-4 rounded-xl border p-4 sm:grid-cols-[10rem_1fr]"
                data-test="generation"
            >
                <!-- Image, or what stands in for it -->
                <div
                    class="flex aspect-square w-full max-w-56 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground sm:max-w-none"
                >
                    <!-- Opens the full-size image in a new tab. -->
                    <a
                        v-if="generation.image?.url"
                        :href="generation.image.fullUrl ?? generation.image.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="size-full"
                        :title="`Open full size: ${generation.prompt}`"
                        data-test="open-image"
                    >
                        <img
                            :src="generation.image.url"
                            :alt="generation.prompt"
                            class="size-full object-cover transition-opacity hover:opacity-90"
                            loading="lazy"
                        />
                    </a>
                    <div
                        v-else-if="generation.image?.expired"
                        class="flex flex-col items-center gap-1 p-2 text-center text-xs"
                    >
                        <ImageOff class="size-5" />
                        Image expired after 24 hours
                    </div>
                    <div
                        v-else-if="!generation.finished"
                        class="size-full animate-pulse bg-muted-foreground/10"
                    />
                    <ImageOff v-else class="size-5" />
                </div>

                <div class="flex min-w-0 flex-col gap-3">
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="flex flex-col gap-1">
                            <StatusBadge :generation="generation" />
                            <p
                                v-if="generation.detail"
                                class="text-sm text-muted-foreground"
                                data-test="detail"
                            >
                                {{ generation.detail }}
                            </p>
                        </div>
                        <time
                            class="text-xs text-muted-foreground"
                            :datetime="generation.createdAt"
                        >
                            {{ localTime(generation.createdAt) }}
                        </time>
                    </div>

                    <p class="line-clamp-3 text-sm break-words">
                        {{ generation.prompt }}
                    </p>

                    <p class="text-xs text-muted-foreground">
                        {{ generation.size.replace('x', ' × ') }} ·
                        {{ generation.quality }}
                    </p>

                    <ul
                        v-if="generation.referenceImages.length"
                        class="flex flex-wrap gap-x-3 text-xs"
                    >
                        <li
                            v-for="(url, i) in generation.referenceImages"
                            :key="url"
                        >
                            <!-- Links, never <img>: these are arbitrary user URLs. -->
                            <a
                                :href="url"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="underline underline-offset-2"
                            >
                                Reference {{ i + 1 }}
                            </a>
                        </li>
                    </ul>

                    <dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        <div class="flex gap-1.5">
                            <dt class="text-muted-foreground">Estimated</dt>
                            <dd>
                                <Price :money="generation.estimatedPrice" />
                            </dd>
                        </div>
                        <div class="flex gap-1.5">
                            <dt class="text-muted-foreground">
                                {{ generation.failed ? 'Charged' : 'Actual' }}
                            </dt>
                            <dd data-test="actual">
                                <Price
                                    v-if="generation.actualPrice"
                                    :money="generation.actualPrice"
                                />
                                <span v-else class="text-muted-foreground"
                                    >when it finishes</span
                                >
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="
                            generation.retryOf ||
                            generation.retriedAs ||
                            generation.failed ||
                            generation.image?.downloadUrl
                        "
                        class="flex flex-wrap items-center gap-3 text-sm"
                    >
                        <a
                            v-if="generation.retryOf"
                            :href="`#generation-${generation.retryOf.id}`"
                            class="text-muted-foreground underline underline-offset-2"
                        >
                            Retry of the attempt from
                            {{ localTime(generation.retryOf.createdAt) }}
                        </a>
                        <a
                            v-if="generation.retriedAs"
                            :href="`#generation-${generation.retriedAs}`"
                            class="text-muted-foreground underline underline-offset-2"
                        >
                            See the automatic retry
                        </a>
                        <!-- A plain link, not Inertia's: the response is a file. -->
                        <Button
                            v-if="generation.image?.downloadUrl"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a
                                :href="generation.image.downloadUrl"
                                data-test="download"
                            >
                                <Download /> Download
                            </a>
                        </Button>
                        <Button
                            v-if="generation.failed"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="
                                    create({ query: { from: generation.id } })
                                "
                                data-test="try-again"
                            >
                                <RotateCcw /> Try again
                            </Link>
                        </Button>
                    </div>
                </div>
            </li>
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between"
            aria-label="Pages"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.prev"
                as-child
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    >Newer</Link
                >
                <span v-else>Newer</span>
            </Button>
            <span class="text-sm text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.next"
                as-child
            >
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    >Older</Link
                >
                <span v-else>Older</span>
            </Button>
        </nav>
    </div>
</template>
