<script setup lang="ts">
import type { Component } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    title: string;
    icon: Component;
    iconClass?: string;
    caption: string;
}>();
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-center justify-between pb-2">
            <CardTitle class="text-xs font-medium uppercase text-neutral-500">{{ title }}</CardTitle>
            <component :is="icon" class="h-4 w-4" :class="iconClass" />
        </CardHeader>
        <CardContent>
            <slot />
            <p class="mt-1 text-xs text-neutral-500">{{ caption }}</p>
        </CardContent>
    </Card>
</template>
