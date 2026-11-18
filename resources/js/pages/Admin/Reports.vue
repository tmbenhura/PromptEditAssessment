<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BarChart3 } from '@lucide/vue';
import { ref } from 'vue';
import DailyReconciliationTable from '@/components/reports/DailyReconciliationTable.vue';
import DateRangeFilter from '@/components/reports/DateRangeFilter.vue';
import ReportSummaryCards from '@/components/reports/ReportSummaryCards.vue';
import UserSpendTable from '@/components/reports/UserSpendTable.vue';
import type {
    DailyReportEntry,
    ReportDateRange,
    ReportSummary,
    UserSpendEntry,
} from '@/types';

const props = defineProps<{
    dailyReport: DailyReportEntry[];
    userStats: UserSpendEntry[];
    summary: ReportSummary;
    filters: ReportDateRange;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Generations', href: '/dashboard' },
            { title: 'Financial Reports', href: '/reports' },
        ],
    },
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const isFiltering = ref(false);

function applyDateFilter() {
    isFiltering.value = true;

    router.get(
        '/reports',
        { start_date: startDate.value, end_date: endDate.value },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                isFiltering.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Financial Reports & Reconciliation" />

    <h1 class="sr-only">Financial Reports & Reconciliation</h1>

    <div class="flex flex-col gap-6 p-4 md:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">
                    <BarChart3 class="h-6 w-6 text-primary" />
                    Operator Financial Reports
                </h1>
                <p class="text-sm text-neutral-500">
                    Spend, provider costs and exact reconciliation against Pixelforge GET /v1/usage.
                </p>
            </div>

            <DateRangeFilter
                v-model:start-date="startDate"
                v-model:end-date="endDate"
                :is-loading="isFiltering"
                @apply="applyDateFilter"
            />
        </div>

        <ReportSummaryCards :summary="summary" :daily-report="dailyReport" />

        <DailyReconciliationTable :days="dailyReport" />

        <UserSpendTable :users="userStats" />
    </div>
</template>
