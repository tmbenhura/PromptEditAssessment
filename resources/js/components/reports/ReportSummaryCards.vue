<script setup lang="ts">
import { ArrowUpRight, CheckCircle2, DollarSign, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import StatCard from '@/components/reports/StatCard.vue';
import { formatUsd } from '@/lib/money';
import type { ReportSummary } from '@/types';

const props = defineProps<{
    summary: ReportSummary;
}>();

const reconciliationStatus = computed(() => {
    if (!props.summary.pixelforge_available) {
        return { label: 'Usage unavailable', dotClass: 'bg-neutral-400' };
    }

    return props.summary.all_days_reconciled
        ? { label: 'Reconciled', dotClass: 'bg-emerald-500' }
        : { label: 'Discrepancies found', dotClass: 'bg-rose-500' };
});
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
            title="Total User Spend"
            :icon="DollarSign"
            icon-class="text-emerald-500"
            caption="Gross revenue charged to users"
        >
            <div class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                {{ formatUsd(summary.total_user_spend_usd, 4) }}
            </div>
        </StatCard>

        <StatCard
            title="Pixelforge Costs"
            :icon="ArrowUpRight"
            icon-class="text-amber-500"
            caption="Total cost billed by provider"
        >
            <div class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                {{ formatUsd(summary.total_provider_cost_usd, 4) }}
            </div>
        </StatCard>

        <StatCard
            title="Net Profit (25% Markup)"
            :icon="TrendingUp"
            icon-class="text-primary"
            caption="20% gross margin on revenue"
        >
            <div class="text-2xl font-bold text-primary">
                {{ formatUsd(summary.total_profit_usd, 4) }}
            </div>
        </StatCard>

        <StatCard
            title="Pixelforge Status"
            :icon="CheckCircle2"
            icon-class="text-emerald-500"
            :caption="`${summary.total_generations} generations recorded`"
        >
            <div class="flex items-center gap-2">
                <span class="inline-flex h-2.5 w-2.5 rounded-full" :class="reconciliationStatus.dotClass"></span>
                <span class="font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ reconciliationStatus.label }}
                </span>
            </div>
        </StatCard>
    </div>
</template>
