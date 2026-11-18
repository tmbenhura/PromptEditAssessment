<script setup lang="ts">
import { CheckCircle2, ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import ReconciliationBadge from '@/components/reports/ReconciliationBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatUsd } from '@/lib/money';
import type { DailyReportEntry } from '@/types';

defineProps<{
    days: DailyReportEntry[];
}>();

const expandedDate = ref<string | null>(null);

function toggleDay(date: string) {
    expandedDate.value = expandedDate.value === date ? null : date;
}
</script>

<template>
    <Card>
        <CardHeader>
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle class="text-base font-semibold">Daily Provider Reconciliation</CardTitle>
                    <CardDescription>
                        Compares local recorded costs against Pixelforge's official GET /v1/usage report per UTC day. Use a day's expand button to see each user.
                    </CardDescription>
                </div>
                <Badge variant="outline" class="gap-1 border-emerald-500/30 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <CheckCircle2 aria-hidden="true" class="h-3.5 w-3.5" />
                    Source of Truth: GET /v1/usage
                </Badge>
            </div>
        </CardHeader>
        <CardContent class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Daily provider reconciliation by UTC date</caption>
                <thead class="border-b bg-neutral-50 text-xs font-medium uppercase text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                    <tr>
                        <th scope="col" class="w-8 p-3"><span class="sr-only">Show users</span></th>
                        <th scope="col" class="p-3">UTC Date</th>
                        <th scope="col" class="p-3">Generations</th>
                        <th scope="col" class="p-3">Pixelforge Usage API</th>
                        <th scope="col" class="p-3">Local Provider Cost</th>
                        <th scope="col" class="p-3">Reconciliation</th>
                        <th scope="col" class="p-3">User Spend (Charged)</th>
                        <th scope="col" class="p-3">Net Profit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-if="days.length === 0">
                        <td colspan="8" class="p-4 text-center text-neutral-600 dark:text-neutral-400">
                            No generation records found in selected date range.
                        </td>
                    </tr>
                    <template v-for="day in days" :key="day.date">
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50">
                            <td class="p-1 text-neutral-600 dark:text-neutral-400">
                                <button
                                    v-if="day.users.length > 0"
                                    type="button"
                                    class="flex h-8 w-8 cursor-pointer items-center justify-center rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-expanded="expandedDate === day.date"
                                    :aria-label="`${expandedDate === day.date ? 'Hide' : 'Show'} users for ${day.date}`"
                                    @click="toggleDay(day.date)"
                                >
                                    <ChevronRight
                                        aria-hidden="true"
                                        class="h-4 w-4 transition-transform motion-reduce:transition-none"
                                        :class="{ 'rotate-90': expandedDate === day.date }"
                                    />
                                </button>
                            </td>
                            <th scope="row" class="p-3 font-medium">{{ day.date }}</th>
                            <td class="p-3">
                                <span class="font-semibold">{{ day.generations_count }}</span>
                                <span v-if="day.failed_count > 0" class="ml-1 text-xs text-rose-700 dark:text-rose-400">
                                    ({{ day.failed_count }} failed)
                                </span>
                            </td>
                            <td class="p-3 font-mono text-xs">{{ formatUsd(day.pixelforge_cost_usd) }}</td>
                            <td class="p-3 font-mono text-xs">{{ formatUsd(day.provider_cost_usd) }}</td>
                            <td class="p-3">
                                <ReconciliationBadge :reconciled="day.reconciled" :difference-usd="day.difference_usd" />
                            </td>
                            <td class="p-3 font-mono text-xs font-medium text-neutral-900 dark:text-neutral-100">
                                {{ formatUsd(day.user_spend_usd) }}
                            </td>
                            <td class="p-3 font-mono text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                +{{ formatUsd(day.profit_usd) }}
                            </td>
                        </tr>

                        <tr
                            v-for="user in expandedDate === day.date ? day.users : []"
                            :key="`${day.date}-${user.id}`"
                            class="bg-neutral-50/60 text-xs dark:bg-neutral-900/40"
                        >
                            <td class="p-2"></td>
                            <td class="p-2 pl-6" colspan="2">
                                <span class="font-medium text-neutral-800 dark:text-neutral-200">{{ user.name }}</span>
                                <span class="ml-1 text-neutral-600 dark:text-neutral-400">{{ user.email }}</span>
                                <span class="ml-2 text-neutral-600 dark:text-neutral-400">· {{ user.generations_count }} generations</span>
                            </td>
                            <td class="p-2"></td>
                            <td class="p-2 font-mono">{{ formatUsd(user.provider_cost_usd) }}</td>
                            <td class="p-2"></td>
                            <td class="p-2 font-mono">{{ formatUsd(user.user_spend_usd) }}</td>
                            <td class="p-2 font-mono text-emerald-700 dark:text-emerald-400">
                                +{{ formatUsd(user.profit_usd) }}
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </CardContent>
    </Card>
</template>
