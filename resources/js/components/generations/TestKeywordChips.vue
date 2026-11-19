<script setup lang="ts">
interface TestKeyword {
    keyword: string;
    classes: string;
}

defineEmits<{
    select: [keyword: string];
}>();

// Keywords the Pixelforge sandbox reacts to, for exercising each outcome.
const testKeywords: TestKeyword[] = [
    {
        keyword: '[succeed]',
        classes:
            'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700',
    },
    {
        keyword: '[fail]',
        classes:
            'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-900/60',
    },
    {
        keyword: '[slow]',
        classes:
            'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-900/60',
    },
    {
        keyword: '[duplicate]',
        classes:
            'bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-950/40 dark:text-purple-300 dark:hover:bg-purple-900/60',
    },
    {
        keyword: '[blocked]',
        classes:
            'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-900/60',
    },
];
</script>

<template>
    <div class="space-y-1" role="group" aria-labelledby="test-flags-label">
        <div
            id="test-flags-label"
            class="text-[11px] font-medium tracking-wider text-neutral-600 uppercase dark:text-neutral-400"
        >
            Quick Test Flags
        </div>
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="testKeyword in testKeywords"
                :key="testKeyword.keyword"
                type="button"
                class="cursor-pointer rounded px-2 py-1 text-xs transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="testKeyword.classes"
                :aria-label="`Append ${testKeyword.keyword} to prompt`"
                @click="$emit('select', testKeyword.keyword)"
            >
                {{ testKeyword.keyword }}
            </button>
        </div>
    </div>
</template>
