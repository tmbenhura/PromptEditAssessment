<script setup lang="ts">
import { BarChart3, Coins } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    availableBalance: string;
    heldBalance: string;
    isAdmin: boolean;
}>();

defineEmits<{
    buyCredits: [];
}>();
</script>

<template>
    <div class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                <Coins aria-hidden="true" class="h-6 w-6" />
            </div>
            <div>
                <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Available Credits</div>
                <div class="font-mono text-xl font-bold text-neutral-900 dark:text-neutral-100">
                    ${{ Number(availableBalance).toFixed(4) }} USD
                </div>
                <div
                    v-if="Number(heldBalance) > 0"
                    class="text-xs text-neutral-600 dark:text-neutral-400"
                    data-test="held-balance"
                >
                    ${{ Number(heldBalance).toFixed(4) }} held for generations in progress
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button variant="default" class="gap-2 shadow-xs" @click="$emit('buyCredits')">
                <Coins aria-hidden="true" class="h-4 w-4" />
                <span>Buy Credits</span>
            </Button>

            <Button v-if="isAdmin" variant="outline" as-child class="gap-2">
                <a href="/reports">
                    <BarChart3 aria-hidden="true" class="h-4 w-4 text-neutral-500" />
                    <span>Operator Reports</span>
                </a>
            </Button>
        </div>
    </div>
</template>
