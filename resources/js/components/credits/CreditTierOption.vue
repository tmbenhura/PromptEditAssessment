<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { CreditTier } from '@/types';

defineProps<{
    tier: CreditTier;
    name: string;
}>();

const selectedAmount = defineModel<number>({ required: true });
</script>

<template>
    <label
        class="flex cursor-pointer items-center justify-between rounded-lg border p-3 text-left transition-all hover:border-neutral-400 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-emerald-500 has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background dark:hover:border-neutral-600"
        :class="
            selectedAmount === tier.amount
                ? 'border-emerald-500 bg-emerald-500/5 ring-2 ring-emerald-500/30'
                : 'border-neutral-200 dark:border-neutral-800'
        "
    >
        <input
            v-model="selectedAmount"
            type="radio"
            class="sr-only"
            :name="name"
            :value="tier.amount"
        />
        <div class="flex items-center gap-3">
            <div
                aria-hidden="true"
                class="flex h-5 w-5 items-center justify-center rounded-full border text-xs"
                :class="
                    selectedAmount === tier.amount
                        ? 'border-emerald-600 bg-emerald-600 text-white'
                        : 'border-neutral-400 dark:border-neutral-500'
                "
            >
                <Check
                    v-if="selectedAmount === tier.amount"
                    class="h-3 w-3 stroke-[3]"
                />
            </div>
            <div>
                <div class="font-medium text-neutral-900 dark:text-neutral-100">
                    {{ tier.label }}
                </div>
                <div class="text-xs text-neutral-600 dark:text-neutral-400">
                    {{ tier.description }}
                </div>
            </div>
        </div>

        <span
            v-if="tier.popular"
            class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
        >
            Most Popular
        </span>
    </label>
</template>
