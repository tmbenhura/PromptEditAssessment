<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

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
                <span class="text-xs text-neutral-500">Generated with Pixelforge</span>
                <a
                    v-if="imageUrl"
                    :href="imageUrl"
                    target="_blank"
                    download
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                >
                    <Download class="h-3.5 w-3.5" />
                    Download Full Image
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
