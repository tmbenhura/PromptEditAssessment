<script setup lang="ts">
import { Users } from '@lucide/vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
                    <CardTitle class="text-base font-semibold"
                        >User Spend & Credit Balances</CardTitle
                    >
                    <CardDescription>
                        Spend, provider usage, and profitability per user over
                        the selected dates.
                    </CardDescription>
                </div>
                <Users aria-hidden="true" class="h-4 w-4 text-neutral-500" />
            </div>
        </CardHeader>
        <CardContent class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    User spend, provider cost and profit over the selected dates
                </caption>
                <thead
                    class="border-b bg-neutral-50 text-xs font-medium text-neutral-600 uppercase dark:bg-neutral-900 dark:text-neutral-400"
                >
                    <tr>
                        <th scope="col" class="p-3">User</th>
                        <th scope="col" class="p-3">Current Balance</th>
                        <th scope="col" class="p-3">Total Generations</th>
                        <th scope="col" class="p-3">Provider Cost</th>
                        <th scope="col" class="p-3">Total Spend Charged</th>
                        <th scope="col" class="p-3">Operator Profit</th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-neutral-100 dark:divide-neutral-800"
                >
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50"
                    >
                        <th scope="row" class="p-3 font-normal">
                            <div
                                class="font-medium text-neutral-900 dark:text-neutral-100"
                            >
                                {{ user.name }}
                            </div>
                            <div
                                class="text-xs text-neutral-600 dark:text-neutral-400"
                            >
                                {{ user.email }}
                            </div>
                        </th>
                        <td
                            class="p-3 font-mono text-xs font-semibold text-neutral-700 dark:text-neutral-300"
                        >
                            {{ formatUsd(user.balance_usd, 4) }}
                        </td>
                        <td class="p-3 font-medium">
                            {{ user.generations_count }}
                        </td>
                        <td
                            class="p-3 font-mono text-xs text-neutral-600 dark:text-neutral-400"
                        >
                            {{ formatUsd(user.provider_cost_usd) }}
                        </td>
                        <td
                            class="p-3 font-mono text-xs font-semibold text-neutral-900 dark:text-neutral-100"
                        >
                            {{ formatUsd(user.user_spend_usd) }}
                        </td>
                        <td
                            class="p-3 font-mono text-xs font-bold text-emerald-700 dark:text-emerald-400"
                        >
                            +{{ formatUsd(user.profit_usd) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </CardContent>
    </Card>
</template>
