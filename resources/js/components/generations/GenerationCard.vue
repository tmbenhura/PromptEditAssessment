<script setup lang="ts">
import { AlertCircle, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
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
const isPending = computed(
    () =>
        props.generation.status === 'queued' ||
        props.generation.status === 'processing',
);

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
                        <span
                            class="text-xs text-neutral-600 dark:text-neutral-400"
                        >
                            {{ generation.size }} • {{ generation.quality }}
                        </span>
                    </div>

                    <span
                        class="font-mono text-[11px] text-neutral-600 dark:text-neutral-400"
                    >
                        <span class="sr-only">Created at </span
                        >{{ formatTime(generation.created_at) }}
                    </span>
                </div>

                <div>
                    <p
                        class="line-clamp-2 text-sm text-neutral-800 dark:text-neutral-200"
                    >
                        "{{ generation.prompt }}"
                    </p>
                    <button
                        type="button"
                        class="mt-1 flex items-center gap-1 rounded px-1 py-0.5 text-[11px] text-neutral-600 hover:text-neutral-900 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:text-neutral-400 dark:hover:text-neutral-200"
                        @click="copyPrompt"
                    >
                        <Copy aria-hidden="true" class="h-3 w-3" />
                        <span>Copy Prompt</span>
                    </button>
                    <span class="sr-only" role="status" aria-live="polite">{{
                        promptCopied ? 'Prompt copied to clipboard' : ''
                    }}</span>
                </div>

                <div
                    v-if="
                        generation.status === 'failed' &&
                        generation.error_message
                    "
                    class="flex items-start gap-1.5 rounded-md bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                >
                    <AlertCircle
                        aria-hidden="true"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0"
                    />
                    <span>{{ generation.error_message }}</span>
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-t pt-2 text-xs"
                >
                    <div class="flex items-center gap-3">
                        <div>
                            <span class="text-neutral-600 dark:text-neutral-400"
                                >Estimate:
                            </span>
                            <span
                                class="font-mono text-neutral-600 dark:text-neutral-400"
                            >
                                ${{
                                    Number(
                                        generation.estimated_user_cost,
                                    ).toFixed(4)
                                }}
                            </span>
                        </div>

                        <div>
                            <span class="text-neutral-600 dark:text-neutral-400"
                                >Actual Cost:
                            </span>
                            <span
                                v-if="isPending"
                                class="text-neutral-600 dark:text-neutral-400"
                                >Pending</span
                            >
                            <span
                                v-else
                                class="font-mono font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                ${{
                                    generation.status === 'failed'
                                        ? '0.000000'
                                        : Number(
                                              generation.actual_user_cost,
                                          ).toFixed(6)
                                }}
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="generation.usage"
                        class="font-mono text-[11px] text-neutral-600 dark:text-neutral-400"
                    >
                        {{
                            generation.usage.total_tokens?.toLocaleString() ?? 0
                        }}
                        tokens
                    </div>
                </div>
            </div>
        </div>
    </Card>
</template>
