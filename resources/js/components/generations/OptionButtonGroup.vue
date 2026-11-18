<script setup lang="ts">
import { Label } from '@/components/ui/label';

defineProps<{
    label: string;
    options: { value: string; label: string; description?: string }[];
}>();

const selectedValue = defineModel<string>({ required: true });
</script>

<template>
    <div class="space-y-1.5">
        <Label class="text-xs font-medium">{{ label }}</Label>
        <div class="grid grid-cols-3 gap-2">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                class="flex cursor-pointer flex-col items-center justify-center rounded-lg border p-2 text-center transition duration-200 hover:border-primary/60 hover:shadow-[0_0_14px_color-mix(in_oklab,var(--primary)_40%,transparent)] focus-visible:shadow-[0_0_14px_color-mix(in_oklab,var(--primary)_40%,transparent)] focus-visible:outline-none"
                :class="
                    selectedValue === option.value
                        ? 'border-primary bg-primary/5 text-primary shadow-[0_0_10px_color-mix(in_oklab,var(--primary)_25%,transparent)] ring-1 ring-primary hover:shadow-[0_0_18px_color-mix(in_oklab,var(--primary)_55%,transparent)]'
                        : 'border-neutral-200 dark:border-neutral-800'
                "
                @click="selectedValue = option.value"
            >
                <span class="text-xs font-semibold">{{ option.label }}</span>
                <span v-if="option.description" class="text-[10px] text-neutral-500">
                    {{ option.description }}
                </span>
            </button>
        </div>
    </div>
</template>
