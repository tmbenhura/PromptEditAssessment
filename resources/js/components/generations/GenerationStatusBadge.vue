<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { AlertCircle, Check, Clock, Loader2 } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

interface StatusAppearance {
    label: string;
    icon: Component;
    classes: string;
    iconClasses?: string;
}

const appearances: Record<GenerationStatus, StatusAppearance> = {
    succeeded: {
        label: 'Succeeded',
        icon: Check,
        classes:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    },
    processing: {
        label: 'Processing',
        icon: Loader2,
        classes:
            'animate-pulse bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
        iconClasses: 'animate-spin',
    },
    queued: {
        label: 'Queued',
        icon: Clock,
        classes:
            'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    },
    failed: {
        label: 'Failed',
        icon: AlertCircle,
        classes:
            'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
    },
};

const appearance = computed(() => appearances[props.status]);
</script>

<template>
    <Badge class="gap-1 border-transparent" :class="appearance.classes">
        <component
            :is="appearance.icon"
            class="h-3 w-3"
            :class="appearance.iconClasses"
        />
        {{ appearance.label }}
    </Badge>
</template>
