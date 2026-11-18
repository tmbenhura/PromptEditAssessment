<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { CreditTier } from '@/types';

defineProps<{
    tier: CreditTier;
    selected: boolean;
}>();
</script>

<template>
    <button
        type="button"
        class="flex items-center justify-between rounded-lg border p-3 text-left transition-all hover:border-neutral-400 dark:hover:border-neutral-600"
        :class="
            selected
                ? 'border-primary bg-primary/5 ring-2 ring-primary/20'
                : 'border-neutral-200 dark:border-neutral-800'
        "
    >
        <div class="flex items-center gap-3">
            <div
                class="flex h-5 w-5 items-center justify-center rounded-full border text-xs"
                :class="
                    selected
                        ? 'border-primary bg-primary text-white'
                        : 'border-neutral-300 dark:border-neutral-600'
                "
            >
                <Check v-if="selected" class="h-3 w-3 stroke-[3]" />
            </div>
            <div>
                <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ tier.label }}</div>
                <div class="text-xs text-neutral-500">{{ tier.description }}</div>
            </div>
        </div>

        <span
            v-if="tier.popular"
            class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
        >
            Most Popular
        </span>
    </button>
</template>
