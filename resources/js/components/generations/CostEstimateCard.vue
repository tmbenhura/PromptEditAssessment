<script setup lang="ts">
import { AlertCircle, Coins } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { CostEstimate } from '@/types';

defineProps<{
    estimate: CostEstimate | null;
    isLoading: boolean;
    errorMessage: string | null;
    canAfford: boolean;
}>();

defineEmits<{
    buyCredits: [];
}>();

function usd(amount: string, decimals: number): string {
    return `$${Number(amount).toFixed(decimals)}`;
}
</script>

<template>
    <div
        class="space-y-2.5 rounded-xl border border-neutral-200 bg-neutral-50/50 p-3.5 text-xs dark:border-neutral-800 dark:bg-neutral-900/50"
        role="region"
        aria-label="Cost estimate"
        :aria-busy="isLoading"
    >
        <div class="flex items-center justify-between">
            <span class="flex items-center gap-1.5 font-semibold text-neutral-700 dark:text-neutral-300">
                <Coins aria-hidden="true" class="h-4 w-4 text-amber-500" />
                Estimated Cost
            </span>
            <Badge
                v-if="estimate"
                variant="outline"
                class="font-mono text-[11px] transition-opacity"
                :class="{ 'opacity-50': isLoading }"
            >
                ≈ {{ usd(estimate.costs.typical, 5) }}
            </Badge>
        </div>

        <p v-if="!estimate && !errorMessage" role="status" class="text-neutral-600 dark:text-neutral-400">Calculating…</p>

        <template v-if="estimate">
            <div
                class="space-y-1 font-mono text-[11px] text-neutral-600 transition-opacity dark:text-neutral-400"
                :class="{ 'opacity-50': isLoading }"
            >
                <div class="flex justify-between">
                    <span>Input text tokens (~{{ estimate.tokens.text }}):</span>
                    <span>{{ usd(estimate.costs.text, 6) }}</span>
                </div>
                <div v-if="estimate.tokens.image > 0" class="flex justify-between">
                    <span>Ref image tokens ({{ estimate.tokens.image }}):</span>
                    <span>{{ usd(estimate.costs.image, 6) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Output tokens ({{ estimate.tokens.output }}):</span>
                    <span>{{ usd(estimate.costs.output, 6) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Reasoning tokens ({{ estimate.tokens.reasoning_min }}-{{ estimate.tokens.reasoning_max }}):</span>
                    <span>{{ usd(estimate.costs.reasoning_min, 6) }} - {{ usd(estimate.costs.reasoning_max, 6) }}</span>
                </div>
            </div>

            <div
                class="flex items-center justify-between border-t pt-2 font-semibold transition-opacity"
                :class="{ 'opacity-50': isLoading }"
            >
                <span class="text-neutral-700 dark:text-neutral-200">Price Range:</span>
                <span class="font-mono text-neutral-900 dark:text-neutral-100">
                    {{ usd(estimate.costs.min, 4) }} – {{ usd(estimate.costs.max, 4) }}
                </span>
            </div>
        </template>

        <!-- Polite announcement of the headline figure whenever it settles. -->
        <p v-if="estimate" class="sr-only" role="status" aria-live="polite">
            Estimated cost about {{ usd(estimate.costs.typical, 4) }}, between
            {{ usd(estimate.costs.min, 4) }} and {{ usd(estimate.costs.max, 4) }}.
        </p>

        <p v-if="errorMessage" role="alert" class="flex items-center gap-1.5 text-rose-700 dark:text-rose-400">
            <AlertCircle aria-hidden="true" class="h-4 w-4 shrink-0" />
            {{ errorMessage }}
        </p>

        <div
            v-if="estimate && !canAfford"
            role="alert"
            class="flex items-center justify-between rounded bg-rose-50 p-2 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300"
        >
            <div class="flex items-center gap-1.5 text-xs">
                <AlertCircle aria-hidden="true" class="h-4 w-4 shrink-0" />
                <span>Insufficient credits for max estimate.</span>
            </div>
            <button
                type="button"
                class="ml-2 rounded px-1 text-xs font-semibold underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="$emit('buyCredits')"
            >
                Buy
            </button>
        </div>
    </div>
</template>
