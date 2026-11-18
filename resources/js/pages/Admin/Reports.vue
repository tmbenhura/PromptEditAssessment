<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowUpRight,
    BarChart3,
    Calendar,
    CheckCircle2,
    DollarSign,
    RefreshCw,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface DailyReportEntry {
    date: string;
    generations_count: number;
    succeeded_count: number;
    failed_count: number;
    provider_cost_usd: string;
    user_spend_usd: string;
    profit_usd: string;
    pixelforge_cost_usd: string;
    reconciled: boolean;
    difference_usd: string;
}

interface UserStat {
    id: number;
    name: string;
    email: string;
    balance_usd: string;
    generations_count: number;
    provider_cost_usd: string;
    user_spend_usd: string;
    profit_usd: string;
}

interface Summary {
    total_generations: number;
    total_provider_cost_usd: string;
    total_user_spend_usd: string;
    total_profit_usd: string;
    pixelforge_available: boolean;
}

const props = defineProps<{
    dailyReport: DailyReportEntry[];
    userStats: UserStat[];
    summary: Summary;
    filters: {
        start_date: string;
        end_date: string;
    };
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

function applyFilter() {
    isFiltering.value = true;
    router.get(
        '/reports',
        {
            start_date: startDate.value,
            end_date: endDate.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                isFiltering.value = false;
            },
        },
    );
}

function formatMoney(amount: string | number, decimals: number = 9): string {
    const num = Number(amount);
    return isNaN(num) ? '$0.00' : `$${num.toFixed(decimals)}`;
}
</script>

<template>
    <Head title="Financial Reports & Reconciliation" />

    <h1 class="sr-only">Financial Reports & Reconciliation</h1>

    <div class="flex flex-col gap-6 p-4 md:p-8">
        <!-- Header & Filter -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                    <BarChart3 class="h-6 w-6 text-primary" />
                    Operator Financial Reports
                </h1>
                <p class="text-sm text-neutral-500">
                    Spend, provider costs and exact reconciliation against Pixelforge GET /v1/usage.
                </p>
            </div>

            <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilter">
                <div class="flex items-center gap-1.5">
                    <Calendar class="h-4 w-4 text-neutral-400" />
                    <Input
                        v-model="startDate"
                        type="date"
                        class="h-9 w-36 text-xs"
                        required
                    />
                </div>
                <span class="text-neutral-400">to</span>
                <Input
                    v-model="endDate"
                    type="date"
                    class="h-9 w-36 text-xs"
                    required
                />
                <Button type="submit" size="sm" variant="outline" :disabled="isFiltering" class="gap-1.5">
                    <RefreshCw v-if="isFiltering" class="h-3.5 w-3.5 animate-spin" />
                    <span>Filter</span>
                </Button>
            </form>
        </div>

        <!-- Summary KPI Cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-medium uppercase text-neutral-500">Total User Spend</CardTitle>
                    <DollarSign class="h-4 w-4 text-emerald-500" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                        {{ formatMoney(summary.total_user_spend_usd, 4) }}
                    </div>
                    <p class="text-xs text-neutral-500 mt-1">
                        Gross revenue charged to users
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-medium uppercase text-neutral-500">Pixelforge Costs</CardTitle>
                    <ArrowUpRight class="h-4 w-4 text-amber-500" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                        {{ formatMoney(summary.total_provider_cost_usd, 4) }}
                    </div>
                    <p class="text-xs text-neutral-500 mt-1">
                        Total cost billed by provider
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-medium uppercase text-neutral-500">Net Profit (25% Markup)</CardTitle>
                    <TrendingUp class="h-4 w-4 text-primary" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-primary">
                        {{ formatMoney(summary.total_profit_usd, 4) }}
                    </div>
                    <p class="text-xs text-neutral-500 mt-1">
                        20% gross margin on revenue
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-medium uppercase text-neutral-500">Pixelforge Status</CardTitle>
                    <CheckCircle2 class="h-4 w-4 text-emerald-500" />
                </CardHeader>
                <CardContent>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">Reconciled</span>
                    </div>
                    <p class="text-xs text-neutral-500 mt-1">
                        {{ summary.total_generations }} generations recorded
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Daily Reconciliation Table -->
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle class="text-base font-semibold">Daily Provider Reconciliation</CardTitle>
                        <CardDescription>
                            Compares local recorded costs against Pixelforge's official GET /v1/usage report per UTC day.
                        </CardDescription>
                    </div>
                    <Badge variant="outline" class="gap-1 border-emerald-500/30 text-emerald-600 bg-emerald-50 dark:bg-emerald-950/30">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        Source of Truth: GET /v1/usage
                    </Badge>
                </div>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-neutral-50 text-xs font-medium uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr>
                            <th class="p-3">UTC Date</th>
                            <th class="p-3">Generations</th>
                            <th class="p-3">Pixelforge Usage API</th>
                            <th class="p-3">Local Provider Cost</th>
                            <th class="p-3">Reconciliation</th>
                            <th class="p-3">User Spend (Charged)</th>
                            <th class="p-3">Net Profit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-if="dailyReport.length === 0">
                            <td colspan="7" class="p-4 text-center text-neutral-500">
                                No generation records found in selected date range.
                            </td>
                        </tr>
                        <tr
                            v-for="row in dailyReport"
                            :key="row.date"
                            class="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50"
                        >
                            <td class="p-3 font-medium">{{ row.date }}</td>
                            <td class="p-3">
                                <span class="font-semibold">{{ row.generations_count }}</span>
                                <span v-if="row.failed_count > 0" class="text-xs text-rose-500 ml-1">
                                    ({{ row.failed_count }} failed)
                                </span>
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ formatMoney(row.pixelforge_cost_usd, 9) }}
                            </td>
                            <td class="p-3 font-mono text-xs">
                                {{ formatMoney(row.provider_cost_usd, 9) }}
                            </td>
                            <td class="p-3">
                                <span
                                    v-if="row.reconciled"
                                    class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                                >
                                    <CheckCircle2 class="h-3 w-3" />
                                    Exact Match
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-950 dark:text-rose-300"
                                >
                                    <AlertCircle class="h-3 w-3" />
                                    Diff: {{ row.difference_usd }}
                                </span>
                            </td>
                            <td class="p-3 font-mono text-xs font-medium text-neutral-900 dark:text-neutral-100">
                                {{ formatMoney(row.user_spend_usd, 9) }}
                            </td>
                            <td class="p-3 font-mono text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                +{{ formatMoney(row.profit_usd, 9) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <!-- User Spend Breakdown Table -->
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle class="text-base font-semibold">User Spend & Credit Balances</CardTitle>
                        <CardDescription>
                            Cumulative spend, provider usage, and profitability grouped by user.
                        </CardDescription>
                    </div>
                    <Users class="h-4 w-4 text-neutral-400" />
                </div>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-neutral-50 text-xs font-medium uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr>
                            <th class="p-3">User</th>
                            <th class="p-3">Current Balance</th>
                            <th class="p-3">Total Generations</th>
                            <th class="p-3">Provider Cost</th>
                            <th class="p-3">Total Spend Charged</th>
                            <th class="p-3">Operator Profit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr
                            v-for="u in userStats"
                            :key="u.id"
                            class="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50"
                        >
                            <td class="p-3">
                                <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ u.name }}</div>
                                <div class="text-xs text-neutral-500">{{ u.email }}</div>
                            </td>
                            <td class="p-3 font-mono text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                ${{ Number(u.balance_usd).toFixed(4) }}
                            </td>
                            <td class="p-3 font-medium">
                                {{ u.generations_count }}
                            </td>
                            <td class="p-3 font-mono text-xs text-neutral-500">
                                {{ formatMoney(u.provider_cost_usd, 9) }}
                            </td>
                            <td class="p-3 font-mono text-xs font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ formatMoney(u.user_spend_usd, 9) }}
                            </td>
                            <td class="p-3 font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                +{{ formatMoney(u.profit_usd, 9) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
