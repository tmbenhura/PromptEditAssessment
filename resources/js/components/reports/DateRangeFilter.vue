<script setup lang="ts">
import { Calendar, RefreshCw } from '@lucide/vue';
import { useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
    isLoading: boolean;
    error?: string;
}>();

defineEmits<{
    apply: [];
}>();

const idPrefix = useId();
const errorId = `${idPrefix}-error`;
const startDate = defineModel<string>('startDate', { required: true });
const endDate = defineModel<string>('endDate', { required: true });
</script>

<template>
    <form
        class="flex flex-wrap items-center gap-2"
        role="search"
        aria-label="Report date range"
        @submit.prevent="$emit('apply')"
    >
        <div class="flex items-center gap-1.5">
            <Calendar aria-hidden="true" class="h-4 w-4 text-neutral-500" />
            <label :for="`${idPrefix}-start`" class="sr-only">Start date</label>
            <Input
                :id="`${idPrefix}-start`"
                v-model="startDate"
                type="date"
                class="h-9 w-36 text-xs"
                required
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error ? errorId : undefined"
            />
        </div>
        <span class="text-neutral-600 dark:text-neutral-400" aria-hidden="true">to</span>
        <label :for="`${idPrefix}-end`" class="sr-only">End date</label>
        <Input
            :id="`${idPrefix}-end`"
            v-model="endDate"
            type="date"
            class="h-9 w-36 text-xs"
            required
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? errorId : undefined"
        />
        <Button type="submit" size="sm" variant="outline" :disabled="isLoading" class="gap-1.5">
            <RefreshCw v-if="isLoading" aria-hidden="true" class="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" />
            <span>Filter</span>
        </Button>
        <p v-if="error" :id="errorId" role="alert" class="w-full text-xs font-medium text-rose-600 dark:text-rose-400">{{ error }}</p>
    </form>
</template>
