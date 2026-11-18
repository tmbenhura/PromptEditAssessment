<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Coins, Loader2 } from '@lucide/vue';
import { ref, useId } from 'vue';
import CreditTierOption from '@/components/credits/CreditTierOption.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatUsd } from '@/lib/money';
import type { CreditTier } from '@/types';

const DEFAULT_TIER_AMOUNT = 25;

defineProps<{
    currentBalance: string;
}>();

const isOpen = defineModel<boolean>('open', { required: true });

const creditTiers: CreditTier[] = [
    { amount: 5, label: '$5.00', description: '5 credits' },
    { amount: 10, label: '$10.00', description: '10 credits' },
    { amount: 25, label: '$25.00', description: '25 credits', popular: true },
    { amount: 50, label: '$50.00', description: '50 credits' },
    { amount: 100, label: '$100.00', description: '100 credits' },
];

const selectedAmount = ref(DEFAULT_TIER_AMOUNT);
const isPurchasing = ref(false);
const tierGroupName = `credit-tier-${useId()}`;

function purchaseCredits() {
    isPurchasing.value = true;

    router.post(
        '/credits/buy',
        { amount: selectedAmount.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                isOpen.value = false;
            },
            onFinish: () => {
                isPurchasing.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Coins aria-hidden="true" class="h-5 w-5 text-amber-500" />
                    Buy Test Credits
                </DialogTitle>
                <DialogDescription>
                    Add simulation credits to test Pixelforge image generations without real money.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-2">
                <div class="rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-900 border">
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-600 dark:text-neutral-400">Current Balance:</span>
                        <span class="font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                            {{ formatUsd(currentBalance, 4) }} USD
                        </span>
                    </div>
                </div>

                <fieldset class="grid grid-cols-1 gap-2">
                    <legend class="sr-only">Credit amount</legend>
                    <CreditTierOption
                        v-for="tier in creditTiers"
                        :key="tier.amount"
                        v-model="selectedAmount"
                        :tier="tier"
                        :name="tierGroupName"
                    />
                </fieldset>
            </div>

            <DialogFooter class="sm:justify-between">
                <Button
                    variant="outline"
                    type="button"
                    :disabled="isPurchasing"
                    @click="isOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    :disabled="isPurchasing"
                    class="gap-2"
                    @click="purchaseCredits"
                >
                    <Loader2 v-if="isPurchasing" aria-hidden="true" class="h-4 w-4 animate-spin motion-reduce:animate-none" />
                    <span>Confirm +${{ selectedAmount }} Credits</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
