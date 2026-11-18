<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const MAX_REFERENCE_IMAGES = 4;

const imageUrls = defineModel<string[]>({ required: true });

const pendingUrl = ref('');
const urlError = ref<string | null>(null);

function addImageUrl() {
    const url = pendingUrl.value.trim();

    if (!url || imageUrls.value.length >= MAX_REFERENCE_IMAGES) {
        return;
    }

    try {
        new URL(url);
    } catch {
        urlError.value = 'Please enter a valid URL.';

        return;
    }

    imageUrls.value = [...imageUrls.value, url];
    pendingUrl.value = '';
    urlError.value = null;
}

function removeImageUrl(index: number) {
    imageUrls.value = imageUrls.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between text-xs">
            <Label class="font-medium">Reference Images (Optional)</Label>
            <span class="text-[11px] text-neutral-400">
                {{ imageUrls.length }}/{{ MAX_REFERENCE_IMAGES }} max
            </span>
        </div>

        <div v-if="imageUrls.length > 0" class="space-y-1.5">
            <div
                v-for="(imageUrl, index) in imageUrls"
                :key="imageUrl"
                class="flex items-center justify-between rounded border bg-neutral-50 px-2 py-1 text-xs dark:bg-neutral-900"
            >
                <span class="max-w-[240px] truncate font-mono text-[11px] text-neutral-600 dark:text-neutral-300">
                    {{ imageUrl }}
                </span>
                <button
                    type="button"
                    class="text-neutral-400 hover:text-rose-500"
                    :aria-label="`Remove reference image ${index + 1}`"
                    @click="removeImageUrl(index)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <div v-if="imageUrls.length < MAX_REFERENCE_IMAGES" class="space-y-1">
            <div class="flex gap-2">
                <Input
                    v-model="pendingUrl"
                    placeholder="https://example.com/style.png"
                    class="h-8 text-xs"
                    type="url"
                    @keydown.enter.prevent="addImageUrl"
                />
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-8 cursor-pointer px-2 text-xs"
                    @click="addImageUrl"
                >
                    <Plus class="mr-1 h-3.5 w-3.5" />
                    Add
                </Button>
            </div>
            <p v-if="urlError" class="text-xs font-medium text-rose-500">{{ urlError }}</p>
        </div>
    </div>
</template>
