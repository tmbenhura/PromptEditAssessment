<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

defineProps<{
    imageUrl: string | null;
}>();

defineEmits<{
    close: [];
}>();
</script>

<template>
    <Dialog :open="imageUrl !== null" @update:open="$emit('close')">
        <DialogContent class="max-w-4xl p-2 sm:p-4">
            <DialogHeader class="sr-only">
                <DialogTitle>Image Preview</DialogTitle>
                <DialogDescription>Full resolution view of the generated image.</DialogDescription>
            </DialogHeader>
            <div class="relative flex max-h-[80vh] items-center justify-center overflow-hidden rounded-lg bg-black">
                <img
                    v-if="imageUrl"
                    :src="imageUrl"
                    alt="High resolution generated image"
                    class="max-h-[80vh] w-auto object-contain"
                />
            </div>
            <div class="mt-2 flex items-center justify-between px-2">
                <span class="text-xs text-neutral-600 dark:text-neutral-400">Generated with Pixelforge</span>
                <a
                    v-if="imageUrl"
                    :href="imageUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    download
                    class="inline-flex items-center gap-1.5 rounded text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Download aria-hidden="true" class="h-3.5 w-3.5" />
                    Download Full Image<span class="sr-only"> (opens in a new tab)</span>
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
