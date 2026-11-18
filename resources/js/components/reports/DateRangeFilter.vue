<script setup lang="ts">
import { Calendar, RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
    isLoading: boolean;
    error?: string;
}>();

defineEmits<{
    apply: [];
}>();

const startDate = defineModel<string>('startDate', { required: true });
const endDate = defineModel<string>('endDate', { required: true });
</script>

<template>
    <form class="flex flex-wrap items-center gap-2" @submit.prevent="$emit('apply')">
        <div class="flex items-center gap-1.5">
            <Calendar class="h-4 w-4 text-neutral-400" />
            <Input v-model="startDate" type="date" class="h-9 w-36 text-xs" required />
        </div>
        <span class="text-neutral-400">to</span>
        <Input v-model="endDate" type="date" class="h-9 w-36 text-xs" required />
        <Button type="submit" size="sm" variant="outline" :disabled="isLoading" class="gap-1.5">
            <RefreshCw v-if="isLoading" class="h-3.5 w-3.5 animate-spin" />
            <span>Filter</span>
        </Button>
        <p v-if="error" class="w-full text-xs font-medium text-rose-500">{{ error }}</p>
    </form>
</template>
