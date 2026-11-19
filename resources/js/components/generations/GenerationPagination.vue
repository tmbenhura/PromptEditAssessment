<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { PaginatedGenerations } from '@/types';

defineProps<{
    generations: PaginatedGenerations;
}>();
</script>

<template>
    <nav
        v-if="generations.last_page > 1"
        aria-label="Generation history pages"
        class="flex items-center justify-between pt-2"
    >
        <Button
            variant="outline"
            size="sm"
            :disabled="!generations.prev_page_url"
            as-child
        >
            <a
                v-if="generations.prev_page_url"
                :href="generations.prev_page_url"
                rel="prev"
                >Previous</a
            >
            <span v-else aria-disabled="true">Previous</span>
        </Button>
        <span
            class="text-xs text-neutral-600 dark:text-neutral-400"
            aria-current="page"
        >
            Page {{ generations.current_page }} of {{ generations.last_page }}
        </span>
        <Button
            variant="outline"
            size="sm"
            :disabled="!generations.next_page_url"
            as-child
        >
            <a
                v-if="generations.next_page_url"
                :href="generations.next_page_url"
                rel="next"
                >Next</a
            >
            <span v-else aria-disabled="true">Next</span>
        </Button>
    </nav>
</template>
