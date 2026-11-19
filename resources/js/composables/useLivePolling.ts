import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

const DEFAULT_INTERVAL_MS = 4000;

/**
 * Reloads the given Inertia props on an interval while `isActive` is true.
 */
export function useLivePolling(
    isActive: () => boolean,
    propsToReload: string[],
    intervalMs: number = DEFAULT_INTERVAL_MS,
) {
    let timer: ReturnType<typeof setInterval> | null = null;

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function sync() {
        if (isActive() && !timer) {
            timer = setInterval(() => {
                router.reload({ only: propsToReload});
            }, intervalMs);
        } else if (!isActive()) {
            stop();
        }
    }

    watch(isActive, sync);
    onMounted(sync);
    onUnmounted(stop);
}
