<script setup lang="ts">
import { AlertCircle, ImageOff, Loader2, ZoomIn } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

defineEmits<{
    preview: [imageUrl: string];
}>();

// Waiting for the daily generation quota (resets at UTC midnight).
const waitingForQuota = computed(
    () =>
        props.generation.status === 'queued' &&
        props.generation.submit_after !== null &&
        new Date(props.generation.submit_after) > new Date(),
);
const quotaResetTime = computed(() =>
    props.generation.submit_after
        ? new Date(props.generation.submit_after).toLocaleString([], {
              hour: '2-digit',
              minute: '2-digit',
              day: 'numeric',
              month: 'short',
          })
        : '',
);

// Pixelforge's signed URLs stop working 24 hours after completion.
const imageUnavailable = ref(false);
const imageExpired = computed(
    () =>
        imageUnavailable.value ||
        (props.generation.image_url_expires_at !== null &&
            new Date(props.generation.image_url_expires_at) <= new Date()),
);
</script>

<template>
    <div class="relative flex h-48 items-center justify-center overflow-hidden border-b bg-neutral-100 dark:bg-neutral-900 sm:w-48 sm:min-w-[192px] sm:border-b-0 sm:border-r">
        <div
            v-if="generation.status === 'succeeded' && imageExpired"
            class="flex flex-col items-center gap-1.5 p-4 text-center"
        >
            <ImageOff aria-hidden="true" class="h-7 w-7 text-neutral-500" />
            <span class="text-xs font-semibold text-neutral-600 dark:text-neutral-300">Image expired</span>
            <span class="text-[11px] text-neutral-600 dark:text-neutral-400">Pixelforge links last 24 hours</span>
        </div>

        <template v-else-if="generation.status === 'succeeded' && generation.image_url">
            <button
                type="button"
                class="group relative block h-full w-full cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                aria-label="View full resolution image"
                @click="$emit('preview', generation.image_url)"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="h-full w-full object-cover transition duration-500 ease-in group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                    @error="imageUnavailable = true"
                />
                <span
                    aria-hidden="true"
                    class="absolute bottom-2 right-2 rounded-full bg-black/70 p-1.5 text-white backdrop-blur-xs transition group-hover:bg-black/90"
                >
                    <ZoomIn class="h-3.5 w-3.5" />
                </span>
            </button>
        </template>

        <div
            v-else-if="generation.status === 'queued' || generation.status === 'processing'"
            role="status"
            class="flex flex-col items-center gap-2 p-4 text-center"
        >
            <Loader2 aria-hidden="true" class="h-7 w-7 animate-spin text-primary motion-reduce:animate-none" />
            <template v-if="waitingForQuota">
                <span class="text-xs font-medium text-neutral-600 dark:text-neutral-300">
                    Daily limit reached
                </span>
                <span class="text-[11px] text-neutral-600 dark:text-neutral-400">
                    Starts automatically at {{ quotaResetTime }} (UTC day resets)
                </span>
            </template>
            <template v-else>
                <span class="text-xs font-medium text-neutral-600 dark:text-neutral-300">
                    {{ generation.status === 'queued' ? 'In Queue' : 'Rendering Image...' }}
                </span>
                <span class="text-[11px] text-neutral-600 dark:text-neutral-400">Waiting</span>
            </template>
        </div>

        <div
            v-else-if="generation.status === 'failed'"
            class="flex flex-col items-center gap-1.5 p-4 text-center text-rose-800 dark:text-rose-300"
        >
            <AlertCircle aria-hidden="true" class="h-7 w-7" />
            <span class="text-xs font-semibold">Generation Failed</span>
            <span class="text-[11px] text-neutral-600 dark:text-neutral-400">No Charge</span>
        </div>
    </div>
</template>
