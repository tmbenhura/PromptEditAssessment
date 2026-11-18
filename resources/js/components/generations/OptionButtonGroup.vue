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
                class="flex cursor-pointer flex-col items-center justify-center rounded-lg border p-2 text-center transition"
                :class="
                    selectedValue === option.value
                        ? 'border-primary bg-primary/5 text-primary ring-1 ring-primary'
                        : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-800'
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
