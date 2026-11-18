<script setup lang="ts">
import { Users } from '@lucide/vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatUsd } from '@/lib/money';
import type { UserSpendEntry } from '@/types';

defineProps<{
    users: UserSpendEntry[];
}>();
</script>

<template>
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
                        v-for="user in users"
                        :key="user.id"
                        class="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50"
                    >
                        <td class="p-3">
                            <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ user.name }}</div>
                            <div class="text-xs text-neutral-500">{{ user.email }}</div>
                        </td>
                        <td class="p-3 font-mono text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {{ formatUsd(user.balance_usd, 4) }}
                        </td>
                        <td class="p-3 font-medium">{{ user.generations_count }}</td>
                        <td class="p-3 font-mono text-xs text-neutral-500">{{ formatUsd(user.provider_cost_usd) }}</td>
                        <td class="p-3 font-mono text-xs font-semibold text-neutral-900 dark:text-neutral-100">
                            {{ formatUsd(user.user_spend_usd) }}
                        </td>
                        <td class="p-3 font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400">
                            +{{ formatUsd(user.profit_usd) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </CardContent>
    </Card>
</template>
