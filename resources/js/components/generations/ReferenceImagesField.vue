<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const MAX_REFERENCE_IMAGES = 4;

const imageUrls = defineModel<string[]>({ required: true });

const urlInputId = `reference-url-${useId()}`;
const urlErrorId = `${urlInputId}-error`;
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
            <Label :for="urlInputId" class="font-medium">Reference Images (Optional)</Label>
            <span class="text-[11px] text-neutral-600 dark:text-neutral-400">
                {{ imageUrls.length }}/{{ MAX_REFERENCE_IMAGES }} max
            </span>
        </div>

        <ul v-if="imageUrls.length > 0" class="space-y-1.5" aria-label="Reference images">
            <li
                v-for="(imageUrl, index) in imageUrls"
                :key="imageUrl"
                class="flex items-center justify-between rounded border bg-neutral-50 px-2 py-1 text-xs dark:bg-neutral-900"
            >
                <span class="max-w-[240px] truncate font-mono text-[11px] text-neutral-600 dark:text-neutral-300">
                    {{ imageUrl }}
                </span>
                <button
                    type="button"
                    class="rounded p-1 text-neutral-500 hover:text-rose-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:text-neutral-400"
                    :aria-label="`Remove reference image ${index + 1}`"
                    @click="removeImageUrl(index)"
                >
                    <Trash2 aria-hidden="true" class="h-3.5 w-3.5" />
                </button>
            </li>
        </ul>

        <div v-if="imageUrls.length < MAX_REFERENCE_IMAGES" class="space-y-1">
            <div class="flex gap-2">
                <Input
                    :id="urlInputId"
                    v-model="pendingUrl"
                    :aria-invalid="urlError ? 'true' : undefined"
                    :aria-describedby="urlError ? urlErrorId : undefined"
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
                    <Plus aria-hidden="true" class="mr-1 h-3.5 w-3.5" />
                    Add
                </Button>
            </div>
            <p v-if="urlError" :id="urlErrorId" role="alert" class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ urlError }}</p>
        </div>
    </div>
</template>
