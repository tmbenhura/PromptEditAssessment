<script setup lang="ts">
import { AlertCircle, Loader2, ZoomIn } from '@lucide/vue';
import type { Generation } from '@/types';

defineProps<{
    generation: Generation;
}>();

defineEmits<{
    preview: [imageUrl: string];
}>();
</script>

<template>
    <div class="relative flex h-48 items-center justify-center overflow-hidden border-b bg-neutral-100 dark:bg-neutral-900 sm:w-48 sm:min-w-[192px] sm:border-b-0 sm:border-r">
        <template v-if="generation.status === 'succeeded' && generation.image_url">
            <img
                :src="generation.image_url"
                :alt="generation.prompt"
                class="h-full w-full cursor-pointer object-cover transition duration-500 ease-in hover:scale-105"
                @click="$emit('preview', generation.image_url)"
            />
            <button
                type="button"
                class="absolute bottom-2 right-2 cursor-pointer rounded-full bg-black/60 p-1.5 text-white backdrop-blur-xs transition hover:bg-black/80"
                title="View Full Resolution"
                @click="$emit('preview', generation.image_url)"
            >
                <ZoomIn class="h-3.5 w-3.5" />
            </button>
        </template>

        <div
            v-else-if="generation.status === 'queued' || generation.status === 'processing'"
            class="flex flex-col items-center gap-2 p-4 text-center"
        >
            <Loader2 class="h-7 w-7 animate-spin text-primary" />
            <span class="text-xs font-medium text-neutral-600 dark:text-neutral-300">
                {{ generation.status === 'queued' ? 'In Pixelforge Queue' : 'Rendering Image...' }}
            </span>
            <span class="text-[10px] text-neutral-400">Updates live</span>
        </div>

        <div
            v-else-if="generation.status === 'failed'"
            class="flex flex-col items-center gap-1.5 p-4 text-center text-rose-800"
        >
            <AlertCircle class="h-7 w-7 text-rose-800" />
            <span class="text-xs font-semibold">Generation Failed</span>
            <span class="text-[10px] text-neutral-400">No Charge</span>
        </div>
    </div>
</template>
