<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowUpRight,
    BarChart3,
    Check,
    Clock,
    Coins,
    Copy,
    Download,
    ExternalLink,
    HelpCircle,
    Image as ImageIcon,
    Info,
    Layers,
    Loader2,
    Plus,
    RefreshCw,
    Sparkles,
    Trash2,
    X,
    ZoomIn,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import BuyCreditsModal from '@/components/BuyCreditsModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';

interface GenerationItem {
    id: string;
    pixelforge_id: string | null;
    prompt: string;
    size: '1024x1024' | '1536x1024' | '1024x1536';
    quality: 'low' | 'medium' | 'high';
    reference_images: string[] | null;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    image_url: string | null;
    image_url_expires_at: string | null;
    usage: {
        input_text_tokens?: number;
        input_image_tokens?: number;
        output_tokens?: number;
        reasoning_tokens?: number;
        total_tokens?: number;
    } | null;
    rates: Record<string, string> | null;
    estimated_provider_cost: string;
    estimated_user_cost: string;
    actual_provider_cost: string;
    actual_user_cost: string;
    error_code: string | null;
    error_message: string | null;
    pixelforge_created_at: string | null;
    pixelforge_started_at: string | null;
    pixelforge_completed_at: string | null;
    created_at: string;
}

interface PaginatedGenerations {
    data: GenerationItem[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface SizeOption {
    value: string;
    label: string;
    aspectRatio: string;
}

interface QualityOption {
    value: string;
    label: string;
}

const props = defineProps<{
    generations: PaginatedGenerations;
    balance: string;
    rates: Record<string, string>;
    hasActiveGenerations: boolean;
    sizes: SizeOption[];
    qualities: QualityOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Generations', href: '/dashboard' },
        ],
    },
});

const showBuyCredits = ref(false);
const previewImage = ref<string | null>(null);
const referenceImageUrl = ref('');
const copiedPromptId = ref<string | null>(null);

// Form
const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Realtime Client-side Cost Estimation
const OUTPUT_TOKENS: Record<string, Record<string, number>> = {
    low: { '1024x1024': 280, '1536x1024': 420, '1024x1536': 420 },
    medium: { '1024x1024': 1100, '1536x1024': 1650, '1024x1536': 1650 },
    high: { '1024x1024': 4300, '1536x1024': 6450, '1024x1536': 6450 },
};

const REASONING_TOKENS: Record<string, { min: number; max: number }> = {
    low: { min: 0, max: 500 },
    medium: { min: 200, max: 2000 },
    high: { min: 1000, max: 8000 },
};

const estimate = computed(() => {
    const textChars = form.prompt.length;
    const baseTextTokens = Math.max(1, Math.ceil(textChars / 4));
    const minTextTokens = Math.max(1, Math.round(baseTextTokens * 0.85));
    const maxTextTokens = Math.max(1, Math.round(baseTextTokens * 1.15));

    const imageTokens = form.reference_images.length * 765;
    const outputTokens = OUTPUT_TOKENS[form.quality]?.[form.size] ?? 1100;
    const reasoning = REASONING_TOKENS[form.quality] ?? { min: 200, max: 2000 };

    const rText = Number(props.rates.input_text_tokens || '5.00') / 1_000_000;
    const rImage = Number(props.rates.input_image_tokens || '10.00') / 1_000_000;
    const rOutput = Number(props.rates.output_tokens || '40.00') / 1_000_000;
    const rReasoning = Number(props.rates.reasoning_tokens || '12.50') / 1_000_000;

    const minProviderCost =
        minTextTokens * rText +
        imageTokens * rImage +
        outputTokens * rOutput +
        reasoning.min * rReasoning;

    const maxProviderCost =
        maxTextTokens * rText +
        imageTokens * rImage +
        outputTokens * rOutput +
        reasoning.max * rReasoning;

    const typicalProviderCost =
        baseTextTokens * rText +
        imageTokens * rImage +
        outputTokens * rOutput +
        ((reasoning.min + reasoning.max) / 2) * rReasoning;

    const minUserCost = minProviderCost * 1.25;
    const maxUserCost = maxProviderCost * 1.25;
    const typicalUserCost = typicalProviderCost * 1.25;

    return {
        baseTextTokens,
        minTextTokens,
        maxTextTokens,
        imageTokens,
        outputTokens,
        reasoningMin: reasoning.min,
        reasoningMax: reasoning.max,
        minProviderCost,
        maxProviderCost,
        typicalProviderCost,
        minUserCost,
        maxUserCost,
        typicalUserCost,
    };
});

const canAfford = computed(() => {
    return Number(props.balance) >= estimate.value.maxUserCost;
});

function addReferenceImage() {
    if (!referenceImageUrl.value) return;
    if (form.reference_images.length >= 4) return;
    try {
        new URL(referenceImageUrl.value);
        form.reference_images.push(referenceImageUrl.value);
        referenceImageUrl.value = '';
    } catch {
        alert('Please enter a valid URL.');
    }
}

function removeReferenceImage(index: number) {
    form.reference_images.splice(index, 1);
}

function appendTestKeyword(keyword: string) {
    if (!form.prompt.includes(keyword)) {
        form.prompt = form.prompt ? `${form.prompt} ${keyword}` : keyword;
    }
}

function submitGeneration() {
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            form.reference_images = [];
        },
    });
}

function copyPrompt(id: string, text: string) {
    navigator.clipboard.writeText(text);
    copiedPromptId.value = id;
    setTimeout(() => {
        copiedPromptId.value = null;
    }, 2000);
}

// Live Polling when there are active generations
let pollTimer: ReturnType<typeof setInterval> | null = null;

function checkAndPoll() {
    if (props.hasActiveGenerations && !pollTimer) {
        pollTimer = setInterval(() => {
            router.reload({
                only: ['generations', 'hasActiveGenerations', 'balance'],
                preserveScroll: true,
            });
        }, 4000);
    } else if (!props.hasActiveGenerations && pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

watch(() => props.hasActiveGenerations, checkAndPoll);

onMounted(() => {
    checkAndPoll();
});

onUnmounted(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
});
</script>

<template>
    <Head title="Pixelforge Studio" />

    <h1 class="sr-only">Pixelforge Studio</h1>

    <div class="flex flex-col gap-6 p-4 md:p-8">
        <!-- Top Balance Bar -->
        <div class="flex flex-col gap-4 rounded-xl border bg-card p-4 sm:flex-row sm:items-center sm:justify-between shadow-xs">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <Coins class="h-6 w-6" />
                </div>
                <div>
                    <div class="text-xs font-medium text-neutral-500">Available Credits</div>
                    <div class="font-mono text-xl font-bold text-neutral-900 dark:text-neutral-100">
                        ${{ Number(balance).toFixed(4) }} USD
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="default" class="gap-2 shadow-xs" @click="showBuyCredits = true">
                    <Coins class="h-4 w-4" />
                    <span>Buy Credits</span>
                </Button>

                <Button variant="outline" as-child class="gap-2">
                    <a href="/reports">
                        <BarChart3 class="h-4 w-4 text-neutral-500" />
                        <span>Operator Reports</span>
                    </a>
                </Button>
            </div>
        </div>

        <!-- Studio Grid: Left Form, Right History -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <!-- Left: Generation Form -->
            <div class="lg:col-span-5">
                <Card class="sticky top-6">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <Sparkles class="h-5 w-5 text-primary" />
                            Generate Image
                        </CardTitle>
                        <CardDescription>
                            Submit prompt to Pixelforge. Cost includes a 25% markup on provider rates.
                        </CardDescription>
                    </CardHeader>

                    <form @submit.prevent="submitGeneration">
                        <CardContent class="space-y-4">
                            <!-- Prompt input -->
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between text-xs">
                                    <Label for="prompt" class="font-medium">Prompt</Label>
                                    <span class="text-neutral-400 font-mono">
                                        {{ form.prompt.length }} / 4,000 chars
                                    </span>
                                </div>
                                <textarea
                                    id="prompt"
                                    v-model="form.prompt"
                                    rows="3"
                                    required
                                    maxlength="4000"
                                    placeholder="A lighthouse on a cliff at dawn, dramatic waves, oil painting..."
                                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                ></textarea>
                                <div v-if="form.errors.prompt" class="text-xs text-rose-500 font-medium">
                                    {{ form.errors.prompt }}
                                </div>
                            </div>

                            <!-- Test Keyword Quick Chips -->
                            <div class="space-y-1">
                                <div class="text-[11px] font-medium text-neutral-400 uppercase tracking-wider">
                                    Quick Test Flags:
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        type="button"
                                        class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 transition"
                                        @click="appendTestKeyword('[succeed]')"
                                    >
                                        + [succeed]
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded bg-rose-50 px-2 py-0.5 text-xs text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-900/60 transition"
                                        @click="appendTestKeyword('[fail]')"
                                    >
                                        + [fail] (Free)
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-700 hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-900/60 transition"
                                        @click="appendTestKeyword('[slow]')"
                                    >
                                        + [slow] (60-90s)
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded bg-purple-50 px-2 py-0.5 text-xs text-purple-700 hover:bg-purple-100 dark:bg-purple-950/40 dark:text-purple-300 dark:hover:bg-purple-900/60 transition"
                                        @click="appendTestKeyword('[duplicate]')"
                                    >
                                        + [duplicate]
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-700 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-900/60 transition"
                                        @click="appendTestKeyword('[blocked]')"
                                    >
                                        + [blocked]
                                    </button>
                                </div>
                            </div>

                            <!-- Size Selector -->
                            <div class="space-y-1.5">
                                <Label class="font-medium text-xs">Image Aspect & Dimensions</Label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button
                                        v-for="s in sizes"
                                        :key="s.value"
                                        type="button"
                                        class="flex flex-col items-center justify-center rounded-lg border p-2 text-center transition"
                                        :class="[
                                            form.size === s.value
                                                ? 'border-primary bg-primary/5 text-primary ring-1 ring-primary'
                                                : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-800',
                                        ]"
                                        @click="form.size = s.value"
                                    >
                                        <span class="text-xs font-semibold">{{ s.aspectRatio }}</span>
                                        <span class="text-[10px] text-neutral-500">{{ s.value }}</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Quality Selector -->
                            <div class="space-y-1.5">
                                <Label class="font-medium text-xs">Quality Tier</Label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button
                                        v-for="q in qualities"
                                        :key="q.value"
                                        type="button"
                                        class="rounded-lg border p-2 text-center text-xs font-medium transition"
                                        :class="[
                                            form.quality === q.value
                                                ? 'border-primary bg-primary/5 text-primary ring-1 ring-primary'
                                                : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-800',
                                        ]"
                                        @click="form.quality = q.value"
                                    >
                                        {{ q.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- Reference Images (optional) -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <Label class="font-medium">Reference Images (Optional)</Label>
                                    <span class="text-[11px] text-neutral-400">
                                        {{ form.reference_images.length }}/4 max (765 tokens ea.)
                                    </span>
                                </div>

                                <div v-if="form.reference_images.length > 0" class="space-y-1.5">
                                    <div
                                        v-for="(img, idx) in form.reference_images"
                                        :key="idx"
                                        class="flex items-center justify-between rounded border bg-neutral-50 px-2 py-1 text-xs dark:bg-neutral-900"
                                    >
                                        <span class="truncate max-w-[240px] text-neutral-600 dark:text-neutral-300 font-mono text-[11px]">
                                            {{ img }}
                                        </span>
                                        <button
                                            type="button"
                                            class="text-neutral-400 hover:text-rose-500"
                                            @click="removeReferenceImage(idx)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>

                                <div v-if="form.reference_images.length < 4" class="flex gap-2">
                                    <Input
                                        v-model="referenceImageUrl"
                                        placeholder="https://example.com/style.png"
                                        class="h-8 text-xs"
                                        type="url"
                                        @keydown.enter.prevent="addReferenceImage"
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="h-8 px-2 text-xs"
                                        @click="addReferenceImage"
                                    >
                                        <Plus class="h-3.5 w-3.5 mr-1" />
                                        Add
                                    </Button>
                                </div>
                            </div>

                            <!-- Live Cost Breakdown Card -->
                            <div class="rounded-xl border border-neutral-200 bg-neutral-50/50 p-3.5 text-xs dark:border-neutral-800 dark:bg-neutral-900/50 space-y-2.5">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-neutral-700 dark:text-neutral-300 flex items-center gap-1.5">
                                        <Coins class="h-4 w-4 text-amber-500" />
                                        Estimated Cost (with 25% markup)
                                    </span>
                                    <Badge variant="outline" class="font-mono text-[11px]">
                                        ~${{ estimate.typicalUserCost.toFixed(5) }}
                                    </Badge>
                                </div>

                                <div class="space-y-1 text-neutral-500 text-[11px] font-mono">
                                    <div class="flex justify-between">
                                        <span>Input text tokens (~{{ estimate.baseTextTokens }}):</span>
                                        <span>${{ (estimate.baseTextTokens * Number(rates.input_text_tokens || '5') / 1_000_000 * 1.25).toFixed(6) }}</span>
                                    </div>
                                    <div v-if="estimate.imageTokens > 0" class="flex justify-between">
                                        <span>Ref image tokens ({{ estimate.imageTokens }}):</span>
                                        <span>${{ (estimate.imageTokens * Number(rates.input_image_tokens || '10') / 1_000_000 * 1.25).toFixed(6) }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>Output tokens ({{ estimate.outputTokens }}):</span>
                                        <span>${{ (estimate.outputTokens * Number(rates.output_tokens || '40') / 1_000_000 * 1.25).toFixed(6) }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>Reasoning tokens ({{ estimate.reasoningMin }}-{{ estimate.reasoningMax }}):</span>
                                        <span>${{ (estimate.reasoningMin * Number(rates.reasoning_tokens || '12.5') / 1_000_000 * 1.25).toFixed(6) }} - ${{ (estimate.reasoningMax * Number(rates.reasoning_tokens || '12.5') / 1_000_000 * 1.25).toFixed(6) }}</span>
                                    </div>
                                </div>

                                <div class="border-t pt-2 flex items-center justify-between font-semibold">
                                    <span class="text-neutral-700 dark:text-neutral-200">Price Range:</span>
                                    <span class="font-mono text-neutral-900 dark:text-neutral-100">
                                        ${{ estimate.minUserCost.toFixed(4) }} – ${{ estimate.maxUserCost.toFixed(4) }}
                                    </span>
                                </div>

                                <div v-if="!canAfford" class="flex items-center justify-between rounded bg-rose-50 p-2 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                                    <div class="flex items-center gap-1.5 text-xs">
                                        <AlertCircle class="h-4 w-4 shrink-0" />
                                        <span>Insufficient credits for max estimate.</span>
                                    </div>
                                    <button
                                        type="button"
                                        class="underline font-semibold text-xs ml-2"
                                        @click="showBuyCredits = true"
                                    >
                                        Buy
                                    </button>
                                </div>
                            </div>

                            <div v-if="form.errors.balance" class="text-xs text-rose-500 font-medium">
                                {{ form.errors.balance }}
                            </div>
                        </CardContent>

                        <CardFooter class="pt-0">
                            <Button
                                type="submit"
                                class="w-full gap-2 py-5"
                                :disabled="form.processing || !form.prompt.trim() || !canAfford"
                            >
                                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                                <Sparkles v-else class="h-4 w-4" />
                                <span>Generate (~${{ estimate.typicalUserCost.toFixed(4) }})</span>
                            </Button>
                        </CardFooter>
                    </form>
                </Card>
            </div>

            <!-- Right: Generations History with Live Status -->
            <div class="lg:col-span-7 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Generation History</h2>
                        <span class="rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ generations.total }} total
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge
                            v-if="hasActiveGenerations"
                            variant="outline"
                            class="animate-pulse gap-1.5 border-blue-500/30 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
                        >
                            <RefreshCw class="h-3 w-3 animate-spin" />
                            Live Polling Active
                        </Badge>

                        <Button
                            size="sm"
                            variant="ghost"
                            class="h-8 px-2 text-xs"
                            @click="router.reload({ preserveScroll: true })"
                        >
                            <RefreshCw class="h-3.5 w-3.5 mr-1" />
                            Refresh
                        </Button>
                    </div>
                </div>

                <!-- Empty state -->
                <Card v-if="generations.data.length === 0" class="p-8 text-center">
                    <ImageIcon class="mx-auto h-12 w-12 text-neutral-300 dark:text-neutral-600" />
                    <h3 class="mt-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">No images generated yet</h3>
                    <p class="mt-1 text-xs text-neutral-500">
                        Write a prompt in the studio on the left to start generating.
                    </p>
                </Card>

                <!-- Generations List -->
                <div class="space-y-3">
                    <Card
                        v-for="gen in generations.data"
                        :key="gen.id"
                        class="overflow-hidden transition-all hover:shadow-xs"
                    >
                        <div class="flex flex-col sm:flex-row">
                            <!-- Image Preview Box -->
                            <div class="relative sm:w-48 sm:min-w-[192px] h-48 bg-neutral-100 dark:bg-neutral-900 flex items-center justify-center overflow-hidden border-b sm:border-b-0 sm:border-r">
                                <!-- Succeeded with Image -->
                                <template v-if="gen.status === 'succeeded' && gen.image_url">
                                    <img
                                        :src="gen.image_url"
                                        :alt="gen.prompt"
                                        class="h-full w-full object-cover transition hover:scale-105 cursor-pointer"
                                        @click="previewImage = gen.image_url"
                                    />
                                    <button
                                        type="button"
                                        class="absolute bottom-2 right-2 rounded-full bg-black/60 p-1.5 text-white backdrop-blur-xs transition hover:bg-black/80"
                                        title="View Full Resolution"
                                        @click="previewImage = gen.image_url"
                                    >
                                        <ZoomIn class="h-3.5 w-3.5" />
                                    </button>
                                </template>

                                <!-- Processing / Queued -->
                                <template v-else-if="gen.status === 'queued' || gen.status === 'processing'">
                                    <div class="flex flex-col items-center gap-2 p-4 text-center">
                                        <Loader2 class="h-7 w-7 animate-spin text-primary" />
                                        <span class="text-xs font-medium text-neutral-600 dark:text-neutral-300">
                                            {{ gen.status === 'queued' ? 'In Pixelforge Queue' : 'Rendering Image...' }}
                                        </span>
                                        <span class="text-[10px] text-neutral-400">Updates live</span>
                                    </div>
                                </template>

                                <!-- Failed -->
                                <template v-else-if="gen.status === 'failed'">
                                    <div class="flex flex-col items-center gap-1.5 p-4 text-center text-rose-500">
                                        <AlertCircle class="h-7 w-7 text-rose-500" />
                                        <span class="text-xs font-semibold">Generation Failed</span>
                                        <span class="text-[10px] text-neutral-400">Charged $0.00</span>
                                    </div>
                                </template>
                            </div>

                            <!-- Details Content -->
                            <div class="flex flex-1 flex-col justify-between p-4 space-y-3">
                                <!-- Header Row -->
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <!-- Status badge -->
                                        <Badge
                                            v-if="gen.status === 'succeeded'"
                                            class="bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 gap-1 border-transparent"
                                        >
                                            <Check class="h-3 w-3" />
                                            Succeeded
                                        </Badge>
                                        <Badge
                                            v-else-if="gen.status === 'processing'"
                                            class="animate-pulse bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300 gap-1 border-transparent"
                                        >
                                            <Loader2 class="h-3 w-3 animate-spin" />
                                            Processing
                                        </Badge>
                                        <Badge
                                            v-else-if="gen.status === 'queued'"
                                            class="bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 gap-1 border-transparent"
                                        >
                                            <Clock class="h-3 w-3" />
                                            Queued
                                        </Badge>
                                        <Badge
                                            v-else-if="gen.status === 'failed'"
                                            class="bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 gap-1 border-transparent"
                                        >
                                            <AlertCircle class="h-3 w-3" />
                                            Failed
                                        </Badge>

                                        <span class="text-xs text-neutral-400">
                                            {{ gen.size }} • {{ gen.quality }}
                                        </span>
                                    </div>

                                    <span class="text-[11px] text-neutral-400 font-mono">
                                        {{ new Date(gen.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                                    </span>
                                </div>

                                <!-- Prompt -->
                                <div class="group relative">
                                    <p class="text-sm text-neutral-800 dark:text-neutral-200 line-clamp-2">
                                        "{{ gen.prompt }}"
                                    </p>
                                    <button
                                        type="button"
                                        class="mt-1 flex items-center gap-1 text-[11px] text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300"
                                        @click="copyPrompt(gen.id, gen.prompt)"
                                    >
                                        <Copy class="h-3 w-3" />
                                        <span>{{ copiedPromptId === gen.id ? 'Copied!' : 'Copy prompt' }}</span>
                                    </button>
                                </div>

                                <!-- Error Alert if failed -->
                                <div
                                    v-if="gen.status === 'failed' && gen.error_message"
                                    class="rounded-md bg-rose-50 p-2 text-xs text-rose-700 dark:bg-rose-950/30 dark:text-rose-300 flex items-start gap-1.5"
                                >
                                    <AlertCircle class="h-3.5 w-3.5 shrink-0 mt-0.5" />
                                    <span>{{ gen.error_message }}</span>
                                </div>

                                <!-- Financial & Token Footer -->
                                <div class="border-t pt-2 flex flex-wrap items-center justify-between gap-2 text-xs">
                                    <div class="flex items-center gap-3">
                                        <div>
                                            <span class="text-neutral-400">Estimate: </span>
                                            <span class="font-mono text-neutral-500">${{ Number(gen.estimated_user_cost).toFixed(4) }}</span>
                                        </div>

                                        <div>
                                            <span class="text-neutral-400">Actual Cost: </span>
                                            <span
                                                class="font-mono font-semibold"
                                                :class="[
                                                    gen.status === 'succeeded'
                                                        ? 'text-neutral-900 dark:text-neutral-100'
                                                        : gen.status === 'failed'
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-neutral-400',
                                                ]"
                                            >
                                                ${{ Number(gen.actual_user_cost).toFixed(6) }}
                                                <span v-if="gen.status === 'failed'" class="text-[10px] font-normal text-emerald-600">
                                                    (Free)
                                                </span>
                                            </span>
                                        </div>
                                    </div>

                                    <div v-if="gen.usage" class="text-[11px] text-neutral-400 font-mono">
                                        {{ gen.usage.total_tokens?.toLocaleString() ?? 0 }} tokens
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Pagination -->
                <div v-if="generations.last_page > 1" class="flex justify-between items-center pt-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!generations.prev_page_url"
                        as-child
                    >
                        <a v-if="generations.prev_page_url" :href="generations.prev_page_url">Previous</a>
                        <span v-else>Previous</span>
                    </Button>
                    <span class="text-xs text-neutral-500">
                        Page {{ generations.current_page }} of {{ generations.last_page }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!generations.next_page_url"
                        as-child
                    >
                        <a v-if="generations.next_page_url" :href="generations.next_page_url">Next</a>
                        <span v-else>Next</span>
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <!-- Buy Credits Modal -->
    <BuyCreditsModal
        v-model:open="showBuyCredits"
        :current-balance="balance"
    />

    <!-- Image Lightbox Modal -->
    <Dialog :open="!!previewImage" @update:open="previewImage = null">
        <DialogContent class="max-w-4xl p-2 sm:p-4">
            <DialogHeader class="sr-only">
                <DialogTitle>Image Preview</DialogTitle>
            </DialogHeader>
            <div class="relative overflow-hidden rounded-lg bg-black flex items-center justify-center max-h-[80vh]">
                <img
                    v-if="previewImage"
                    :src="previewImage"
                    alt="High resolution generated image"
                    class="max-h-[80vh] w-auto object-contain"
                />
            </div>
            <div class="mt-2 flex items-center justify-between px-2">
                <span class="text-xs text-neutral-500">Generated with Pixelforge</span>
                <a
                    v-if="previewImage"
                    :href="previewImage"
                    target="_blank"
                    download
                    class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline font-medium"
                >
                    <Download class="h-3.5 w-3.5" />
                    Download Full Image
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
