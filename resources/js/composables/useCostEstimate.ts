import type { MaybeRefOrGetter } from 'vue';
import { onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue';
import type { CostEstimate } from '@/types';

const DEBOUNCE_MS = 300;

export interface CostEstimateInput {
    prompt: MaybeRefOrGetter<string>;
    size: MaybeRefOrGetter<string>;
    quality: MaybeRefOrGetter<string>;
    referenceImages: MaybeRefOrGetter<string[]>;
}

/** Only trust a response that has every figure the card shows. */
function isCostEstimate(value: unknown): value is CostEstimate {
    const candidate = value as CostEstimate | null;

    return (
        typeof candidate?.tokens?.text === 'number' &&
        typeof candidate?.costs?.typical === 'string' &&
        typeof candidate?.costs?.min === 'string' &&
        typeof candidate?.costs?.max === 'string'
    );
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The estimate for the form's current values, asked of the server (which owns the
 * pricing and the markup) whenever they change, after a short pause in typing.
 * The previous estimate stays visible while a new one loads.
 */
export function useCostEstimate(
    input: CostEstimateInput,
    initialEstimate: CostEstimate | null,
) {
    const estimate = ref<CostEstimate | null>(initialEstimate);
    const isEstimating = ref(false);
    const errorMessage = ref<string | null>(null);

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let latestRequestId = 0;
    let isUnmounted = false;

    async function fetchEstimate() {
        // Several requests can overlap while the user keeps typing. Only the newest one
        // may update the screen, so older responses are ignored when they arrive.
        const requestId = ++latestRequestId;
        isEstimating.value = true;

        try {
            const response = await fetch('/generations/estimate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
                    prompt: toValue(input.prompt),
                    size: toValue(input.size),
                    quality: toValue(input.quality),
                    reference_images: toValue(input.referenceImages),
                }),
            });

            const body = await response.json().catch(() => null);

            if (isUnmounted || requestId !== latestRequestId) {
                return;
            }

            if (response.ok && isCostEstimate(body)) {
                estimate.value = body;
                errorMessage.value = null;

                return;
            }

            console.error(
                'Cost estimate request failed',
                response.status,
                body,
            );

            // Keep showing the last good estimate, unless pricing is unavailable.
            errorMessage.value =
                body?.error ??
                body?.message ??
                'The estimate could not be updated.';

            if (response.status === 503) {
                estimate.value = null;
            }
        } catch {
            if (!isUnmounted && requestId === latestRequestId) {
                errorMessage.value = 'The estimate could not be updated.';
            }
        } finally {
            if (requestId === latestRequestId) {
                isEstimating.value = false;
            }
        }
    }

    function scheduleFetch() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(fetchEstimate, DEBOUNCE_MS);
    }

    watch(
        () => [
            toValue(input.prompt),
            toValue(input.size),
            toValue(input.quality),
            toValue(input.referenceImages).join('\n'),
        ],
        scheduleFetch,
    );

    // The server normally sends an estimate for the starting values; ask for one now if
    // it couldn't (for example, pricing was unavailable when the page loaded).
    onMounted(() => {
        if (estimate.value === null) {
            fetchEstimate().catch(() => {});
        }
    });

    onBeforeUnmount(() => {
        isUnmounted = true;

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
    });

    return { estimate, isEstimating, errorMessage };
}
