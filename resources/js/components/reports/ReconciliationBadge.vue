<script setup lang="ts">
import { AlertCircle, CheckCircle2 } from '@lucide/vue';

defineProps<{
    reconciled: boolean;
    differenceUsd: string;
}>();
</script>

<template>
    <span
        v-if="reconciled"
        class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
    >
        <CheckCircle2 class="h-3 w-3" />
        Exact Match
    </span>
    <span
        v-else
        class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-950 dark:text-rose-300"
    >
        <AlertCircle class="h-3 w-3" />
        Diff: {{ differenceUsd }}
    </span>
</template>
