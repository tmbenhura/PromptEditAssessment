<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Image as ImageIcon, RefreshCw } from '@lucide/vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationPagination from '@/components/generations/GenerationPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { PaginatedGenerations } from '@/types';

defineProps<{
    generations: PaginatedGenerations;
    isPolling: boolean;
}>();

defineEmits<{
    preview: [imageUrl: string];
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Generation History</h2>
                <span class="rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                    {{ generations.total }} total
                </span>
            </div>

            <div class="flex items-center gap-2">
                <Badge
                    v-if="isPolling"
                    variant="outline"
                    class="animate-pulse gap-1.5 border-blue-500/30 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
                >
                    <RefreshCw class="h-3 w-3 animate-spin" />
                    Live Polling Active
                </Badge>

                <Button
                    size="sm"
                    variant="ghost"
                    class="h-8 cursor-pointer px-2 text-xs"
                    @click="router.reload({ preserveScroll: true })"
                >
                    <RefreshCw class="mr-1 h-3.5 w-3.5" />
                    Refresh
                </Button>
            </div>
        </div>

        <Card v-if="generations.data.length === 0" class="p-8 text-center">
            <ImageIcon class="mx-auto h-12 w-12 text-neutral-300 dark:text-neutral-600" />
            <h3 class="mt-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                No images generated yet
            </h3>
            <p class="mt-1 text-xs text-neutral-500">
                Write a prompt in the studio on the left to start generating.
            </p>
        </Card>

        <div class="space-y-3">
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
                @preview="$emit('preview', $event)"
            />
        </div>

        <GenerationPagination :generations="generations" />
    </div>
</template>
