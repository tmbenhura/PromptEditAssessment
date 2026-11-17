<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Coins, Loader2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    currentBalance: string;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const selectedAmount = ref('25');
const isSubmitting = ref(false);

const creditTiers = [
    { amount: 5, label: '$5.00', bonus: '5 credits' },
    { amount: 10, label: '$10.00', bonus: '10 credits' },
    { amount: 25, label: '$25.00', bonus: '25 credits', popular: true },
    { amount: 50, label: '$50.00', bonus: '50 credits' },
    { amount: 100, label: '$100.00', bonus: '100 credits' },
];

function handlePurchase() {
    isSubmitting.value = true;
    router.post(
        '/credits/buy',
        { amount: selectedAmount.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('update:open', false);
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Coins class="h-5 w-5 text-amber-500" />
                    Buy Test Credits
                </DialogTitle>
                <DialogDescription>
                    Add simulation credits to test Pixelforge image generations without real money.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-2">
                <div class="rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-900 border">
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-500">Current Balance:</span>
                        <span class="font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                            ${{ Number(currentBalance).toFixed(4) }} USD
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2">
                    <button
                        v-for="tier in creditTiers"
                        :key="tier.amount"
                        type="button"
                        class="flex items-center justify-between rounded-lg border p-3 text-left transition-all hover:border-neutral-400 dark:hover:border-neutral-600"
                        :class="[
                            selectedAmount === tier.amount
                                ? 'border-primary ring-2 ring-primary/20 bg-primary/5'
                                : 'border-neutral-200 dark:border-neutral-800',
                        ]"
                        @click="selectedAmount = tier.amount"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-5 w-5 items-center justify-center rounded-full border text-xs"
                                :class="[
                                    selectedAmount === tier.amount
                                        ? 'border-primary bg-primary text-white'
                                        : 'border-neutral-300 dark:border-neutral-600',
                                ]"
                            >
                                <Check v-if="selectedAmount === tier.amount" class="h-3 w-3 stroke-[3]" />
                            </div>
                            <div>
                                <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ tier.label }}
                                </div>
                                <div class="text-xs text-neutral-500">
                                    {{ tier.bonus }}
                                </div>
                            </div>
                        </div>

                        <span
                            v-if="tier.popular"
                            class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                        >
                            Most Popular
                        </span>
                    </button>
                </div>
            </div>

            <DialogFooter class="sm:justify-between">
                <Button
                    variant="outline"
                    type="button"
                    :disabled="isSubmitting"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    :disabled="isSubmitting"
                    class="gap-2"
                    @click="handlePurchase"
                >
                    <Loader2 v-if="isSubmitting" class="h-4 w-4 animate-spin" />
                    <span>Confirm +${{ selectedAmount }} Credits</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
