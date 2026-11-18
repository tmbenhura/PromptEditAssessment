<script setup lang="ts">
import { AlertCircle, Copy } from '@lucide/vue';
import { ref } from 'vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import GenerationThumbnail from '@/components/generations/GenerationThumbnail.vue';
import { Card } from '@/components/ui/card';
import type { Generation } from '@/types';

const COPIED_FEEDBACK_MS = 2000;

const props = defineProps<{
    generation: Generation;
}>();

defineEmits<{
    preview: [imageUrl: string];
}>();

const promptCopied = ref(false);

async function copyPrompt() {
    await navigator.clipboard.writeText(props.generation.prompt);
    promptCopied.value = true;
    setTimeout(() => (promptCopied.value = false), COPIED_FEEDBACK_MS);
}

function formatTime(isoTimestamp: string): string {
    return new Date(isoTimestamp).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Card class="overflow-hidden py-0 transition-all hover:shadow-xs">
        <div class="flex flex-col sm:flex-row">
            <GenerationThumbnail
                :generation="generation"
                @preview="$emit('preview', $event)"
            />

            <div class="flex flex-1 flex-col justify-between space-y-3 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <GenerationStatusBadge :status="generation.status" />
                        <span class="text-xs text-neutral-400">
                            {{ generation.size }} • {{ generation.quality }}
                        </span>
                    </div>

                    <span class="font-mono text-[11px] text-neutral-400">
                        {{ formatTime(generation.created_at) }}
                    </span>
                </div>

                <div>
                    <p class="line-clamp-2 text-sm text-neutral-800 dark:text-neutral-200">
                        "{{ generation.prompt }}"
                    </p>
                    <button
                        type="button"
                        class="mt-1 flex items-center gap-1 text-[10px] text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300"
                        @click="copyPrompt"
                    >
                        <Copy class="h-3 w-3" />
                        <span>{{ promptCopied ? 'Copied!' : 'Copy Prompt' }}</span>
                    </button>
                </div>

                <div
                    v-if="generation.status === 'failed' && generation.error_message"
                    class="flex items-start gap-1.5 rounded-md bg-rose-50 p-2 text-xs text-rose-700 dark:bg-rose-950/30 dark:text-rose-300"
                >
                    <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>{{ generation.error_message }}</span>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t pt-2 text-xs">
                    <div class="flex items-center gap-3">
                        <div>
                            <span class="text-neutral-400">Estimate: </span>
                            <span class="font-mono text-neutral-500">
                                ${{ Number(generation.estimated_user_cost).toFixed(4) }}
                            </span>
                        </div>

                        <div>
                            <span class="text-neutral-400">Actual Cost: </span>
                            <span
                                class="font-mono font-semibold"
                                :class="
                                    generation.status === 'queued' || generation.status === 'processing'
                                        ? 'text-neutral-400'
                                        : 'text-neutral-900 dark:text-neutral-100'
                                "
                            >
                                ${{ generation.status === 'failed' ? '0.0' : Number(generation.actual_user_cost).toFixed(6) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="generation.usage" class="font-mono text-[11px] text-neutral-400">
                        {{ generation.usage.total_tokens?.toLocaleString() ?? 0 }} tokens
                    </div>
                </div>
            </div>
        </div>
    </Card>
</template>
