<script setup lang="ts">
import { AlertCircle, Coins } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { CostEstimate } from '@/types';

defineProps<{
    estimate: CostEstimate;
    canAfford: boolean;
}>();

defineEmits<{
    buyCredits: [];
}>();
</script>

<template>
    <div class="space-y-2.5 rounded-xl border border-neutral-200 bg-neutral-50/50 p-3.5 text-xs dark:border-neutral-800 dark:bg-neutral-900/50">
        <div class="flex items-center justify-between">
            <span class="flex items-center gap-1.5 font-semibold text-neutral-700 dark:text-neutral-300">
                <Coins class="h-4 w-4 text-amber-500" />
                Estimated Cost
            </span>
            <Badge variant="outline" class="font-mono text-[11px]">
                ≈ ${{ estimate.typicalCost.toFixed(5) }}
            </Badge>
        </div>

        <div class="space-y-1 font-mono text-[11px] text-neutral-500">
            <div class="flex justify-between">
                <span>Input text tokens (~{{ estimate.promptTokens }}):</span>
                <span>${{ estimate.promptCost.toFixed(6) }}</span>
            </div>
            <div v-if="estimate.referenceImageTokens > 0" class="flex justify-between">
                <span>Ref image tokens ({{ estimate.referenceImageTokens }}):</span>
                <span>${{ estimate.referenceImageCost.toFixed(6) }}</span>
            </div>
            <div class="flex justify-between">
                <span>Output tokens ({{ estimate.outputTokens }}):</span>
                <span>${{ estimate.outputCost.toFixed(6) }}</span>
            </div>
            <div class="flex justify-between">
                <span>Reasoning tokens ({{ estimate.reasoningTokensMin }}-{{ estimate.reasoningTokensMax }}):</span>
                <span>${{ estimate.reasoningCostMin.toFixed(6) }} - ${{ estimate.reasoningCostMax.toFixed(6) }}</span>
            </div>
        </div>

        <div class="flex items-center justify-between border-t pt-2 font-semibold">
            <span class="text-neutral-700 dark:text-neutral-200">Price Range:</span>
            <span class="font-mono text-neutral-900 dark:text-neutral-100">
                ${{ estimate.minCost.toFixed(4) }} – ${{ estimate.maxCost.toFixed(4) }}
            </span>
        </div>

        <div
            v-if="!canAfford"
            class="flex items-center justify-between rounded bg-rose-50 p-2 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300"
        >
            <div class="flex items-center gap-1.5 text-xs">
                <AlertCircle class="h-4 w-4 shrink-0" />
                <span>Insufficient credits for max estimate.</span>
            </div>
            <button
                type="button"
                class="ml-2 text-xs font-semibold underline"
                @click="$emit('buyCredits')"
            >
                Buy
            </button>
        </div>
    </div>
</template>
