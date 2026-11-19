<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import CostEstimateCard from '@/components/generations/CostEstimateCard.vue';
import OptionButtonGroup from '@/components/generations/OptionButtonGroup.vue';
import ReferenceImagesField from '@/components/generations/ReferenceImagesField.vue';
import TestKeywordChips from '@/components/generations/TestKeywordChips.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useCostEstimate } from '@/composables/useCostEstimate';
import type { CostEstimate, QualityOption, SizeOption } from '@/types';

const MAX_PROMPT_LENGTH = 4000;

const props = defineProps<{
    availableBalance: string;
    initialEstimate: CostEstimate | null;
    sizeOptions: SizeOption[];
    qualityOptions: QualityOption[];
}>();

defineEmits<{
    buyCredits: [];
}>();

interface GenerationForm {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    balance?: number;
    general?: string;
}

const form = useForm<GenerationForm>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// The server works out the estimate (it owns the pricing), so the browser only
// ever sees what the user will be charged.
const { estimate, isEstimating, errorMessage } = useCostEstimate(
    {
        prompt: () => form.prompt,
        size: () => form.size,
        quality: () => form.quality,
        referenceImages: () => form.reference_images,
    },
    props.initialEstimate,
);

const canAfford = computed(
    () =>
        estimate.value !== null &&
        Number(props.availableBalance) >= Number(estimate.value.costs.max),
);

const sizeButtons = computed(() =>
    props.sizeOptions.map((sizeOption) => ({
        value: sizeOption.value,
        label: sizeOption.aspectRatio,
        description: sizeOption.value,
    })),
);

function appendTestKeyword(keyword: string) {
    if (!form.prompt.includes(keyword)) {
        form.prompt = form.prompt ? `${form.prompt} ${keyword}` : keyword;
    }
}

function submit() {
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            form.reference_images = [];
        },
    });
}
</script>

<template>
    <Card class="sticky top-6">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-lg">
                <Sparkles aria-hidden="true" class="h-5 w-5 text-primary" />
                Generate Image
            </CardTitle>
            <CardDescription>Submit prompt to Pixelforge.</CardDescription>
        </CardHeader>

        <form @submit.prevent="submit">
            <CardContent class="space-y-4">
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between text-xs">
                        <Label for="prompt" class="font-medium">Prompt</Label>
                        <span
                            class="font-mono text-neutral-600 dark:text-neutral-400"
                            aria-hidden="true"
                        >
                            {{ form.prompt.length }} /
                            {{ MAX_PROMPT_LENGTH.toLocaleString() }} chars
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="3"
                        required
                        :maxlength="MAX_PROMPT_LENGTH"
                        :aria-invalid="form.errors.prompt ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.prompt ? 'prompt-error' : undefined
                        "
                        placeholder="A lighthouse on a cliff at dawn, dramatic waves, oil painting..."
                        class="w-full rounded-md border border-input px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:bg-neutral-900/50"
                    ></textarea>
                    <div
                        v-if="form.errors.prompt"
                        id="prompt-error"
                        role="alert"
                        class="text-xs font-medium text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.prompt }}
                    </div>
                </div>

                <TestKeywordChips @select="appendTestKeyword" />

                <OptionButtonGroup
                    v-model="form.size"
                    label="Image Aspect & Dimensions"
                    :options="sizeButtons"
                />

                <OptionButtonGroup
                    v-model="form.quality"
                    label="Quality Tier"
                    :options="qualityOptions"
                />

                <ReferenceImagesField v-model="form.reference_images" />

                <CostEstimateCard
                    :estimate="estimate"
                    :is-loading="isEstimating"
                    :error-message="errorMessage"
                    :can-afford="canAfford"
                    @buy-credits="$emit('buyCredits')"
                />

                <div
                    v-if="form.errors.balance"
                    role="alert"
                    class="text-xs font-medium text-rose-600 dark:text-rose-400"
                >
                    {{ form.errors.balance }}
                </div>
                <div
                    v-if="form.errors.general"
                    role="alert"
                    class="text-xs font-medium text-rose-600 dark:text-rose-400"
                >
                    {{ form.errors.general }}
                </div>
            </CardContent>

            <CardFooter class="pt-4">
                <Button
                    type="submit"
                    class="w-full cursor-pointer gap-2 bg-lime-400 py-5 text-neutral-950 hover:bg-lime-300 focus-visible:ring-emerald-600"
                    :disabled="
                        form.processing || !form.prompt.trim() || !canAfford
                    "
                >
                    <Loader2
                        v-if="form.processing"
                        aria-hidden="true"
                        class="h-4 w-4 animate-spin motion-reduce:animate-none"
                    />
                    <Sparkles v-else aria-hidden="true" class="h-4 w-4" />
                    <span class="uppercase">
                        Generate
                        <span
                            v-if="estimate"
                            class="font-normal text-neutral-800"
                        >
                            ≈ ${{ Number(estimate.costs.typical).toFixed(4) }}
                        </span>
                    </span>
                </Button>
            </CardFooter>
        </form>
    </Card>
</template>
