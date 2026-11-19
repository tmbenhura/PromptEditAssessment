<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import BuyCreditsModal from '@/components/BuyCreditsModal.vue';
import BalanceBar from '@/components/generations/BalanceBar.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import GenerationHistory from '@/components/generations/GenerationHistory.vue';
import ImagePreviewDialog from '@/components/generations/ImagePreviewDialog.vue';
import { useLivePolling } from '@/composables/useLivePolling';
import type {
    CostEstimate,
    PaginatedGenerations,
    QualityOption,
    SizeOption,
} from '@/types';

const props = defineProps<{
    generations: PaginatedGenerations;
    balance: string;
    availableBalance: string;
    heldBalance: string;
    initialEstimate: CostEstimate | null;
    hasActiveGenerations: boolean;
    sizes: SizeOption[];
    qualities: QualityOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: '/dashboard' }],
    },
});

const isBuyCreditsOpen = ref(false);
const previewImageUrl = ref<string | null>(null);

useLivePolling(
    () => props.hasActiveGenerations,
    [
        'generations',
        'hasActiveGenerations',
        'balance',
        'availableBalance',
        'heldBalance',
    ],
);
</script>

<template>
    <Head title="Pixelforge Studio" />

    <h1 class="sr-only">Pixelforge Studio</h1>

    <div class="flex flex-col gap-6 p-4 md:p-8">
        <BalanceBar
            :available-balance="availableBalance"
            :held-balance="heldBalance"
            :is-admin="$page.props.auth.user.is_admin"
            @buy-credits="isBuyCreditsOpen = true"
        />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="lg:col-span-5">
                <GenerationForm
                    :available-balance="availableBalance"
                    :initial-estimate="initialEstimate"
                    :size-options="sizes"
                    :quality-options="qualities"
                    @buy-credits="isBuyCreditsOpen = true"
                />
            </div>

            <div class="lg:col-span-7">
                <GenerationHistory
                    :generations="generations"
                    :is-polling="hasActiveGenerations"
                    @preview="previewImageUrl = $event"
                />
            </div>
        </div>
    </div>

    <BuyCreditsModal
        v-model:open="isBuyCreditsOpen"
        :current-balance="balance"
    />

    <ImagePreviewDialog
        :image-url="previewImageUrl"
        @close="previewImageUrl = null"
    />
</template>
