<script setup lang="ts">
import { useId } from 'vue';

defineProps<{
    label: string;
    options: { value: string; label: string; description?: string }[];
}>();

const selectedValue = defineModel<string>({ required: true });

// Native radios give arrow-key navigation, a single tab stop and screen
// reader "n of m" announcements without any custom key handling.
const groupName = `option-group-${useId()}`;
</script>

<template>
    <fieldset class="space-y-1.5">
        <legend class="mb-1.5 text-xs font-medium">{{ label }}</legend>
        <div class="grid grid-cols-3 gap-2">
            <label
                v-for="option in options"
                :key="option.value"
                class="flex cursor-pointer flex-col items-center justify-center rounded-lg border p-2 text-center transition duration-200 hover:border-lime-500/60 hover:shadow-[0_0_10px_color-mix(in_oklab,var(--color-lime-500)_10%,transparent)] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-lime-500 has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background"
                :class="
                    selectedValue === option.value
                        ? 'border-white bg-lime-500/5 text-lime-800 shadow-[0_0_10px_color-mix(in_oklab,var(--color-lime-500)_10%,transparent)] ring-2 ring-white hover:shadow-[0_0_15px_color-mix(in_oklab,var(--color-lime-500)_25%,transparent)] dark:text-lime-300'
                        : 'border-neutral-200 dark:border-neutral-800'
                "
            >
                <input
                    v-model="selectedValue"
                    type="radio"
                    class="sr-only"
                    :name="groupName"
                    :value="option.value"
                />
                <span class="text-xs font-semibold">{{ option.label }}</span>
                <span
                    v-if="option.description"
                    class="text-[11px] text-neutral-600 dark:text-neutral-400"
                >
                    {{ option.description }}
                </span>
            </label>
        </div>
    </fieldset>
</template>
